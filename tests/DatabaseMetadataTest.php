<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Database/DatabaseDirectory.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../app/Services/MetadataService.php';
require_once __DIR__ . '/../app/Controllers/MetadataController.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 7: database-aware metadata and metadata.databases.
 *
 * Unit and SQL-generation tests. Catalog queries are answered by an in-memory
 * model of each database's INFORMATION_SCHEMA, chosen by the catalog the SQL
 * names (`[Db].INFORMATION_SCHEMA`, else the connected database). This is not
 * SQL Server: real catalog behavior needs an integration environment.
 */

function metaAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function metaFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code, first detail path, encoded payload] of the API response an exception produces. */
function metaResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, $payload['error']['details'][0]['path'] ?? null, json_encode($payload)];
}

function metaRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** Per physical database: schema → table → column → data type. Same names, different shapes. */
const META_CATALOGS = [
    'CompanyDB' => [
        'dbo' => ['Customer' => ['Id' => 'int', 'Name' => 'nvarchar', 'Status' => 'varchar', 'ProductId' => 'int'], 'Region' => ['Id' => 'int', 'Name' => 'nvarchar']],
        'sales' => ['Customer' => ['Id' => 'int', 'Region' => 'varchar']],
    ],
    'InventoryDB' => [
        'dbo' => ['Customer' => ['Id' => 'int', 'Status' => 'int', 'Warehouse' => 'varchar'], 'Product' => ['Id' => 'int', 'Name' => 'nvarchar', 'CustomerId' => 'int']],
        'sales' => ['Product' => ['Id' => 'int', 'Sku' => 'varchar']],
    ],
    'ReportingDB' => ['dbo' => ['Sales' => ['Id' => 'int', 'CustomerId' => 'int', 'Amount' => 'decimal']]],
];

/** Answers INFORMATION_SCHEMA queries from META_CATALOGS. */
function metaCatalogRows(string $sql, array $params, string $connected): array
{
    if (str_contains($sql, 'compatibility_level')) return [['CompatibilityLevel' => 150]];
    if (!str_contains($sql, 'INFORMATION_SCHEMA')) return [['Id' => 1]];
    $database = preg_match('/FROM\s+\[((?:[^\]]|\]\])+)\]\.INFORMATION_SCHEMA/', $sql, $match) === 1 ? str_replace(']]', ']', $match[1]) : $connected;
    $rows = [];
    foreach (META_CATALOGS[$database] ?? [] as $schema => $tables) {
        foreach ($tables as $table => $columns) {
            if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) {
                $rows[] = ['TABLE_SCHEMA' => $schema, 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE'];
                continue;
            }
            $position = 0;
            foreach ($columns as $column => $type) {
                $rows[] = ['TABLE_SCHEMA' => $schema, 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column, 'DATA_TYPE' => $type,
                    'IS_NULLABLE' => 'YES', 'ORDINAL_POSITION' => ++$position];
            }
        }
    }
    preg_match_all("/(TABLE_SCHEMA|TABLE_NAME|COLUMN_NAME|TABLE_TYPE)\\s*=\\s*(\\?|'[^']*')/", $sql, $predicates, PREG_SET_ORDER);
    foreach ($predicates as [, $field, $value]) {
        $expected = $value === '?' ? array_shift($params) : trim($value, "'");
        $rows = array_values(array_filter($rows, fn (array $row): bool => strcasecmp((string)$row[$field], (string)$expected) === 0));
    }
    if (str_contains($sql, 'COUNT(*) AS Total')) return [['Total' => count($rows)]];
    preg_match('/SELECT\s+(.*?)\s+FROM/s', $sql, $select);
    $fields = array_map('trim', explode(',', $select[1]));
    return array_map(fn (array $row): array => array_intersect_key($row, array_flip($fields)), $rows);
}

/** Records connection attempts; the connection handle names the catalog it opened. */
final class MetaSqlServerDriver extends SqlServerDriver
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

final class MetaDatabase extends Database
{
    private MetaSqlServerDriver $metaDriver;

    public function __construct(array $configuration)
    {
        $this->metaDriver = new MetaSqlServerDriver($configuration, false);
        $this->metaDriver->connect();
    }

    public function getConnection() { return $this->metaDriver->getConnection(); }
    public function close() {}
}

