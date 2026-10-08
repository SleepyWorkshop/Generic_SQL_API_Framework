<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../app/Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/SqlRepository.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../app/Resources/SqlResourceRegistry.php';
require_once __DIR__ . '/../app/Resources/SqlResourceStatement.php';
require_once __DIR__ . '/../app/Resources/RoutineResolver.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 8: database-aware SQL Resources and routines.
 *
 * Unit, planner, and SQL-generation tests. The end-to-end cases run the real
 * planner, repositories, QueryEngine, and DatabaseConnectionManager with the
 * ODBC calls recorded; catalog lookups are answered by an in-memory model of
 * each database. SQL Server itself is not involved.
 */

function resAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function resFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code, first detail path, encoded payload] of the API response an exception produces. */
function resResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, $payload['error']['details'][0]['path'] ?? null, json_encode($payload)];
}

function resRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** Routines per physical database: schema → name → [ROUTINE_TYPE, DATA_TYPE, parameter count]. */
const RES_ROUTINES = [
    'InventoryDB' => ['dbo' => ['GetProducts' => ['PROCEDURE', null, 1], 'StockLevel' => ['FUNCTION', 'int', 2]]],
    'Legacy-Archive' => ['dbo' => ['ArchiveRows' => ['FUNCTION', 'TABLE', 1]]],
    'CompanyDB' => ['dbo' => ['RunReport' => ['PROCEDURE', null, 0]]],
];

function resRows(string $sql, array $params, string $connected): array
{
    if (str_contains($sql, 'compatibility_level')) return [['CompatibilityLevel' => 150]];
    if (str_contains($sql, 'COUNT(*) AS TotalRows')) return [['TotalRows' => 3]];
    if (str_contains($sql, 'INFORMATION_SCHEMA.ROUTINES')) {
        $routine = RES_ROUTINES[$connected][$params[0]][$params[1]] ?? null;
        return $routine === null ? [] : [['RoutineType' => $routine[0], 'DataType' => $routine[1], 'ParameterCount' => $routine[2]]];
    }
    return [['ProductID' => 1, 'Name' => 'Row']];
}

/** Records connection attempts; the handle names the catalog it opened. */
final class ResSqlServerDriver extends SqlServerDriver
{
    public static array $attempts = [];

    protected function openConnection(string $dsn, string $authentication, string $username, string $password)
    {
        self::$attempts[] = $dsn;
        preg_match('/Database=([^;]+);/', $dsn, $match);
        return 'catalog:' . $match[1];
    }

    public function disconnect() {}
}

final class ResDatabase extends Database
{
    private ResSqlServerDriver $resDriver;

    public function __construct(array $configuration)
    {
        $this->resDriver = new ResSqlServerDriver($configuration, false);
        $this->resDriver->connect();
    }

    public function getConnection() { return $this->resDriver->getConnection(); }
    public function close() {}
}

/** The real QueryEngine with its ODBC statement calls recorded. */
final class ResRecordingEngine extends QueryEngine
{
    public array $statements = [];

    protected function prepareStatement(string $sql)
    {
        $this->statements[] = ['connection' => $this->connection, 'sql' => trim((string)preg_replace('/\s+/', ' ', $sql)), 'params' => []];
        return new ArrayObject(['sql' => $sql, 'rows' => []]);
    }
    protected function executeStatement($statement, array $params): bool
    {
        $this->statements[count($this->statements) - 1]['params'] = $params;
        $statement['rows'] = resRows($statement['sql'], $params, substr((string)$this->connection, strlen('catalog:')));
        return true;
    }
    protected function fetchRow($statement)
    {
        $rows = $statement['rows'];
        $row = array_shift($rows);
        $statement['rows'] = $rows;
        return $row ?? false;
    }
    protected function nextResult($statement): bool { return false; }
    protected function freeStatement($statement): void {}
    protected function lastError(): string { return ''; }
    protected function lastSqlState(): string { return ''; }

