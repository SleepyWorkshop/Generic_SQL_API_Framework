<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseDirectory.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Resources/SqlResourceStatement.php';
require_once __DIR__ . '/../app/Resources/RoutineResolver.php';
require_once __DIR__ . '/../app/Resources/DatabaseObjectName.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 11: an adversarial sweep of the database boundary across every
 * request path — SELECT (base, join, subquery, CTE, set operation), SQL
 * Resources, routines, writes, and metadata — plus registry tampering,
 * authorization, and static checks. Nothing connects to SQL Server: every
 * rejection happens in validation or planning, before a connection exists.
 */

function sweepAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function sweepRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-security-sweep-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}

try {
    foreach (['config', 'database/config', 'logs', 'operational'] as $path) mkdir($directory . '/' . $path, 0700, true);
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    // sql01: company (default), inventory, archive (disabled); sql02: legacy.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.sweep.test', 'port' => '14330',
        'authentication' => 'sql', 'username' => 'sweep_user', 'password' => 'sweep-secret', 'options' => ['encrypt' => true, 'loginTimeoutSeconds' => 9]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, [...$sql01, 'server' => '10.11.12.13', 'username' => 'legacy_user', 'password' => 'legacy-secret']);
    foreach ([['company', 'CompanyDB', 'sql01', true], ['inventory', 'InventoryDB', 'sql01', true], ['archive', 'ArchiveDB', 'sql01', false],
        ['legacy', 'Legacy-Archive', 'sql02', true]] as [$id, $physical, $server, $enabled]) {
        $registry->saveDatabase($id, ucfirst($id), $server, $enabled, $physical);
    }
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'archive', 'legacy'] as $id) $availability->setAvailable(true, $id);

    // Server-authored SQL Resources, read by the planner from this map.
    $resources = [
        'reports/plain' => 'SELECT Id FROM dbo.Customer',
        'reports/same-profile' => 'SELECT p.Id FROM {{database:inventory}}.dbo.Product p JOIN {{database:company}}.dbo.Customer c ON c.Id = p.Id',
        'reports/cross-server' => 'SELECT p.Id FROM {{database:inventory}}.dbo.Product p JOIN {{database:legacy}}.dbo.Old o ON o.Id = p.Id',
        'reports/unknown' => 'SELECT Id FROM {{database:unregistered}}.dbo.T',
        'reports/system' => 'SELECT name FROM {{database:master}}.sys.databases',
        'reports/disabled' => 'SELECT Id FROM {{database:archive}}.dbo.T',
        'reports/physical' => 'SELECT Id FROM {{database:inventorydb}}.dbo.Product',
        'reports/decoys' => "SELECT Id, '{{database:legacy}}' AS Note FROM {{database:inventory}}.dbo.Product /* {{database:legacy}} */ -- {{database:legacy}}",
    ];
    $collector = new DatabaseReferenceCollector(static fn (string $resource): string => $resources[$resource] ?? throw new ApiRequestException('Invalid SQL resource.', 'INVALID_SQL_RESOURCE', [], 400));
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver, null, $collector);
    $validator = new QueryRequestValidator();
    $payloads = [];
    // The error code a request ends with after validation and planning ('OK' when planned).
    $outcome = function (array $request) use ($validator, $planner, &$payloads): string {
        try {
            $validator->validate($request);
            $planner->plan($request);
            return 'OK';
        } catch (Throwable $exception) {
            [, $payload] = ExceptionHandler::responseFor($exception);
            $payloads[] = json_encode($payload);
            return $payload['error']['code'];
        }
    };

    // Every request position that can name a database.
    $slots = [
        'select' => fn ($v) => ['action' => 'select', 'database' => $v, 'source' => ['table' => 'Customer'], 'fields' => ['Id']],
        'source' => fn ($v) => ['action' => 'select', 'source' => ['table' => 'Customer', 'database' => $v], 'fields' => ['Id']],
        'join' => fn ($v) => ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'],
            'joins' => [['type' => 'INNER', 'source' => ['table' => 'Product', 'database' => $v], 'on' => ['left' => 'Id', 'right' => 'Id']]]],
        'subquery' => fn ($v) => ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'],
            'filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Product', 'database' => $v], 'fields' => ['Id']]]]],
        'cte' => fn ($v) => ['action' => 'select', 'with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Orders', 'database' => $v], 'fields' => ['Id']]],
            'source' => ['table' => 'Recent'], 'fields' => ['Id']],
        'union' => fn ($v) => ['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']], ['source' => ['table' => 'Product', 'database' => $v], 'fields' => ['Id']]]],
        'union-top' => fn ($v) => ['action' => 'union', 'database' => $v, 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']]]],
        'sql' => fn ($v) => ['action' => 'sql', 'resource' => 'reports/plain', 'database' => $v],
        'metadata.tables' => fn ($v) => ['action' => 'metadata.tables', 'database' => $v],
        'metadata.columns' => fn ($v) => ['action' => 'metadata.columns', 'database' => $v, 'source' => ['table' => 'Customer']],
        'metadata.schema' => fn ($v) => ['action' => 'metadata.schema', 'database' => $v],
        'procedure' => fn ($v) => ['action' => 'procedure', 'database' => $v, 'source' => ['procedure' => 'dbo.Run']],
        'function' => fn ($v) => ['action' => 'function', 'database' => $v, 'source' => ['function' => 'dbo.Score'], 'parameters' => [1]],
        'tableFunction' => fn ($v) => ['action' => 'tableFunction', 'database' => $v, 'source' => ['function' => 'dbo.Rows']],
        'insert' => fn ($v) => ['action' => 'insert', 'database' => $v, 'table' => 'Customer', 'data' => ['Name' => 'A']],
        'update' => fn ($v) => ['action' => 'update', 'database' => $v, 'table' => 'Customer', 'data' => ['Name' => 'A'], 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]],
        'delete' => fn ($v) => ['action' => 'delete', 'database' => $v, 'table' => 'Customer', 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]],
        'upsert' => fn ($v) => ['action' => 'upsert', 'database' => $v, 'table' => 'Customer', 'data' => ['Code' => 'A'], 'keys' => ['Code']],
    ];

    // 1. The registry is the allowlist: unregistered and system databases never resolve.
    foreach (['unregistered', 'master', 'tempdb', 'model', 'msdb'] as $id) {
        foreach ($slots as $slot => $request) {
            sweepAssert($outcome($request($id)) === 'DATABASE_NOT_FOUND', "Unregistered database {$id} was accepted in {$slot}.");
        }
    }
    // 2. Physical names, qualified names, and SQL fragments are never database ids.
    foreach (['InventoryDB', '[InventoryDB]', 'InventoryDB.dbo.Product', 'server.InventoryDB', 'sql01.sweep.test', 'InventoryDB; DROP TABLE x',
        'inventory; SELECT 1', 'inventory]', "inventory'--", 'inventory/*x*/', 'INVENTORY', ' inventory', '', null, 7, true, ['inventory'],
        ['id' => 'inventory'], 'Server=sql01;Database=InventoryDB'] as $value) {
        foreach ($slots as $slot => $request) {
            sweepAssert($outcome($request($value)) === 'INVALID_REQUEST', 'Database value ' . json_encode($value) . " was accepted in {$slot}.");
        }
    }
    foreach ($slots as $slot => $request) {
        sweepAssert($outcome($request('inventorydb')) === 'DATABASE_NOT_FOUND' && $outcome($request('archive')) === 'DATABASE_DISABLED',
            "A physical-looking or disabled database was accepted in {$slot}.");
    }
    // Registered databases plan where the request path allows them.
    foreach (['select', 'source', 'join', 'subquery', 'cte', 'union', 'union-top', 'sql', 'metadata.tables', 'procedure', 'insert', 'delete'] as $slot) {
        sweepAssert($outcome($slots[$slot]('inventory')) === 'OK', "A registered database was rejected in {$slot}.");
    }

    // 3, 4, 6. Multi-part names, linked-server forms, and SQL fragments in every identifier position.
    $hostile = ['InventoryDB.dbo.Product', '[InventoryDB].[dbo].[Product]', 'Server01.InventoryDB.dbo.Product', 'Server01...Product', 'Db..Table',
        'Db.Schema.', '.Schema.Table', '........', 'Product; DROP TABLE Product', 'Product] DROP TABLE Product--', 'Product/*comment*/',
        "Product' OR '1'='1", 'Product"; DROP TABLE Product--', 'OPENQUERY(srv, \'SELECT 1\')', 'Product)', ''];
    $tablePositions = [
        'select' => fn ($t) => ['action' => 'select', 'source' => ['table' => $t], 'fields' => ['Id']],
        'join' => fn ($t) => ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'], 'joins' => [['type' => 'LEFT', 'source' => ['table' => $t], 'on' => ['left' => 'Id', 'right' => 'Id']]]],
        'subquery' => fn ($t) => ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'], 'filters' => [['operator' => 'EXISTS', 'query' => ['source' => ['table' => $t], 'fields' => ['Id']]]]],
        'cte' => fn ($t) => ['action' => 'select', 'with' => ['name' => 'R', 'query' => ['source' => ['table' => $t], 'fields' => ['Id']]], 'source' => ['table' => 'R'], 'fields' => ['Id']],
        'union' => fn ($t) => ['action' => 'union', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']], ['source' => ['table' => $t], 'fields' => ['Id']]]],
        'insert' => fn ($t) => ['action' => 'insert', 'table' => $t, 'data' => ['Name' => 'A']],
        'delete' => fn ($t) => ['action' => 'delete', 'table' => $t, 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]],
        'metadata.columns' => fn ($t) => ['action' => 'metadata.columns', 'source' => ['table' => $t]],
    ];
    foreach ($hostile as $name) {
        foreach ($tablePositions as $position => $request) {
            sweepAssert($outcome($request($name)) === 'INVALID_REQUEST', 'Table ' . json_encode($name) . " was accepted in {$position}.");
        }
        $routineOutcome = $outcome(['action' => 'procedure', 'source' => ['procedure' => $name]]);
        if ($routineOutcome === 'OK') {
            $routineOutcome = 'accepted';
            try {
                RoutineResolver::name($name, 'procedure');
            } catch (ApiRequestException $exception) {
                $routineOutcome = $exception->getErrorCode();
            }
        }
        sweepAssert(in_array($routineOutcome, ['INVALID_REQUEST', 'INVALID_ROUTINE'], true), 'Routine ' . json_encode($name) . ' was accepted.');
        sweepAssert($outcome(['action' => 'sql', 'resource' => $name]) === 'INVALID_REQUEST', 'Resource id ' . json_encode($name) . ' was accepted.');
    }
    // Two-part write and metadata names stay structured: schema and object, in the selected database only.
    sweepAssert(DatabaseObjectName::parse('dbo.Product', 'table', 'INVALID_WRITE_TABLE', 'x') === ['schema' => 'dbo', 'name' => 'Product']
        && DatabaseObjectName::qualify($resolver->resolve('inventory'), 'sales', 'Product')->render() === '[InventoryDB].[sales].[Product]',
        'A two-part name was not kept structured.');
    foreach (['sys.objects', 'INFORMATION_SCHEMA.TABLES', 'Sys.Tables'] as $system) {
        sweepAssert(sweepFailureCode(fn () => DatabaseObjectName::parse($system, 'table', 'INVALID_WRITE_TABLE', 'x')) === 'INVALID_WRITE_TABLE', "System object {$system} was writable.");
    }
    // Schemas, columns, aliases, and sort/group fields.
    foreach (['dbo.Product', 'sys', 'INFORMATION_SCHEMA', 'dbo; DROP', 'dbo]', 'dbo/**/', "dbo'", ''] as $schema) {
        sweepAssert($outcome(['action' => 'select', 'source' => ['table' => 'Customer', 'schema' => $schema], 'fields' => ['Id']]) === 'INVALID_REQUEST'
            && $outcome(['action' => 'metadata.columns', 'source' => ['table' => 'Customer', 'schema' => $schema]]) === 'INVALID_REQUEST',
            'Schema ' . json_encode($schema) . ' was accepted.');
    }
    foreach (['Id; DROP TABLE x', "Id' OR '1'='1", 'Id--', 'Id/**/', '[Id]', 'Id)', 'Id,Name', ''] as $column) {
        foreach ([
            ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => [$column]],
            ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'], 'sort' => [['field' => $column]]],
            ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'], 'groupBy' => [$column]],
            ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'], 'filters' => [['field' => $column, 'operator' => '=', 'value' => 1]]],
            ['action' => 'select', 'source' => ['table' => 'Customer', 'alias' => $column], 'fields' => ['Id']],
            ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => [['field' => 'Id', 'alias' => $column]]],
            ['action' => 'insert', 'table' => 'Customer', 'data' => [$column => 1]],
            ['action' => 'upsert', 'table' => 'Customer', 'data' => ['Code' => 1], 'keys' => [$column]],
        ] as $request) {
            sweepAssert($outcome($request) === 'INVALID_REQUEST', 'Identifier ' . json_encode($column) . ' was accepted: ' . json_encode($request));
        }
    }

    // 5, 7. SQL Resources: placeholders are registry-controlled; literal addressing and remote rowsets are rejected.
    sweepAssert($outcome(['action' => 'sql', 'resource' => 'reports/same-profile']) === 'OK'
        && $planner->plan(['action' => 'sql', 'resource' => 'reports/same-profile'])->databaseIds() === ['inventory', 'company'], 'Same-profile placeholders were not planned.');
    foreach (['reports/unknown' => 'DATABASE_NOT_FOUND', 'reports/system' => 'DATABASE_NOT_FOUND', 'reports/physical' => 'DATABASE_NOT_FOUND',
        'reports/disabled' => 'DATABASE_DISABLED', 'reports/cross-server' => 'CROSS_SERVER_QUERY_NOT_SUPPORTED'] as $resource => $expected) {
        sweepAssert($outcome(['action' => 'sql', 'resource' => $resource]) === $expected, "Resource {$resource} was not rejected with {$expected}.");
    }
    sweepAssert($outcome(['action' => 'sql', 'resource' => 'reports/same-profile', 'database' => 'inventory']) === 'INVALID_REQUEST',
        'A request database redirected a resource with placeholders.');
    sweepAssert(DatabaseReferenceCollector::resourceDatabaseIds($resources['reports/decoys']) === ['inventory'],
        'A placeholder in a literal or comment was collected.');
    foreach ([
        'SELECT Id FROM InventoryDB.dbo.Product', 'SELECT Id FROM [InventoryDB].[dbo].[Product]', 'SELECT Id FROM InventoryDB..Product',
        'SELECT Id FROM Server01.InventoryDB.dbo.Product', 'SELECT Id FROM Server01...Product', 'SELECT Id FROM "InventoryDB"."dbo"."Product"',
        'SELECT Id FROM dbo.T WHERE Id IN (SELECT Id FROM (SELECT Id FROM InventoryDB.dbo.Product) x)', 'SELECT dbo.Customer.Id FROM dbo.Customer',
        "SELECT * FROM OPENQUERY(srv, 'SELECT 1')", "SELECT * FROM openrowset('SQLNCLI', 'x', 'SELECT 1') r", "SELECT * FROM OpenDataSource('SQLNCLI', 'x').Db.dbo.T",
        'SELECT x FROM dbo.T CROSS APPLY (SELECT * FROM OPENQUERY(srv, \'q\')) q',
        'SELECT Id FROM {{database:InventoryDB}}.dbo.Product', 'SELECT Id FROM {{ database:inventory }}.dbo.Product', 'SELECT Id FROM {{database:}}.dbo.Product',
        'SELECT Id FROM {{database:inventory}.dbo.Product', 'SELECT Id FROM {{database:inventory', 'SELECT Id FROM {{server:sql01}}.dbo.Product',
        'SELECT Id FROM dbo.{{database:inventory}}.Product', 'SELECT Id FROM dbo.Product.{{database:inventory}}', 'SELECT Id FROM {{database:inventory}}.Product',
        'SELECT Id FROM {{database:inventory}}.dbo.Product.Id', 'SELECT {{database:inventory}} AS Id FROM dbo.T', 'SELECT Id FROM {{database:inventory}}{{database:company}}.dbo.T',
        'SELECT Id FROM {{database:inventory}}.dbo.Product; DROP TABLE dbo.Product',
    ] as $sql) {
        sweepAssert(sweepFailureCode(fn () => SqlResourceStatement::analyze($sql)) !== null, "Resource SQL was accepted: {$sql}");
    }
    foreach (['SELECT p.Id FROM {{database:inventory}}.dbo.Product AS p', 'SELECT Id FROM {{database:inventory}}..Product', 'SELECT [p].[Id] FROM [dbo].[Product] [p]',
        "SELECT 'Db.dbo.T' AS Literal FROM dbo.T -- Db.dbo.T", "SELECT JSON_VALUE(Data, '$.a.b.c') AS V FROM dbo.Docs"] as $sql) {
        SqlResourceStatement::analyze($sql);
    }

    // 8. Routines: one target database; names never reach another database or system routines.
    foreach (['sys.sp_who', 'sp_executesql', 'xp_cmdshell', 'dbo.sp_helptext', 'dbo.XP_Read', 'master.dbo.sp_who', 'msdb.dbo.sp_start_job', 'srv.db.dbo.Proc'] as $routine) {
        sweepAssert(sweepFailureCode(fn () => RoutineResolver::name($routine, 'procedure')) === 'INVALID_ROUTINE', "Routine {$routine} was callable.");
    }
    foreach ([['source' => ['procedure' => 'dbo.Run', 'database' => 'inventory']], ['source' => ['procedure' => 'dbo.Run', 'server' => 'sql02']],
        ['databases' => ['inventory', 'legacy']], ['database' => 'inventory', 'targets' => ['legacy']]] as $extra) {
        sweepAssert($outcome(array_replace(['action' => 'procedure', 'source' => ['procedure' => 'dbo.Run']], $extra)) === 'INVALID_REQUEST',
            'A routine named a second database: ' . json_encode($extra));
    }
    sweepAssert($planner->plan(['action' => 'procedure', 'database' => 'legacy', 'source' => ['procedure' => 'dbo.Run']])->databaseIds() === ['legacy'],
        'A routine plan named more than its database.');

    // 9. Writes: exactly one target database.
    foreach ([['database' => ['inventory', 'company']], ['targets' => [['database' => 'inventory']]], ['source' => ['database' => 'legacy']],
        ['joins' => []], ['from' => 'legacy'], ['Database' => 'legacy'], ['table' => ['Customer']], ['table' => ['schema' => 'dbo', 'name' => 'Customer']]] as $extra) {
        foreach (['insert', 'update', 'delete', 'upsert'] as $action) {
            sweepAssert($outcome(array_replace($slots[$action]('inventory'), $extra)) === 'INVALID_REQUEST', "A {$action} accepted " . json_encode($extra));
        }
    }

    // 21. Cross-server: every reading path is refused; writes and routines cannot express it.
    foreach (['source' => $slots['join']('legacy'), 'subquery' => $slots['subquery']('legacy'), 'cte' => array_replace_recursive($slots['cte']('legacy'),
        ['source' => ['table' => 'Recent'], 'joins' => [['type' => 'INNER', 'source' => ['table' => 'Customer'], 'on' => ['left' => 'Id', 'right' => 'Id']]]]),
        'union' => $slots['union']('legacy'), 'resource' => ['action' => 'sql', 'resource' => 'reports/cross-server']] as $path => $request) {
        sweepAssert($outcome($request) === 'CROSS_SERVER_QUERY_NOT_SUPPORTED', "Cross-server {$path} was not rejected.");
    }
    sweepAssert($outcome(['action' => 'select', 'database' => 'legacy', 'source' => ['table' => 'Customer'], 'fields' => ['Id'],
        'joins' => [['type' => 'INNER', 'source' => ['table' => 'P', 'database' => 'inventory'], 'on' => ['left' => 'Id', 'right' => 'Id']]]]) === 'CROSS_SERVER_QUERY_NOT_SUPPORTED',
        'A cross-server join from a non-default primary was not rejected.');

    // 11. metadata.databases exposes no infrastructure; disabled databases stay listed.
    $listing = json_encode((new DatabaseDirectory($registry, $availability))->databases());
    foreach (['CompanyDB', 'InventoryDB', 'ArchiveDB', 'Legacy-Archive', 'sql01.sweep.test', '10.11.12.13', '14330', 'sweep_user', 'legacy_user',
        'sweep-secret', 'legacy-secret', 'loginTimeout', 'encrypt', 'ODBC', $key] as $secret) {
        sweepAssert(!str_contains($listing, $secret), "metadata.databases exposed {$secret}.");
    }
    sweepAssert(str_contains($listing, '"id":"archive"') && str_contains($listing, '"enabled":false'), 'A disabled database was hidden from metadata.databases.');

    // 14, 15. Registry tampering and key handling fail closed, without secrets.
    $stored = json_decode((string)file_get_contents($registry->registryPath()), true);
    $tamper = [
        'swapped server envelopes' => function (array $d): array { [$d['servers']['sql01']['connection'], $d['servers']['sql02']['connection']] = [$d['servers']['sql02']['connection'], $d['servers']['sql01']['connection']]; return $d; },
        'swapped catalogs' => function (array $d): array { [$d['databases']['company']['catalog'], $d['databases']['inventory']['catalog']] = [$d['databases']['inventory']['catalog'], $d['databases']['company']['catalog']]; return $d; },
        'renamed server' => function (array $d): array { $d['servers']['sql09'] = $d['servers']['sql01']; unset($d['servers']['sql01']);
            foreach ($d['databases'] as &$database) if ($database['server'] === 'sql01') $database['server'] = 'sql09'; return $d; },
        'altered ciphertext' => function (array $d): array { $envelope = &$d['servers']['sql01']['connection']; foreach ($envelope as $field => $value) {
            if (is_string($value) && strlen($value) > 20) { $envelope[$field] = substr($value, 0, -4) . (str_ends_with($value, 'AAAA') ? 'BBBB' : 'AAAA'); break; } } return $d; },
        'plaintext connection' => function (array $d): array { $d['servers']['sql01']['connection'] = ['server' => 'evil.example', 'password' => 'x']; return $d; },
        'plaintext catalog' => function (array $d): array { $d['databases']['company']['catalog'] = ['database' => 'master']; return $d; },
        'unknown envelope field' => function (array $d): array { $d['servers']['sql01']['connection']['note'] = 'x'; return $d; },
        'unknown database field' => function (array $d): array { $d['databases']['company']['physical'] = 'master'; return $d; },
        'version' => function (array $d): array { $d['version'] = 99; return $d; },
        'missing servers' => function (array $d): array { unset($d['servers']); return $d; },
        'dangling server' => function (array $d): array { $d['databases']['company']['server'] = 'nowhere'; return $d; },
        'dangling default' => function (array $d): array { $d['defaultDatabase'] = 'nowhere'; return $d; },
        'disabled default' => function (array $d): array { $d['databases']['company']['enabled'] = false; return $d; },
    ];
    foreach ($tamper as $case => $mutate) {
        file_put_contents($registry->registryPath(), json_encode($mutate($stored)));
        $tampered = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
        $failure = sweepFailure(function () use ($tampered): void {
            $tampered->verify();
        });
        $resolution = sweepFailure(fn () => (new DatabaseContextResolver($tampered, $availability))->resolve('company'));
        sweepAssert($failure instanceof DatabaseCredentialException && $resolution !== null, "Registry tampering ({$case}) was accepted.");
        [, $payload] = ExceptionHandler::responseFor($resolution);
        $payloads[] = json_encode($payload);
    }
    file_put_contents($registry->registryPath(), '{"version":');
    sweepAssert(sweepFailure(fn () => DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json')->metadata()) instanceof DatabaseCredentialException,
        'A corrupt registry was read.');
    file_put_contents($registry->registryPath(), json_encode($stored));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    $wrongKey = sweepFailure(fn () => (new DatabaseContextResolver(DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json'), $availability))->resolve('company'));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    $noKey = sweepFailure(fn () => (new DatabaseContextResolver(DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json'), $availability))->resolve('company'));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    foreach ([$wrongKey, $noKey] as $failure) {
        [$status, $payload] = ExceptionHandler::responseFor($failure);
        sweepAssert($status === 503 && $payload['error']['code'] === 'DATABASE_CONFIGURATION_ERROR', 'A key failure was not reported safely.');
        $payloads[] = json_encode($payload);
    }
    sweepAssert((new DatabaseContextResolver(DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json'), $availability))->resolve('company')
        ->physicalName() === 'CompanyDB', 'The restored registry no longer resolves.');

    // Admin-entered catalogs must be names the identifier rules can render later.
    $adminValidator = new AdminRequestValidator();
    foreach (['A--B', 'A/*B', 'A*/B', 'A;B', "A\nB"] as $catalog) {
        sweepAssert(sweepFailureCode(fn () => $adminValidator->validate(['action' => 'admin.databases.save',
            'database' => ['id' => 'x', 'name' => 'X', 'server' => 'sql01', 'enabled' => true, 'catalog' => $catalog]])) === 'INVALID_ADMIN_REQUEST',
            'Admin accepted catalog ' . json_encode($catalog) . '.');
    }
    $adminValidator->validate(['action' => 'admin.databases.save', 'database' => ['id' => 'x', 'name' => 'X', 'server' => 'sql01', 'enabled' => true, 'catalog' => 'Sales.2024]Q']]);

    // 20. Selecting a database never bypasses permission authorization.
    $readOnly = new Principal('u1', 'reader', 'session', RoleModel::READ_ONLY, false, null, true);
    PrincipalContext::set($readOnly);
    foreach (['insert', 'update', 'delete', 'upsert', 'procedure'] as $action) {
        $failure = sweepFailure(fn () => (new AuthorizationMiddleware())->handle($slots[$action]('inventory')));
        sweepAssert($failure instanceof ApiRequestException && $failure->getStatusCode() === 403, "A read-only principal was authorized for {$action} by naming a database.");
    }
    (new AuthorizationMiddleware())->handle($slots['select']('inventory'));
    PrincipalContext::clear();
    $anonymous = sweepFailure(fn () => (new AuthorizationMiddleware())->handle($slots['select']('inventory')));
    sweepAssert($anonymous instanceof ApiRequestException && $anonymous->getStatusCode() === 401, 'An anonymous request was authorized.');

    // 24. No error payload in this sweep carries physical infrastructure or secrets.
    $errors = implode("\n", $payloads);
    sweepAssert(count($payloads) > 500, 'The sweep did not exercise the rejection paths.');
    foreach (['CompanyDB', 'InventoryDB', 'ArchiveDB', 'Legacy-Archive', 'sql01.sweep.test', '10.11.12.13', '14330', 'sweep_user', 'legacy_user',
        'sweep-secret', 'legacy-secret', 'ODBC', 'Driver=', $key] as $secret) {
        sweepAssert(!str_contains($errors, $secret), "An error exposed {$secret}.");
    }

    // 16, 26, 27, 28. Static boundaries: no persistent connections or pools, no process control in database
    // and Admin database code, no eval, and database names reach SQL only through MssqlIdentifier.
    $root = dirname(__DIR__);
    $sources = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') $sources[substr($file->getPathname(), strlen($root) + 1)] = (string)file_get_contents($file->getPathname());
    }
    foreach (['core', 'database/drivers', 'database/factory'] as $folder) {
        foreach (glob($root . '/' . $folder . '/*.php') ?: [] as $file) $sources[substr($file, strlen($root) + 1)] = (string)file_get_contents($file);
    }
    foreach ($sources as $path => $source) {
        sweepAssert(!preg_match('/odbc_pconnect|\beval\s*\(|create_function/', $source), "{$path} uses persistent connections or eval.");
    }
    foreach (['app/Database/DatabaseConnectionManager.php', 'core/QueryEngine.php', 'core/Database.php', 'app/Services/DatabaseAdministrationService.php',
        'app/Health/ApplicationHealthMonitor.php', 'app/Database/DatabaseDirectory.php'] as $path) {
        sweepAssert(!preg_match('/static\s+(?:\?\w+\s+|array\s+)?\$(?:connection|connections|db|database|pool)\b|proc_open|shell_exec|passthru|popen|\bexec\(|\bsystem\(|iisreset|appcmd|net\s+stop|sc\s+stop/i', $sources[$path]),
            "{$path} keeps shared connections or controls processes.");
    }
    foreach (['app/Database/QualifiedObject.php', 'app/Repositories/SqlRepository.php', 'app/Repositories/MetadataRepository.php'] as $path) {
        sweepAssert(!preg_match('/[\'"]\[[\'"]\s*\.\s*\$[a-z]*(?:database|catalog|physical)/i', $sources[$path]), "{$path} builds database identifiers by concatenation.");
    }

    echo "Security isolation regression tests passed.\n";
} finally {
    PrincipalContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    sweepRemoveDirectory($directory);
}

function sweepFailure(callable $operation): ?Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    return null;
}

function sweepFailureCode(callable $operation): ?string
{
    $failure = sweepFailure($operation);
    if ($failure === null) return null;
    return $failure instanceof ApiRequestException ? $failure->getErrorCode() : get_class($failure);
}