/** The real QueryEngine; statements are answered by the catalog model of the connected database. */
final class MetaCatalogEngine extends QueryEngine
{
    public array $statements = [];

    protected function prepareStatement(string $sql)
    {
        $this->statements[] = ['sql' => trim((string)preg_replace('/\s+/', ' ', $sql)), 'params' => []];
        return new ArrayObject(['sql' => $sql, 'rows' => []]);
    }
    protected function executeStatement($statement, array $params): bool
    {
        $this->statements[count($this->statements) - 1]['params'] = $params;
        $statement['rows'] = metaCatalogRows($statement['sql'], $params, substr((string)$this->connection, strlen('catalog:')));
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

    public function catalogSql(): array
    {
        return array_values(array_filter($this->statements, fn (array $statement): bool => str_contains($statement['sql'], 'INFORMATION_SCHEMA')));
    }
}

/** A registry reader whose second database references a missing server profile. */
final class MetaMissingProfileReader implements DatabaseRegistryReader
{
    public function __construct(private DatabaseRegistry $registry) {}
    public function metadata(): array
    {
        $metadata = $this->registry->metadata();
        $metadata['databases']['orphan'] = ['name' => 'Orphan', 'server' => 'gone', 'enabled' => true];
        return $metadata;
    }
    public function serverConnection(string $serverId): array { return $this->registry->serverConnection($serverId); }
    public function databaseCatalog(string $databaseId): string { return $this->registry->databaseCatalog($databaseId); }
}

/** Registry metadata only: decrypting anything fails the test. */
final class MetaMetadataOnlyReader implements DatabaseRegistryReader
{
    public function __construct(private DatabaseRegistry $registry) {}
    public function metadata(): array { return $this->registry->metadata(); }
    public function serverConnection(string $serverId): array { throw new LogicException('The database listing decrypted a server profile.'); }
    public function databaseCatalog(string $databaseId): string { throw new LogicException('The database listing decrypted a catalog.'); }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-database-metadata-' . bin2hex(random_bytes(8));
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