    public function dataSql(): array
    {
        return array_values(array_filter(array_column($this->statements, 'sql'),
            fn (string $sql): bool => !str_contains($sql, 'INFORMATION_SCHEMA') && !str_contains($sql, 'compatibility_level')
                && !str_contains($sql, 'EngineEdition')));
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-resource-routine-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$secrets = ['res-secret', 'legacy-secret', 'res_user', 'legacy_user', 'sql01.res.test', '10.9.8.7', '14330', 'ConnectTimeout'];

try {
    foreach (['config', 'database/config', 'logs', 'operational', 'resources/reports'] as $path) mkdir($directory . '/' . $path, 0700, true);
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    // sql01: company (default), inventory, sales, archive (disabled), reporting (gate closed);
    // sql02: legacy; sql03 (disabled profile): offline.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.res.test', 'port' => '14330',
        'authentication' => 'sql', 'username' => 'res_user', 'password' => 'res-secret', 'options' => ['encrypt' => true]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, [...$sql01, 'server' => '10.9.8.7', 'port' => '1433', 'username' => 'legacy_user', 'password' => 'legacy-secret']);
    $registry->saveServer('sql03', 'SQL Server 03', false, $sql01);
    foreach ([['company', 'CompanyDB', 'sql01', true], ['inventory', 'InventoryDB', 'sql01', true], ['sales', 'Sales.2024]Q', 'sql01', true],
        ['archive', 'ArchiveDB', 'sql01', false], ['reporting', 'ReportingDB', 'sql01', true], ['legacy', 'Legacy-Archive', 'sql02', true],
        ['offline', 'OfflineDB', 'sql03', true]] as [$id, $physical, $server, $enabled]) {
        $registry->saveDatabase($id, ucfirst($id), $server, $enabled, $physical);
    }
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'sales', 'archive', 'legacy'] as $id) $availability->setAvailable(true, $id);

    // Trusted, server-side SQL Resources.
    $resources = [
        'plain' => 'SELECT Id, Name FROM dbo.Customer',
        'inventory' => 'SELECT ProductID, Name FROM {{database:inventory}}.dbo.Product',
        'cross' => "SELECT p.ProductID, c.Name\nFROM {{database:inventory}}.dbo.Product p\nJOIN {{database:company}}.dbo.Customer c ON c.Id = p.CustomerId",
        'quoted' => "SELECT Id, Note FROM {{database:sales}}..Orders WHERE Note <> '{{database:legacy}}' -- {{database:legacy}}",
        'unknown' => 'SELECT Id FROM {{database:missing}}.dbo.T',
        'physical' => 'SELECT Id FROM {{database:inventorydb}}.dbo.Product',
        'disabled' => 'SELECT Id FROM {{database:archive}}.dbo.T',
        'crossserver' => 'SELECT p.Id FROM {{database:inventory}}.dbo.Product p JOIN {{database:legacy}}.dbo.Old o ON o.Id = p.Id',
    ];
    foreach ($resources as $name => $sql) file_put_contents($directory . "/resources/reports/{$name}.sql", $sql);
    $settings = ['root' => $directory . '/resources', 'exclude' => []];
    $readResource = static fn (string $resource): string => (string)file_get_contents((new SqlResourceDiscovery($settings))->resolve($resource)['file']);
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver, null, new DatabaseReferenceCollector($readResource));
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $logger = new Logger($directory . '/logs');
    $payloads = [];
    $connections = fn (): DatabaseConnectionManager => new DatabaseConnectionManager(static fn (array $configuration): Database => new ResDatabase($configuration));

    // Run a SQL Resource request through validation, planning, one connection, and SqlRepository.
    $runResource = function (array $request) use ($validator, $normalizer, $planner, $logger, $connections, $settings): array {
        $validator->validate($request);
        DatabaseQueryPlanContext::set($planner->plan($request));
        ResSqlServerDriver::$attempts = [];
        try {
            $engine = new ResRecordingEngine(null, $logger, 0, true, $connections());
            $result = (new SqlRepository($engine, new SqlResourceRegistry($settings), $logger))->execute($normalizer->normalize($request));
        } finally {
            DatabaseQueryPlanContext::clear();
        }
        return [$result, $engine, ResSqlServerDriver::$attempts];
    };
    $rejected = function (array $request, int $status, string $code, ?string $path = null) use ($validator, $planner, &$payloads): void {
        ResSqlServerDriver::$attempts = [];
        [$actualStatus, $actualCode, $actualPath, $payload] = resResponse(resFailure(function () use ($validator, $planner, $request): void {
            $validator->validate($request);
            $planner->plan($request);
        }, 'Request was accepted: ' . json_encode($request)));
        resAssert($actualStatus === $status && $actualCode === $code && ($path === null || $actualPath === $path),
            "Expected {$status} {$code} at {$path} for " . json_encode($request) . "; got {$actualStatus} {$actualCode} at {$actualPath}.");
        resAssert(ResSqlServerDriver::$attempts === [], 'A rejected request connected.');
        $payloads[] = $payload;
    };

    // 1. No placeholder: the default database, SQL unchanged.
    [, $engine, $attempts] = $runResource(['action' => 'sql', 'resource' => 'reports/plain']);
    resAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=CompanyDB;') && $engine->dataSql() === ['SELECT * FROM ( SELECT Id, Name FROM dbo.Customer ) AS SqlResource'],
        'A resource without placeholders did not run unchanged on the default database.');
    // A request may point a placeholder-free resource at a registered database.
    [, , $attempts] = $runResource(['action' => 'sql', 'resource' => 'reports/plain', 'database' => 'inventory']);
    resAssert(str_contains($attempts[0], 'Database=InventoryDB;'), 'A request database was not used for a resource without placeholders.');

    // 2, 12, 13. A placeholder resolves through the registry and renders as a delimited identifier.
    [, $engine, $attempts] = $runResource(['action' => 'sql', 'resource' => 'reports/inventory']);
    resAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=InventoryDB;')
        && $engine->dataSql() === ['SELECT * FROM ( SELECT ProductID, Name FROM [InventoryDB].dbo.Product ) AS SqlResource'], 'A placeholder did not resolve to its registered database.');
    [, $engine] = $runResource(['action' => 'sql', 'resource' => 'reports/quoted']);
    resAssert($engine->dataSql() === ["SELECT * FROM ( SELECT Id, Note FROM [Sales.2024]]Q]..Orders WHERE Note <> '{{database:legacy}}' -- {{database:legacy}} ) AS SqlResource"],
        'A physical name was not quoted, or a placeholder in a literal or comment was rendered.');
    resAssert(DatabaseReferenceCollector::resourceDatabaseIds($resources['quoted']) === ['sales'], 'A placeholder in a literal or comment was collected.');

    // 3-5. Unknown, disabled, and physical names.
    $rejected(['action' => 'sql', 'resource' => 'reports/unknown'], 404, 'DATABASE_NOT_FOUND', 'resource');
    $rejected(['action' => 'sql', 'resource' => 'reports/disabled'], 403, 'DATABASE_DISABLED', 'resource');
    $rejected(['action' => 'sql', 'resource' => 'reports/physical'], 404, 'DATABASE_NOT_FOUND', 'resource');
    $rejected(['action' => 'sql', 'resource' => 'reports/plain', 'database' => 'InventoryDB'], 400, 'INVALID_REQUEST', 'database');
    foreach (['{{database:InventoryDB}}.dbo.Product', '{{database: inventory}}.dbo.Product', '{{db:inventory}}.dbo.Product', '{{database:inventory.dbo.Product'] as $source) {
        resAssert(resFailure(fn () => SqlResourceStatement::analyze("SELECT Id FROM {$source}"), "Placeholder {$source} was accepted.") instanceof RuntimeException,
            'A malformed placeholder was accepted.');
    }

    // 6-9. Literal database/server addressing and remote rowsets stay rejected; schema qualification stays valid.
    foreach (['SELECT Id FROM CompanyDB.dbo.Customer', 'SELECT Id FROM [CompanyDB].[dbo].[Customer]', 'SELECT Id FROM CompanyDB..Customer',
        'SELECT Id FROM sql01.CompanyDB.dbo.Customer', 'SELECT Id FROM dbo.T WHERE Id IN (SELECT Id FROM InventoryDB.dbo.Product)',
        'SELECT dbo.Customer.Id FROM dbo.Customer', 'SELECT Id FROM {{database:inventory}}.dbo.Product.Id', 'SELECT Id FROM {{database:inventory}}.Product',
        'SELECT {{database:inventory}} AS Name FROM dbo.T', 'SELECT Id FROM dbo.{{database:inventory}}.Product',
        "SELECT * FROM OPENQUERY(srv, 'SELECT 1')", "SELECT * FROM OpenRowset('SQLNCLI', 'Server=x;', 'SELECT 1') r",
        "SELECT Id FROM OPENDATASOURCE('SQLNCLI', 'Data Source=x').Db.dbo.T"] as $sql) {
        resAssert(resFailure(fn () => SqlResourceStatement::analyze($sql), "Unsafe resource SQL was accepted: {$sql}") instanceof RuntimeException,
            'Unsafe resource addressing was accepted.');
    }
    foreach (['SELECT c.Id FROM dbo.Customer c', 'SELECT [c].[Id] FROM [dbo].[Customer] AS [c]', 'SELECT Id FROM Customer',
        "SELECT JSON_VALUE(Data, '$.a.b.c') AS V FROM dbo.Documents", 'SELECT p.Id FROM {{database:inventory}}.dbo.Product p',
        'SELECT Id FROM {{database:inventory}}..Product', 'SELECT XmlData.value(\'(/a/b)[1]\', \'int\') AS V FROM dbo.Docs'] as $sql) {
        SqlResourceStatement::analyze($sql);
    }

    // 10, 16. Same-profile placeholders: one plan, one connection, one statement.
    $cross = ['action' => 'sql', 'resource' => 'reports/cross', 'execution' => ['columns' => ['ProductID', 'Name'],
        'defaultSort' => [['field' => 'ProductID', 'direction' => 'ASC']]], 'pagination' => ['page' => 1, 'pageSize' => 10]];
    $validator->validate($cross);
    $plan = $planner->plan($cross);
    resAssert($plan->databaseIds() === ['inventory', 'company'] && $plan->primaryDatabase->id === 'inventory' && $plan->serverProfileId === 'sql01'
        && $plan->isCrossDatabase && array_column($plan->references, 'path') === ['resource', 'resource'], 'Placeholder databases were not planned.');
    [$result, $engine, $attempts] = $runResource($cross);
    $data = $engine->dataSql();
    resAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=InventoryDB;')
        && array_unique(array_column($engine->statements, 'connection')) === ['catalog:InventoryDB'], 'A cross-database resource did not use one connection.');
    resAssert(count($data) === 2 && str_contains($data[0], 'COUNT(*) AS TotalRows')
        && str_contains($data[1], 'FROM [InventoryDB].dbo.Product p JOIN [CompanyDB].dbo.Customer c ON c.Id = p.CustomerId')
        && str_contains($data[0], 'FROM [InventoryDB].dbo.Product p JOIN [CompanyDB].dbo.Customer c') && !str_contains(implode(' ', $data), '{{'),
        'Count and data statements were not rendered from the registry: ' . json_encode($data));
    resAssert($result['data'] === [['ProductID' => 1, 'Name' => 'Row']] && $result['totalRows'] === 3, 'The cross-database resource result was merged or altered.');

    // 11. Different server profiles are rejected before connecting.
    $rejected(['action' => 'sql', 'resource' => 'reports/crossserver'], 400, 'CROSS_SERVER_QUERY_NOT_SUPPORTED', 'resource');

    // 15. A request database cannot redirect a resource that names its databases.
    $rejected(['action' => 'sql', 'resource' => 'reports/inventory', 'database' => 'company'], 400, 'INVALID_REQUEST', 'database');
    $rejected(['action' => 'sql', 'resource' => 'reports/inventory', 'database' => 'inventory'], 400, 'INVALID_REQUEST', 'database');

    // Runtime source resolution never reads another database's columns through this catalog.
    $sqlRepository = (new ReflectionClass(SqlRepository::class))->newInstanceWithoutConstructor();
    $resolveSourceColumn = new ReflectionMethod(SqlRepository::class, 'resolveSourceColumn');
    resAssert($resolveSourceColumn->invoke($sqlRepository, SqlResourceStatement::analyze($resources['cross']), 'Name', false) === null,
        'A placeholder resource resolved a source column.');
    // The early availability gate follows the resource's primary database.
    $availability->setAvailable(false, 'company');
    $gate = new DatabaseAvailabilityMiddleware(null, $resolver);
    resAssert(resResponse(resFailure(fn () => $gate->handle(['action' => 'sql', 'resource' => 'reports/plain']), 'A closed default passed.'))[1] === 'DATABASE_UNAVAILABLE',
        'The default gate changed.');
    $gate->handle(['action' => 'sql', 'resource' => 'reports/plain', 'database' => 'inventory']);
    $availability->setAvailable(true, 'company');

    // 16-23. Routines run in one selected database.
    $runRoutine = function (array $request) use ($validator, $normalizer, $planner, $logger, $connections): array {
        $validator->validate($request);
        DatabaseQueryPlanContext::set($planner->plan($request));
        ResSqlServerDriver::$attempts = [];
        try {
            $engine = new ResRecordingEngine(null, $logger, 0, true, $connections());
            $normalized = $normalizer->normalize($request);
            $result = (new QueryRepository($engine, null, $logger))->{$normalized['action']}($normalized);
        } finally {
            DatabaseQueryPlanContext::clear();
        }
        return [$result, $engine, ResSqlServerDriver::$attempts];
    };
    $procedure = ['action' => 'procedure', 'database' => 'inventory', 'source' => ['procedure' => 'dbo.GetProducts'], 'parameters' => [5]];
    [, $engine, $attempts] = $runRoutine($procedure);
    $routineLookup = array_values(array_filter($engine->statements, fn (array $statement): bool => str_contains($statement['sql'], 'INFORMATION_SCHEMA.ROUTINES')))[0];
    resAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=InventoryDB;') && $routineLookup['params'] === ['dbo', 'GetProducts']
        && $engine->dataSql() === ['EXEC [dbo].[GetProducts] ?'] && end($engine->statements)['params'] === [5]
        && array_unique(array_column($engine->statements, 'connection')) === ['catalog:InventoryDB'], 'A routine did not run in its selected database.');
    DatabaseQueryPlanContext::set($planner->plan($procedure));
    $repository = new QueryRepository(new ResRecordingEngine(null, $logger, 0, true, $connections()), null, $logger);
    $resolved = (new ReflectionMethod(QueryRepository::class, 'resolveRoutine'))->invoke($repository, 'dbo.GetProducts', 'procedure', ['params' => [5]]);
    DatabaseQueryPlanContext::clear();
    resAssert($resolved['object'] instanceof QualifiedObject && $resolved['object']->databaseId === 'inventory'
        && $resolved['object']->render() === '[InventoryDB].[dbo].[GetProducts]', 'The routine was not a QualifiedObject of the selected database.');
    // 17. Without a database: the default database, exactly as before.
    [, $engine, $attempts] = $runRoutine(['action' => 'procedure', 'source' => ['procedure' => 'RunReport']]);
    resAssert(str_contains($attempts[0], 'Database=CompanyDB;') && $engine->dataSql() === ['EXEC [dbo].[RunReport]'], 'The default routine behavior changed.');
    $failure = resFailure(fn () => $runRoutine(['action' => 'procedure', 'source' => ['procedure' => 'dbo.GetProducts'], 'parameters' => [5]]),
        'A routine of another database ran on the default database.');
    resAssert($failure instanceof ApiRequestException && $failure->getErrorCode() === 'INVALID_ROUTINE', 'An explicit routine database fell back to the default.');
    // 22. Parameters still bind, for functions and table functions too.
    [, $engine] = $runRoutine(['action' => 'function', 'database' => 'inventory', 'source' => ['function' => 'StockLevel'], 'parameters' => [7, 'A']]);
    resAssert($engine->dataSql() === ['SELECT [dbo].[StockLevel](?, ?) AS Result'] && end($engine->statements)['params'] === [7, 'A'], 'Function parameters did not bind.');
    [, $engine, $attempts] = $runRoutine(['action' => 'tableFunction', 'database' => 'legacy', 'source' => ['function' => 'dbo.ArchiveRows'], 'parameters' => [2026]]);
    resAssert(str_contains($attempts[0], 'Server=10.9.8.7,1433;') && str_contains($attempts[0], 'Database=Legacy-Archive;')
        && $engine->dataSql() === ['SELECT * FROM [dbo].[ArchiveRows](?)'], 'A routine on another server profile did not run on its own connection.');
    $failure = resFailure(fn () => $runRoutine(['action' => 'function', 'database' => 'inventory', 'source' => ['function' => 'StockLevel'], 'parameters' => [7]]),
        'A wrong argument count was accepted.');
    resAssert($failure instanceof ApiRequestException && $failure->getErrorCode() === 'INVALID_ROUTINE_PARAMETERS', 'Routine parameter validation changed.');
    // 18-20, 23. Errors before connecting; the physical database is never client-controlled.
    $rejected(['action' => 'procedure', 'database' => 'missing', 'source' => ['procedure' => 'dbo.GetProducts']], 404, 'DATABASE_NOT_FOUND', 'database');
    $rejected(['action' => 'procedure', 'database' => 'archive', 'source' => ['procedure' => 'dbo.GetProducts']], 403, 'DATABASE_DISABLED', 'database');
    $rejected(['action' => 'function', 'database' => 'offline', 'source' => ['function' => 'F']], 403, 'SERVER_PROFILE_DISABLED', 'database');
    $rejected(['action' => 'procedure', 'database' => 'reporting', 'source' => ['procedure' => 'dbo.GetProducts']], 503, 'DATABASE_UNAVAILABLE');
    $rejected(['action' => 'procedure', 'database' => 'InventoryDB', 'source' => ['procedure' => 'dbo.GetProducts']], 400, 'INVALID_REQUEST', 'database');
    foreach ([['procedure' => 'dbo.GetProducts', 'database' => 'inventory'], ['procedure' => 'dbo.GetProducts', 'server' => 'sql02']] as $source) {
        $rejected(['action' => 'procedure', 'source' => $source], 400, 'INVALID_REQUEST');
    }
    $rejected(['action' => 'procedure', 'source' => ['procedure' => 'dbo.GetProducts'], 'connectionString' => 'x'], 400, 'INVALID_REQUEST');
    // 21. Name and schema validation is unchanged.
    foreach (['InventoryDB.dbo.GetProducts', 'srv.InventoryDB.dbo.GetProducts', 'sys.sp_who', 'sp_helptext', 'dbo.xp_cmdshell'] as $name) {
        resAssert(resResponse(resFailure(fn () => RoutineResolver::name($name, 'procedure'), "Routine {$name} was accepted."))[1] === 'INVALID_ROUTINE',
            'Routine name validation changed.');
    }
    // Routine execution is single-target: a cross-database plan never runs a routine.
    DatabaseQueryPlanContext::set($plan);
    $crossRepository = new QueryRepository(new ResRecordingEngine(null, $logger, 0, true, $connections()), null, $logger);
    DatabaseQueryPlanContext::clear();
    resAssert(resFailure(fn () => $crossRepository->buildProcedure(['procedure' => 'dbo.GetProducts', 'params' => [1]]), 'A routine ran under a cross-database plan.')
        instanceof LogicException, 'A routine could target several databases.');

    // 14. Errors and logs expose no infrastructure.
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    resAssert(str_contains($logText, '["inventory","company"]'), 'The resource databases were not logged by id.');
    foreach ([...$secrets, 'CompanyDB', 'InventoryDB', 'Sales.2024', 'Legacy-Archive', 'ArchiveDB', $key] as $secret) {
        resAssert(!str_contains(implode("\n", $payloads), $secret), "An error exposed {$secret}.");
        resAssert(!str_contains($logText, $secret), "A log exposed {$secret}.");
    }

    echo "Database resource and routine tests passed.\n";
} finally {
    DatabaseQueryPlanContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    resRemoveDirectory($directory);
}
