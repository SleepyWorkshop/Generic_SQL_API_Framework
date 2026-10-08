<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/WriteRepository.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 9: database-aware INSERT, UPDATE, DELETE, and UPSERT.
 *
 * Unit, planner, and SQL-generation tests. The end-to-end cases run the real
 * planner, WriteRepository, MetadataRepository, QueryEngine, and
 * DatabaseConnectionManager with the ODBC calls recorded; catalog lookups are
 * answered by an in-memory model of each database. SQL Server is not involved.
 */

function writeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function writeFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code, first detail path, encoded payload] of the API response an exception produces. */
function writeResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, $payload['error']['details'][0]['path'] ?? null, json_encode($payload)];
}

function writeRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** Writable tables per physical database: schema → table → column → [type, max length, identity, unique]. */
const WRITE_CATALOGS = [
    'CompanyDB' => ['dbo' => ['Customers' => ['Id' => ['int', -1, true, false], 'CustomerCode' => ['varchar', 20, false, true], 'Name' => ['nvarchar', 200, false, false]]]],
    'InventoryDB' => [
        'dbo' => ['Product' => ['Id' => ['int', -1, true, false], 'Sku' => ['varchar', 40, false, true], 'Name' => ['nvarchar', 100, false, false], 'Price' => ['int', -1, false, false]]],
        'sales' => ['Product' => ['Id' => ['int', -1, true, false], 'Sku' => ['varchar', 40, false, true], 'Region' => ['varchar', 10, false, false]]],
    ],
    'Sales.2024]Q' => ['dbo' => ['Orders' => ['Id' => ['int', -1, true, false], 'Total' => ['int', -1, false, false]]]],
];

function writeRows(string $sql, array $params, string $connected): array
{
    if (str_contains($sql, 'FROM sys.columns')) {
        $rows = [];
        foreach (WRITE_CATALOGS[$connected][$params[0]][$params[1]] ?? [] as $column => [$type, $length, $identity]) {
            $rows[] = ['ColumnName' => $column, 'DataType' => $type, 'MaxLength' => $type === 'nvarchar' ? $length * 2 : $length,
                'NumericPrecision' => 10, 'NumericScale' => 0, 'IsNullable' => 0, 'IsIdentity' => (int)$identity, 'IsComputed' => 0,
                'GeneratedAlwaysType' => 0, 'IsHidden' => 0, 'HasDefault' => 0];
        }
        return $rows;
    }
    if (str_contains($sql, 'FROM sys.indexes')) {
        $rows = [];
        foreach (WRITE_CATALOGS[$connected][$params[0]][$params[1]] ?? [] as $column => [, , , $unique]) {
            if ($unique) $rows[] = ['IndexName' => 'UX_' . $column, 'ColumnName' => $column, 'KeyOrdinal' => 1];
        }
        return $rows;
    }
    if (str_contains($sql, '@__WriteOutput')) {
        return [['__operation' => str_contains($sql, 'MERGE INTO') ? 'UPDATE' : null, '__affected' => 1,
            '__generatedId' => str_contains($sql, 'INSERT INTO') ? 42 : null]];
    }
    return [];
}

/** Records connection attempts; the handle names the catalog it opened. */
final class WriteSqlServerDriver extends SqlServerDriver
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

final class WriteDatabase extends Database
{
    private WriteSqlServerDriver $writeDriver;

    public function __construct(array $configuration)
    {
        $this->writeDriver = new WriteSqlServerDriver($configuration, false);
        $this->writeDriver->connect();
    }

    public function getConnection() { return $this->writeDriver->getConnection(); }
    public function close() {}
}