    // company, inventory, reporting, archive (disabled) on sql01; legacy on sql02;
    // offline on the disabled profile sql03.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.meta.test', 'port' => '14330',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => 'company-secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => true, 'loginTimeoutSeconds' => 9]];
    $sql02 = [...$sql01, 'server' => '10.20.30.41', 'port' => '14331', 'username' => 'legacy_user', 'password' => 'legacy-secret'];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, $sql02);
    $registry->saveServer('sql03', 'SQL Server 03', false, [...$sql02, 'server' => 'sql03.meta.test']);
    $registry->saveDatabase('company', 'Company', 'sql01', true, 'CompanyDB');
    $registry->saveDatabase('inventory', 'Inventory', 'sql01', true, 'InventoryDB');
    $registry->saveDatabase('reporting', 'Reporting', 'sql01', true, 'ReportingDB');
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $registry->saveDatabase('legacy', 'Legacy', 'sql02', true, 'Legacy-Archive');
    $registry->saveDatabase('offline', 'Offline', 'sql03', true, 'OfflineDB');
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'reporting', 'legacy', 'archive'] as $id) $availability->setAvailable(true, $id);
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver);
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $logger = new Logger($directory . '/logs');
    $payloads = [];

    /** Run a public request through validation, planning, one connection, and the metadata service. */
    $metadata = function (array $request) use ($validator, $normalizer, $planner, $logger): array {
        $validator->validate($request);
        $normalized = $normalizer->normalize($request);
        DatabaseQueryPlanContext::set($planner->plan($request));
        MetaSqlServerDriver::$attempts = [];
        $engine = new MetaCatalogEngine(null, $logger, 0, true,
            new DatabaseConnectionManager(static fn (array $configuration): Database => new MetaDatabase($configuration)));
        $service = new MetadataService(new MetadataRepository($engine));
        $result = match ($normalized['action']) {
            'tables' => $service->getTables(),
            'columns' => $service->getColumns($normalized['table'], $normalized['schema'] ?? null),
            'schema' => $service->schema(),
        };
        DatabaseQueryPlanContext::clear();
        return [$result, $engine, MetaSqlServerDriver::$attempts];
    };
    $columnNames = fn (array $result): array => array_column($result['data'], 'COLUMN_NAME');
    $rejected = function (array $request, int $status, string $code) use ($validator, $planner, &$payloads): void {
        MetaSqlServerDriver::$attempts = [];
        [$actualStatus, $actualCode, , $payload] = metaResponse(metaFailure(function () use ($validator, $planner, $request): void {
            $validator->validate($request);
            $planner->plan($request);
        }, 'Request was accepted: ' . json_encode($request)));
        metaAssert($actualStatus === $status && $actualCode === $code, "Expected {$status} {$code} for " . json_encode($request) . "; got {$actualStatus} {$actualCode}.");
        metaAssert(MetaSqlServerDriver::$attempts === [] && DatabaseQueryPlanContext::current() === null, 'A rejected metadata request connected or planned.');
        $payloads[] = $payload;
    };

    // 1-3. Database selection.
    [$defaultColumns, $defaultEngine, $attempts] = $metadata(['action' => 'metadata.columns', 'source' => ['table' => 'Region']]);
    metaAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=CompanyDB;') && $columnNames($defaultColumns) === ['Id', 'Name'],
        'Metadata without a database did not use the default database.');
    metaAssert($defaultEngine->catalogSql()[0]['sql'] === 'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION;'
        && $defaultEngine->catalogSql()[0]['params'] === ['Region'], 'The V2 column listing changed.');
    [$inventoryColumns, , $attempts] = $metadata(['action' => 'metadata.columns', 'database' => 'inventory', 'source' => ['table' => 'Customer']]);
    metaAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=InventoryDB;') && str_contains($attempts[0], 'Server=sql01.meta.test,14330;')
        && $columnNames($inventoryColumns) === ['Id', 'Status', 'Warehouse'], 'An explicit database was not used for metadata.');
    $plan = $planner->plan(['action' => 'metadata.tables', 'database' => 'inventory']);
    metaAssert($plan->primaryDatabase->id === 'inventory' && $plan->primaryDatabase->physicalName() === 'InventoryDB' && !$plan->isCrossDatabase,
        'The explicit metadata database did not resolve through the registry.');

    // 4-8. Resolution errors never fall back to the default database.
    $rejected(['action' => 'metadata.tables', 'database' => 'missing'], 404, 'DATABASE_NOT_FOUND');
    $rejected(['action' => 'metadata.columns', 'database' => 'archive', 'source' => ['table' => 'Customer']], 403, 'DATABASE_DISABLED');
    $rejected(['action' => 'metadata.views', 'database' => 'offline'], 403, 'SERVER_PROFILE_DISABLED');
    [$status, $code] = metaResponse(metaFailure(fn () => (new DatabaseQueryPlanner(new DatabaseContextResolver(new MetaMissingProfileReader($registry), $availability)))
        ->plan(['action' => 'metadata.tables', 'database' => 'orphan']), 'A database without a profile was planned.'));
    metaAssert($status === 503 && $code === 'SERVER_PROFILE_NOT_FOUND', 'A missing server profile was not reported.');
    $rejected(['action' => 'metadata.procedures', 'database' => 'Inventory'], 400, 'INVALID_REQUEST');

    // 9-15. Tables.
    [$tables, $tablesEngine, $attempts] = $metadata(['action' => 'metadata.tables', 'database' => 'inventory']);
    metaAssert(str_contains($attempts[0], 'Database=InventoryDB;') && array_column($tables['data'], 'TABLE_NAME') === ['Customer', 'Product', 'Product']
        && $tablesEngine->catalogSql()[0]['sql'] === "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME;",
        'Table metadata did not come from the selected database.');
    DatabaseQueryPlanContext::set($planner->plan(['action' => 'metadata.tables']));
    $companyEngine = new MetaCatalogEngine(null, $logger, 0, true, new DatabaseConnectionManager(static fn (array $c): Database => new MetaDatabase($c)));
    DatabaseQueryPlanContext::clear();
    $catalog = new MetadataRepository($companyEngine);
    $company = $resolver->resolve('company');
    $inventory = $resolver->resolve('inventory');
    $reporting = $resolver->resolve('reporting');
    metaAssert($catalog->tableExists('Customer') && !$catalog->tableExists('Product') && $catalog->objectExists(QualifiedObject::in($inventory, null, 'Product'))
        && !$catalog->objectExists(QualifiedObject::in($reporting, null, 'Product')), 'Table existence did not follow the selected database.');
    metaAssert(end($companyEngine->statements)['sql'] === 'SELECT COUNT(*) AS Total FROM [ReportingDB].INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?'
        && end($companyEngine->statements)['params'] === ['Product'], 'Another database was not read from its own catalog.');
    metaAssert($catalog->objectExists(QualifiedObject::in($company, 'sales', 'Customer')) && !$catalog->objectExists(QualifiedObject::in($company, 'sales', 'Region'))
        && $catalog->objectExists(QualifiedObject::in($inventory, 'sales', 'Product')) && !$catalog->objectExists(QualifiedObject::in($inventory, 'sales', 'Customer')),
        'Schema-specific table lookups failed.');
    foreach ([['schema' => 'sys', 'table' => 'Customer'], ['schema' => 'dbo.sales', 'table' => 'Customer'], ['schema' => "dbo'; DROP", 'table' => 'Customer'],
        ['schema' => 'sales', 'table' => 'sales.Customer'], ['table' => 'sql01.InventoryDB.dbo.Customer'], ['table' => 'InventoryDB.dbo.Customer'],
        ['table' => 'Customer', 'database' => 'inventory'], ['table' => 'Customer', 'server' => 'sql02']] as $source) {
        $rejected(['action' => 'metadata.columns', 'source' => $source], 400, 'INVALID_REQUEST');
    }
    $rejected(['action' => 'metadata.columns', 'database' => 'InventoryDB', 'source' => ['table' => 'Customer']], 400, 'INVALID_REQUEST');
    $rejected(['action' => 'metadata.columns', 'database' => 'inventorydb', 'source' => ['table' => 'Customer']], 404, 'DATABASE_NOT_FOUND');

    // 16-21. Columns: existence, type, listing, and schema, per database.
    metaAssert($catalog->columnExists('Customer', 'Name') && !$catalog->columnExists('Customer', 'Warehouse')
        && $catalog->objectColumnExists(QualifiedObject::in($inventory, null, 'Customer'), 'Warehouse')
        && !$catalog->objectColumnExists(QualifiedObject::in($inventory, null, 'Customer'), 'Name'), 'Column existence did not follow the selected database.');
    metaAssert($catalog->getColumnDataType('Customer', 'Status') === 'varchar'
        && $catalog->objectColumnDataType(QualifiedObject::in($inventory, 'dbo', 'Customer'), 'Status') === 'int'
        && $catalog->objectColumnDataType(QualifiedObject::in($inventory, 'dbo', 'Customer'), 'Missing') === null,
        'Column types were not resolved independently per database.');
    [$schemaColumns, $schemaEngine, $attempts] = $metadata(['action' => 'metadata.columns', 'database' => 'inventory', 'source' => ['schema' => 'sales', 'table' => 'Product']]);
    metaAssert(str_contains($attempts[0], 'Database=InventoryDB;') && $columnNames($schemaColumns) === ['Id', 'Sku']
        && $schemaEngine->catalogSql()[0]['sql'] === 'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        && $schemaEngine->catalogSql()[0]['params'] === ['sales', 'Product'] && array_keys($schemaColumns['data'][0]) === ['COLUMN_NAME', 'DATA_TYPE', 'IS_NULLABLE'],
        'Schema-aware column listing failed or changed shape.');
    [$companySales] = $metadata(['action' => 'metadata.columns', 'source' => ['schema' => 'sales', 'table' => 'Customer']]);
    metaAssert($columnNames($companySales) === ['Id', 'Region'], 'Schema-aware column listing of the default database failed.');
    [$schemaListing, , $attempts] = $metadata(['action' => 'metadata.schema', 'database' => 'reporting']);
    metaAssert(str_contains($attempts[0], 'Database=ReportingDB;') && array_unique(array_column($schemaListing['data'], 'TABLE_NAME')) === ['Sales'],
        'Schema metadata did not come from the selected database.');

    // 22-30. Cross-database SELECT sources are validated against their own databases.
    $select = function (array $request) use ($validator, $normalizer, $planner, $logger): array {
        $validator->validate($request);
        DatabaseQueryPlanContext::set($planner->plan($request));
        MetaSqlServerDriver::$attempts = [];
        $engine = new MetaCatalogEngine(null, $logger, 0, true, new DatabaseConnectionManager(static fn (array $c): Database => new MetaDatabase($c)));
        try {
            (new QueryRepository($engine, null, $logger))->select($normalizer->normalize($request));
        } finally {
            DatabaseQueryPlanContext::clear();
        }
        return [$engine, MetaSqlServerDriver::$attempts];
    };
    $invalid = function (array $request, string $message) use ($select): void {
        $failure = metaFailure(fn () => $select($request), $message);
        metaAssert(preg_match('/^Invalid (?:[A-Za-z ]+ )?(?:column|table)/i', $failure->getMessage()) === 1, "{$message} ({$failure->getMessage()})");
    };
    $join = fn (array $source, string $left, string $right): array => ['type' => 'INNER', 'source' => $source, 'on' => ['left' => $left, 'right' => $right]];
    $threeDatabases = ['action' => 'select', 'database' => 'company', 'source' => ['table' => 'Customer', 'alias' => 'c'], 'fields' => ['c.Name', 'p.Name', 's.Amount'],
        'joins' => [$join(['database' => 'inventory', 'table' => 'Product', 'alias' => 'p'], 'c.ProductId', 'p.Id'),
            $join(['database' => 'reporting', 'table' => 'Sales', 'alias' => 's'], 'c.Id', 's.CustomerId')]];
    [$engine, $attempts] = $select($threeDatabases);
    $catalogText = implode("\n", array_column($engine->catalogSql(), 'sql'));
    metaAssert(count($attempts) === 1 && str_contains($attempts[0], 'Database=CompanyDB;')
        && str_contains($catalogText, 'FROM [InventoryDB].INFORMATION_SCHEMA.') && str_contains($catalogText, 'FROM [ReportingDB].INFORMATION_SCHEMA.')
        && !str_contains($catalogText, '[CompanyDB]') && !str_contains($catalogText, 'Legacy'), 'Sources were not validated against their own catalogs on one connection.');
    metaAssert(end($engine->statements)['sql'] === 'SELECT c.Name, p.Name, s.Amount FROM [CompanyDB]..[Customer] AS [c] INNER JOIN [InventoryDB]..[Product] AS [p] ON c.ProductId = p.Id'
        . ' INNER JOIN [ReportingDB]..[Sales] AS [s] ON c.Id = s.CustomerId ORDER BY [Name] ASC', 'Phase 6 SQL changed.');
    // Each failing variant names a column that exists only in another database.
    $invalid(array_replace($threeDatabases, ['fields' => ['c.Warehouse']]), 'A company source was validated against another database.');
    $invalid(array_replace($threeDatabases, ['fields' => ['p.Status']]), 'An inventory source was validated against the primary database.');
    $invalid(array_replace($threeDatabases, ['fields' => ['s.Name']]), 'A reporting source was validated against the primary database.');
    $invalid(array_replace($threeDatabases, ['joins' => [$join(['database' => 'inventory', 'table' => 'Region', 'alias' => 'p'], 'c.Id', 'p.Id')]]),
        'A table missing from its database was accepted.');
    $sameName = ['action' => 'select', 'source' => ['table' => 'Customer', 'alias' => 'c'], 'fields' => ['c.Name', 'ic.Warehouse'],
        'joins' => [$join(['database' => 'inventory', 'table' => 'Customer', 'alias' => 'ic'], 'c.Id', 'ic.Id')]];
    $select($sameName);
    $invalid(array_replace($sameName, ['fields' => ['ic.Name']]), 'Same-name tables collided (inventory read as company).');
    $invalid(array_replace($sameName, ['fields' => ['c.Warehouse']]), 'Same-name tables collided (company read as inventory).');
    $subquery = ['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id'], 'filters' => [['field' => 'Id', 'operator' => 'IN',
        'query' => ['source' => ['database' => 'reporting', 'table' => 'Sales'], 'fields' => ['CustomerId']]]]];
    $select($subquery);
    $invalid(array_replace_recursive($subquery, ['filters' => [['query' => ['fields' => ['Name']]]]]), 'A subquery source was validated against the primary database.');
    $union = ['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Name']], ['source' => ['database' => 'inventory', 'table' => 'Customer'], 'fields' => ['Warehouse']]]];
    $select($union);
    $invalid(['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Name']], ['source' => ['database' => 'inventory', 'table' => 'Customer'], 'fields' => ['Name']]]],
        'A set-operation branch was validated against the primary database.');
    $cte = ['action' => 'select', 'with' => ['name' => 'Stock', 'query' => ['source' => ['database' => 'inventory', 'schema' => 'sales', 'table' => 'Product'], 'fields' => ['Id', 'Sku']]],
        'source' => ['table' => 'Stock', 'alias' => 'k'], 'fields' => ['k.Sku', 'c.Name'], 'joins' => [$join(['table' => 'Customer', 'alias' => 'c'], 'k.Id', 'c.ProductId')]];
    [$engine] = $select($cte);
    metaAssert(in_array(['sql' => 'SELECT COUNT(*) AS Total FROM [InventoryDB].INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', 'params' => ['sales', 'Product']],
        $engine->catalogSql(), true), 'A CTE source was not validated against its own database and schema.');
    $invalid(array_replace_recursive($cte, ['with' => ['query' => ['fields' => ['Id', 'Name']]]]), 'A CTE source was validated against another schema or database.');

    // 31-40. metadata.databases.
    $validator->validate(['action' => 'metadata.databases']);
    metaAssert($normalizer->normalize(['action' => 'metadata.databases']) === ['controller' => 'Metadata', 'action' => 'databases']
        && method_exists(MetadataController::class, 'databases'), 'metadata.databases is not routed.');
    foreach (['database', 'source', 'server', 'host', 'port', 'user', 'username', 'password', 'connectionString', 'physicalDatabase'] as $field) {
        $rejected(['action' => 'metadata.databases', $field => 'x'], 400, 'INVALID_REQUEST');
    }
    $availability->setAvailable(false, 'reporting');
    MetaSqlServerDriver::$attempts = [];
    $listing = (new DatabaseDirectory(new MetaMetadataOnlyReader($registry), $availability))->databases();
    metaAssert($listing === [
        ['id' => 'archive', 'name' => 'Archive', 'default' => false, 'enabled' => false, 'available' => true, 'crossDatabaseGroup' => 'sql01'],
        ['id' => 'company', 'name' => 'Company', 'default' => true, 'enabled' => true, 'available' => true, 'crossDatabaseGroup' => 'sql01'],
        ['id' => 'inventory', 'name' => 'Inventory', 'default' => false, 'enabled' => true, 'available' => true, 'crossDatabaseGroup' => 'sql01'],
        ['id' => 'legacy', 'name' => 'Legacy', 'default' => false, 'enabled' => true, 'available' => true, 'crossDatabaseGroup' => 'sql02'],
        ['id' => 'offline', 'name' => 'Offline', 'default' => false, 'enabled' => false, 'available' => false, 'crossDatabaseGroup' => 'sql03'],
        ['id' => 'reporting', 'name' => 'Reporting', 'default' => false, 'enabled' => true, 'available' => false, 'crossDatabaseGroup' => 'sql01'],
    ], 'metadata.databases returned the wrong databases: ' . json_encode($listing));
    metaAssert(MetaSqlServerDriver::$attempts === [], 'metadata.databases connected to SQL Server.');
    // The listing needs neither a connection nor the encryption key.
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    $withoutKey = (new DatabaseDirectory(DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json'), $availability))->databases();
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    metaAssert($withoutKey === $listing, 'metadata.databases depended on decryption.');
    $controller = new MetadataController();
    metaAssert((new ReflectionProperty(MetadataController::class, 'metadataService'))->getValue($controller) === null,
        'The metadata controller connects before an action needs it.');
    $directorySource = (string)file_get_contents(dirname(__DIR__) . '/app/Database/DatabaseDirectory.php');
    metaAssert(!preg_match('/QueryEngine|new Database\b|DatabaseConnectionManager|->test\(|serverConnection|databaseCatalog\(/', $directorySource),
        'The database listing can connect or decrypt.');
    $api = (string)file_get_contents(dirname(__DIR__) . '/api/index.php');
    metaAssert(preg_match('/if \(!DatabaseDirectory::isRegistryOnly\(\$publicRequest\[\'action\'\] \?\? null\)\) \{\s+DatabaseQueryPlanContext::set\(\(new DatabaseQueryPlanner\(\)\)/', $api) === 1,
        'metadata.databases is planned against a database.');
    $empty = (new DatabaseDirectory(DatabaseRegistry::forLegacyPath($directory . '/empty/database.json'), $availability))->databases();
    metaAssert($empty === [], 'An unconfigured installation did not list no databases.');

    // 41-50. The listing exposes no infrastructure.
    $envelope = json_encode(Response::successPayload(['rowsReturned' => count($listing), 'data' => $listing], 'Databases Loaded Successfully'));
    $stored = json_decode((string)file_get_contents($directory . '/database/config/databases.json'), true);
    $storedSecrets = [];
    array_walk_recursive($stored, function ($value, $key) use (&$storedSecrets): void {
        if (is_string($value) && strlen($value) >= 12) $storedSecrets[] = $value;
    });
    $hidden = ['CompanyDB', 'InventoryDB', 'ReportingDB', 'ArchiveDB', 'Legacy-Archive', 'OfflineDB', 'sql01.meta.test', 'sql03.meta.test', '10.20.30.41',
        '14330', '14331', 'company_user', 'legacy_user', 'company-secret', 'legacy-secret', 'connectionString', 'password', 'username', '"server"', '"port"',
        'encrypt', 'trustServerCertificate', 'loginTimeout', 'ODBC Driver', 'ciphertext', 'nonce', 'envelope', $key, ...$storedSecrets];
    foreach ($hidden as $value) {
        metaAssert(!str_contains($envelope, $value), 'metadata.databases exposed ' . substr($value, 0, 24) . '.');
    }
    metaAssert(count($storedSecrets) >= 6, 'The registry fixture does not hold encrypted envelopes.');
    metaAssert(array_keys($listing[0]) === ['id', 'name', 'default', 'enabled', 'available', 'crossDatabaseGroup'], 'metadata.databases exposes other fields.');

    // 51-55. Availability.
    $rejected(['action' => 'metadata.tables', 'database' => 'reporting'], 503, 'DATABASE_UNAVAILABLE');
    $middleware = new DatabaseAvailabilityMiddleware(null, $resolver);
    $availability->setAvailable(false, 'company');
    $gate = fn (array $request) => metaResponse(metaFailure(fn () => $middleware->handle($request), 'A closed database passed the gate: ' . json_encode($request)))[1];
    metaAssert($gate(['action' => 'metadata.tables']) === 'DATABASE_UNAVAILABLE' && $gate(['action' => 'metadata.columns', 'database' => 'reporting']) === 'DATABASE_UNAVAILABLE'
        && $gate(['action' => 'select', 'source' => ['table' => 'Customer'], 'fields' => ['Id']]) === 'DATABASE_UNAVAILABLE', 'The availability gate changed.');
    $middleware->handle(['action' => 'metadata.tables', 'database' => 'inventory']);
    $middleware->handle(['action' => 'metadata.databases']);
    $availability->setAvailable(true, 'company');
    // A database closed after planning is rejected before the connection opens.
    DatabaseQueryPlanContext::set($planner->plan(['action' => 'metadata.tables', 'database' => 'inventory']));
    $availability->setAvailable(false, 'inventory');
    MetaSqlServerDriver::$attempts = [];
    [$status, $code] = metaResponse(metaFailure(fn () => new MetaCatalogEngine(null, $logger, 0, true,
        new DatabaseConnectionManager(static fn (array $c): Database => new MetaDatabase($c))), 'A closed database connected.'));
    DatabaseQueryPlanContext::clear();
    $availability->setAvailable(true, 'inventory');
    metaAssert($status === 503 && $code === 'DATABASE_UNAVAILABLE' && MetaSqlServerDriver::$attempts === [], 'A closed database was contacted.');

    // Errors and logs carry no infrastructure.
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    foreach (['company-secret', 'legacy-secret', 'company_user', 'legacy_user', 'sql01.meta.test', '10.20.30.41', 'InventoryDB', 'Legacy-Archive'] as $secret) {
        metaAssert(!str_contains(implode("\n", $payloads), $secret), "An error exposed {$secret}.");
        metaAssert(!str_contains($logText, $secret), "A log exposed {$secret}.");
    }

    echo "Database metadata tests passed.\n";
} finally {
    DatabaseQueryPlanContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    metaRemoveDirectory($directory);
}