/** The real QueryEngine with its ODBC statement calls recorded. */
final class WriteRecordingEngine extends QueryEngine
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
        $statement['rows'] = writeRows($statement['sql'], $params, substr((string)$this->connection, strlen('catalog:')));
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

    /** The write statement itself, without catalog lookups. */
    public function writes(): array
    {
        return array_values(array_filter($this->statements, fn (array $statement): bool => str_contains($statement['sql'], '@__WriteOutput')));
    }

    public function lookups(): array
    {
        return array_column(array_values(array_filter($this->statements, fn (array $statement): bool => str_contains($statement['sql'], 'FROM sys.'))), 'params');
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-database-write-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$secrets = ['write-secret', 'legacy-secret', 'write_user', 'legacy_user', 'sql01.write.test', '10.4.5.6', '14330'];

try {
    foreach (['config', 'database/config', 'logs', 'operational'] as $path) mkdir($directory . '/' . $path, 0700, true);
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    // sql01: company (default), inventory, sales, archive (disabled), reporting (gate closed); sql02: legacy.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.write.test', 'port' => '14330',
        'authentication' => 'sql', 'username' => 'write_user', 'password' => 'write-secret', 'options' => ['encrypt' => true]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, [...$sql01, 'server' => '10.4.5.6', 'port' => '1433', 'username' => 'legacy_user', 'password' => 'legacy-secret']);
    foreach ([['company', 'CompanyDB', 'sql01', true], ['inventory', 'InventoryDB', 'sql01', true], ['sales', 'Sales.2024]Q', 'sql01', true],
        ['archive', 'ArchiveDB', 'sql01', false], ['reporting', 'ReportingDB', 'sql01', true], ['legacy', 'Legacy-Archive', 'sql02', true]] as [$id, $physical, $server, $enabled]) {
        $registry->saveDatabase($id, ucfirst($id), $server, $enabled, $physical);
    }
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'sales', 'archive', 'legacy'] as $id) $availability->setAvailable(true, $id);
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver);
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $logger = new Logger($directory . '/logs');
    $payloads = [];

    // Validate, plan, connect once, and run the real WriteRepository. Unplanned:
    // the repository runs without a plan, as V2 did (the connection still opens
    // through the plan, so no live configuration is read).
    $write = function (array $request, bool $planned = true) use ($validator, $normalizer, $planner, $logger): array {
        $validator->validate($request);
        DatabaseQueryPlanContext::set($planner->plan($request));
        WriteSqlServerDriver::$attempts = [];
        try {
            $engine = new WriteRecordingEngine(null, $logger, 0, true,
                new DatabaseConnectionManager(static fn (array $configuration): Database => new WriteDatabase($configuration)));
            if (!$planned) DatabaseQueryPlanContext::clear();
            $result = (new WriteRepository($engine))->execute($normalizer->normalize($request));
        } finally {
            DatabaseQueryPlanContext::clear();
        }
        return [$result, $engine, WriteSqlServerDriver::$attempts];
    };
    $rejected = function (array $request, int $status, string $code, ?string $path = null) use ($validator, $planner, &$payloads): void {
        WriteSqlServerDriver::$attempts = [];
        [$actualStatus, $actualCode, $actualPath, $payload] = writeResponse(writeFailure(function () use ($validator, $planner, $request): void {
            $validator->validate($request);
            $planner->plan($request);
        }, 'Request was accepted: ' . json_encode($request)));
        writeAssert($actualStatus === $status && $actualCode === $code && ($path === null || $actualPath === $path),
            "Expected {$status} {$code} at {$path} for " . json_encode($request) . "; got {$actualStatus} {$actualCode} at {$actualPath}.");
        writeAssert(WriteSqlServerDriver::$attempts === [] && DatabaseQueryPlanContext::current() === null, 'A rejected write connected.');
        $payloads[] = $payload;
    };
    $output = 'OUTPUT CAST(NULL AS nvarchar(10)), 1, ';
    $batch = fn (string $statement): string => 'SET NOCOUNT ON; DECLARE @__WriteOutput TABLE ([__operation] nvarchar(10) NULL, [__affected] int NOT NULL, [__generatedId] sql_variant NULL); '
        . $statement . '; SELECT [__operation], [__affected], [__generatedId] FROM @__WriteOutput;';

    // 1-8, 26-32. Each write on the default and on an explicit database.
    $cases = [
        'insert' => [
            ['action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C1', 'Name' => 'Ada']],
            $batch('INSERT INTO [dbo].[Customers] ([CustomerCode], [Name]) ' . $output . 'CONVERT(sql_variant, INSERTED.[Id]) INTO @__WriteOutput ([__operation], [__affected], [__generatedId]) VALUES (?, ?)'),
            ['C1', 'Ada'], ['operation' => 'insert', 'affectedRows' => 1, 'generatedId' => 42],
            ['action' => 'insert', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Sku' => 'K1', 'Name' => 'Keyboard', 'Price' => 100]],
            $batch('INSERT INTO [dbo].[Product] ([Sku], [Name], [Price]) ' . $output . 'CONVERT(sql_variant, INSERTED.[Id]) INTO @__WriteOutput ([__operation], [__affected], [__generatedId]) VALUES (?, ?, ?)'),
            ['K1', 'Keyboard', 100],
        ],
        'update' => [
            ['action' => 'update', 'table' => 'Customers', 'data' => ['Name' => 'Ada'], 'filters' => [['field' => 'CustomerCode', 'operator' => '=', 'value' => 'C1']]],
            $batch('UPDATE [dbo].[Customers] SET [Name] = ? ' . $output . 'CAST(NULL AS sql_variant) INTO @__WriteOutput ([__operation], [__affected], [__generatedId]) WHERE ([CustomerCode] = ?)'),
            ['Ada', 'C1'], ['operation' => 'update', 'affectedRows' => 1],
            ['action' => 'update', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Price' => 100], 'filters' => [['field' => 'Sku', 'operator' => '=', 'value' => 'K1']]],
            $batch('UPDATE [dbo].[Product] SET [Price] = ? ' . $output . 'CAST(NULL AS sql_variant) INTO @__WriteOutput ([__operation], [__affected], [__generatedId]) WHERE ([Sku] = ?)'),
            [100, 'K1'],
        ],
        'delete' => [
            ['action' => 'delete', 'table' => 'Customers', 'filters' => [['field' => 'CustomerCode', 'operator' => '=', 'value' => 'C1']]],
            $batch('DELETE FROM [dbo].[Customers] ' . $output . 'CAST(NULL AS sql_variant) INTO @__WriteOutput ([__operation], [__affected], [__generatedId]) WHERE ([CustomerCode] = ?)'),
            ['C1'], ['operation' => 'delete', 'affectedRows' => 1],
            ['action' => 'delete', 'database' => 'inventory', 'table' => 'Product', 'filters' => [['field' => 'Sku', 'operator' => '=', 'value' => 'K1']]],
            $batch('DELETE FROM [dbo].[Product] ' . $output . 'CAST(NULL AS sql_variant) INTO @__WriteOutput ([__operation], [__affected], [__generatedId]) WHERE ([Sku] = ?)'),
            ['K1'],
        ],
        'upsert' => [
            ['action' => 'upsert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C1', 'Name' => 'Ada'], 'keys' => ['CustomerCode']],
            $batch('MERGE INTO [dbo].[Customers] WITH (HOLDLOCK) AS [target] USING (VALUES (?, ?)) AS [source] ([CustomerCode], [Name]) ON [target].[CustomerCode] = [source].[CustomerCode] WHEN MATCHED THEN UPDATE SET [target].[Name] = [source].[Name] WHEN NOT MATCHED THEN INSERT ([CustomerCode], [Name]) VALUES ([source].[CustomerCode], [source].[Name]) OUTPUT $action, 1, CONVERT(sql_variant, INSERTED.[Id]) INTO @__WriteOutput ([__operation], [__affected], [__generatedId])'),
            ['C1', 'Ada'], ['operation' => 'update', 'affectedRows' => 1],
            ['action' => 'upsert', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Sku' => 'K1', 'Name' => 'Keyboard', 'Price' => 100], 'keys' => ['Sku']],
            $batch('MERGE INTO [dbo].[Product] WITH (HOLDLOCK) AS [target] USING (VALUES (?, ?, ?)) AS [source] ([Sku], [Name], [Price]) ON [target].[Sku] = [source].[Sku] WHEN MATCHED THEN UPDATE SET [target].[Name] = [source].[Name], [target].[Price] = [source].[Price] WHEN NOT MATCHED THEN INSERT ([Sku], [Name], [Price]) VALUES ([source].[Sku], [source].[Name], [source].[Price]) OUTPUT $action, 1, CONVERT(sql_variant, INSERTED.[Id]) INTO @__WriteOutput ([__operation], [__affected], [__generatedId])'),
            ['K1', 'Keyboard', 100],
        ],
    ];
    foreach ($cases as $action => [$defaultRequest, $defaultSql, $defaultParams, $payload, $explicitRequest, $explicitSql, $explicitParams]) {
        [$result, $engine, $attempts] = $write($defaultRequest);
        [$v2Result, $v2Engine] = $write($defaultRequest, false);
        $writes = $engine->writes();
        writeAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=CompanyDB;') && count($writes) === 1
            && $writes[0]['sql'] === $defaultSql && $writes[0]['params'] === $defaultParams, "Default {$action} changed: " . ($writes[0]['sql'] ?? ''));
        writeAssert($v2Engine->writes()[0]['sql'] === $defaultSql && $v2Engine->writes()[0]['params'] === $defaultParams,
            "The planned default {$action} differs from the unplanned (V2) one.");
        writeAssert($result['data'] === [$payload] && $result['affectedRows'] === 1 && $result['rowsReturned'] === 0 && $result['totalRows'] === 0
            && array_diff_key($result, ['executionTime' => true]) === array_diff_key($v2Result, ['executionTime' => true])
            && array_keys($result) === ['executionTime', 'rowsReturned', 'totalRows', 'affectedRows', 'data'], "The {$action} response changed: " . json_encode($result));
        [$result, $engine, $attempts] = $write($explicitRequest);
        $writes = $engine->writes();
        writeAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=InventoryDB;') && str_contains($attempts[0], 'Server=sql01.write.test,14330;')
            && count($writes) === 1 && $writes[0]['sql'] === $explicitSql && $writes[0]['params'] === $explicitParams
            && array_unique(array_column($engine->statements, 'connection')) === ['catalog:InventoryDB'] && $engine->lookups()[0] === ['dbo', 'Product'],
            "An explicit-database {$action} did not target its database: " . ($writes[0]['sql'] ?? ''));
        writeAssert($result['affectedRows'] === 1, "The explicit {$action} response changed.");
    }

    // 9, 17, 18. The target is a QualifiedObject of the registry's physical database.
    $plan = $planner->plan(['action' => 'insert', 'database' => 'inventory', 'table' => 'sales.Product', 'data' => ['Sku' => 'K']]);
    DatabaseQueryPlanContext::set($plan);
    $target = (new ReflectionMethod(WriteRepository::class, 'targetObject'))->invoke((new ReflectionClass(WriteRepository::class))->newInstanceWithoutConstructor(),
        ['schema' => 'sales', 'name' => 'Product']);
    DatabaseQueryPlanContext::set($planner->plan(['action' => 'delete', 'database' => 'sales', 'table' => 'Orders', 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]]));
    $quoted = (new ReflectionMethod(WriteRepository::class, 'targetObject'))->invoke((new ReflectionClass(WriteRepository::class))->newInstanceWithoutConstructor(),
        ['schema' => 'dbo', 'name' => 'Orders']);
    DatabaseQueryPlanContext::clear();
    writeAssert($plan->primaryDatabase->physicalName() === 'InventoryDB' && !$plan->isCrossDatabase
        && $target['object']->render() === '[InventoryDB].[sales].[Product]' && $target['object']->renderLocal() === '[sales].[Product]'
        && $quoted['object']->render() === '[Sales.2024]]Q].[dbo].[Orders]', 'The write target was not a registry-derived qualified object.');
    [, $engine, $attempts] = $write(['action' => 'update', 'database' => 'sales', 'table' => 'Orders', 'data' => ['Total' => 5], 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]]);
    writeAssert(str_contains($attempts[0], 'Database=Sales.2024]Q;') && str_starts_with($engine->writes()[0]['sql'], 'SET NOCOUNT ON; DECLARE @__WriteOutput')
        && str_contains($engine->writes()[0]['sql'], 'UPDATE [dbo].[Orders] SET [Total] = ?'), 'A punctuated physical database was not targeted.');

    // 13-16. Schema-aware writes read and write that schema's table.
    foreach ([
        ['action' => 'insert', 'table' => 'sales.Product', 'data' => ['Sku' => 'K1', 'Region' => 'EU']],
        ['action' => 'update', 'table' => 'sales.Product', 'data' => ['Region' => 'EU'], 'filters' => [['field' => 'Sku', 'operator' => '=', 'value' => 'K1']]],
        ['action' => 'delete', 'table' => 'sales.Product', 'filters' => [['field' => 'Sku', 'operator' => '=', 'value' => 'K1']]],
        ['action' => 'upsert', 'table' => 'sales.Product', 'data' => ['Sku' => 'K1', 'Region' => 'EU'], 'keys' => ['Sku']],
    ] as $request) {
        [, $engine] = $write(['database' => 'inventory'] + $request);
        writeAssert($engine->lookups()[0] === ['sales', 'Product'] && preg_match('/(?:INTO|UPDATE|FROM) \[sales\]\.\[Product\]/', $engine->writes()[0]['sql']) === 1,
            "A schema-aware {$request['action']} did not target sales.Product.");
    }
    // `Region` exists only on sales.Product, `Price` only on dbo.Product: the schema really selects the table.
    writeAssert(writeResponse(writeFailure(fn () => $write(['action' => 'insert', 'database' => 'inventory', 'table' => 'sales.Product', 'data' => ['Sku' => 'K', 'Price' => 1]]),
        'A column of another schema was written.'))[1] === 'INVALID_WRITE_COLUMN', 'Schema-aware column validation failed.');

    // 10-12, 36. Errors before connecting, and no fallback to the default database.
    $rejected(['action' => 'insert', 'database' => 'missing', 'table' => 'Customers', 'data' => ['Name' => 'A']], 404, 'DATABASE_NOT_FOUND', 'database');
    $rejected(['action' => 'delete', 'database' => 'archive', 'table' => 'Customers', 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]], 403, 'DATABASE_DISABLED', 'database');
    $rejected(['action' => 'update', 'database' => 'reporting', 'table' => 'Customers', 'data' => ['Name' => 'A'], 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]]], 503, 'DATABASE_UNAVAILABLE');
    $failure = writeFailure(fn () => $write(['action' => 'insert', 'database' => 'inventory', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C1', 'Name' => 'Ada']]),
        'A default-database table was written through another database.');
    writeAssert(writeResponse($failure)[1] === 'INVALID_WRITE_TABLE', 'An explicit database fell back to the default database.');
    $middleware = new DatabaseAvailabilityMiddleware(null, $resolver);
    $availability->setAvailable(false, 'company');
    writeAssert(writeResponse(writeFailure(fn () => $middleware->handle(['action' => 'insert', 'table' => 'Customers']), 'A closed default accepted a write.'))[1] === 'DATABASE_UNAVAILABLE',
        'The default write gate changed.');
    $middleware->handle(['action' => 'insert', 'database' => 'inventory', 'table' => 'Product']);
    $availability->setAvailable(true, 'company');
    DatabaseQueryPlanContext::set($planner->plan(['action' => 'insert', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Sku' => 'K']]));
    $availability->setAvailable(false, 'inventory');
    WriteSqlServerDriver::$attempts = [];
    [$status, $code] = writeResponse(writeFailure(fn () => new WriteRecordingEngine(null, $logger, 0, true,
        new DatabaseConnectionManager(static fn (array $c): Database => new WriteDatabase($c))), 'A closed target connected.'));
    DatabaseQueryPlanContext::clear();
    $availability->setAvailable(true, 'inventory');
    writeAssert($status === 503 && $code === 'DATABASE_UNAVAILABLE' && WriteSqlServerDriver::$attempts === [], 'A closed target database was contacted.');

    // 19-22. Physical names, servers, and SQL fragments cannot be injected; values stay bound.
    $rejected(['action' => 'insert', 'database' => 'InventoryDB', 'table' => 'Product', 'data' => ['Sku' => 'K']], 400, 'INVALID_REQUEST', 'database');
    $rejected(['action' => 'insert', 'database' => 'inventorydb', 'table' => 'Product', 'data' => ['Sku' => 'K']], 404, 'DATABASE_NOT_FOUND', 'database');
    $rejected(['action' => 'insert', 'database' => 'inventory; DROP TABLE x', 'table' => 'Product', 'data' => ['Sku' => 'K']], 400, 'INVALID_REQUEST', 'database');
    foreach (['server' => 'sql02', 'host' => '10.4.5.6', 'port' => 1433, 'connectionString' => 'Server=x', 'username' => 'u', 'password' => 'p'] as $field => $value) {
        $rejected(['action' => 'insert', 'table' => 'Product', 'data' => ['Sku' => 'K'], $field => $value], 400, 'INVALID_REQUEST', $field);
    }
    foreach (['Product; DROP TABLE x', 'InventoryDB.dbo.Product', 'sql01.InventoryDB.dbo.Product', '[dbo].[Product]', 'dbo..Product', 'Product--'] as $table) {
        $rejected(['action' => 'insert', 'database' => 'inventory', 'table' => $table, 'data' => ['Sku' => 'K']], 400, 'INVALID_REQUEST', 'table');
    }
    writeAssert(writeResponse(writeFailure(fn () => $write(['action' => 'insert', 'table' => 'sys.objects', 'data' => ['Name' => 'x']]), 'A system table was written.'))[1] === 'INVALID_WRITE_TABLE',
        'System schemas became writable.');
    [, $engine] = $write(['action' => 'insert', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Sku' => "K'); DROP TABLE x;--", 'Name' => 'N', 'Price' => 1]]);
    writeAssert(!str_contains($engine->writes()[0]['sql'], 'DROP') && $engine->writes()[0]['params'] === ["K'); DROP TABLE x;--", 'N', 1], 'A value was inlined into write SQL.');

    // 23-25, 33-35. One write target: no multi-target, conflicting, cross-database, or cross-server forms.
    foreach ([
        ['database' => ['inventory', 'company']],
        ['database' => ['id' => 'inventory']],
        ['targets' => [['database' => 'inventory', 'table' => 'Product'], ['database' => 'company', 'table' => 'Customers']]],
        ['databases' => ['inventory', 'company']],
        ['source' => ['database' => 'legacy', 'table' => 'Old']],
        ['joins' => [['type' => 'INNER', 'source' => ['database' => 'company', 'table' => 'Customers'], 'on' => ['left' => 'Id', 'right' => 'Id']]]],
        ['from' => ['database' => 'company', 'table' => 'Customers']],
    ] as $extra) {
        $rejected(array_replace(['action' => 'update', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Price' => 1],
            'filters' => [['field' => 'Sku', 'operator' => '=', 'value' => 'K']]], $extra), 400, 'INVALID_REQUEST');
    }
    $rejected(['action' => 'update', 'database' => 'inventory', 'table' => 'Product', 'data' => ['Price' => 1],
        'filters' => [['field' => 'Sku', 'operator' => 'IN', 'query' => ['source' => ['database' => 'legacy', 'table' => 'Old'], 'fields' => ['Sku']]]]], 400, 'INVALID_REQUEST');
    $rejected(['action' => 'insert', 'database' => 'inventory', 'table' => ['Product', 'company.Customers'], 'data' => ['Sku' => 'K']], 400, 'INVALID_REQUEST', 'table');
    foreach (['insert', 'update', 'delete', 'upsert'] as $action) {
        $plan = $planner->plan(['action' => $action, 'database' => 'legacy', 'table' => 'Old']);
        writeAssert($plan->databaseIds() === ['legacy'] && !$plan->isCrossDatabase, "A {$action} plan named more than one database.");
    }
    // A plan naming several databases never reaches a write, even if constructed elsewhere.
    DatabaseQueryPlanContext::set($planner->plan(['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Id'],
        'joins' => [['type' => 'INNER', 'source' => ['database' => 'inventory', 'table' => 'Product'], 'on' => ['left' => 'Id', 'right' => 'Id']]]]));
    $failure = writeFailure(fn () => (new ReflectionMethod(WriteRepository::class, 'targetObject'))
        ->invoke((new ReflectionClass(WriteRepository::class))->newInstanceWithoutConstructor(), ['schema' => 'dbo', 'name' => 'Product']), 'A write accepted two databases.');
    DatabaseQueryPlanContext::clear();
    writeAssert($failure instanceof LogicException, 'A write could target several databases.');
    $writeSources = '';
    foreach (['app/Repositories/WriteRepository.php', 'app/Repositories/Write/WriteSqlBuilder.php', 'app/Repositories/Write/InsertBuilder.php',
        'app/Repositories/Write/UpdateBuilder.php', 'app/Repositories/Write/DeleteBuilder.php', 'app/Repositories/Write/UpsertBuilder.php'] as $file) {
        $writeSources .= (string)file_get_contents(dirname(__DIR__) . '/' . $file);
    }
    writeAssert(!preg_match("/\\\$request\\['database'\\]|DatabaseRegistry|DatabaseContextResolver|BEGIN TRAN|COMMIT|ROLLBACK/i", $writeSources),
        'Writes select databases or manage transactions on their own.');

    // 38-39. Errors and logs expose no infrastructure.
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    writeAssert(str_contains($logText, '["inventory"]'), 'The write target was not logged by id.');
    foreach ([...$secrets, 'CompanyDB', 'InventoryDB', 'Sales.2024', 'Legacy-Archive', 'ArchiveDB', $key] as $secret) {
        writeAssert(!str_contains(implode("\n", $payloads), $secret), "An error exposed {$secret}.");
        writeAssert(!str_contains($logText, $secret), "A log exposed {$secret}.");
    }

    echo "Database write tests passed.\n";
} finally {
    DatabaseQueryPlanContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    writeRemoveDirectory($directory);
}
