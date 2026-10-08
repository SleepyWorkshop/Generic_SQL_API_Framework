<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 6: same-server-profile cross-database SELECT.
 *
 * These are unit and SQL-generation tests. The end-to-end cases run the real
 * planner, builders, QueryEngine, and DatabaseConnectionManager, and replace
 * only the ODBC calls with recorders; they do not claim SQL Server executed
 * anything. Executing cross-database SQL against SQL Server requires an
 * integration environment with two databases on one instance.
 */

function crossAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function crossFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code, first detail path, encoded payload] of the API response an exception produces. */
function crossResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, $payload['error']['details'][0]['path'] ?? null, json_encode($payload)];
}

function crossRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** Canned catalog and data rows for recorded statements. */
function crossRows(string $sql): array
{
    return match (true) {
        str_contains($sql, 'compatibility_level') => [['CompatibilityLevel' => 150]],
        str_contains($sql, 'COUNT(*) AS TotalRows') => [['TotalRows' => 4]],
        str_contains($sql, 'COUNT(*) AS Total') => [['Total' => 1]],
        str_contains($sql, 'COLUMN_NAME, DATA_TYPE') => [['COLUMN_NAME' => 'Id'], ['COLUMN_NAME' => 'Name']],
        str_contains($sql, 'DATA_TYPE FROM') => [['DATA_TYPE' => 'int']],
        default => [['Id' => 1, 'Name' => 'Row']],
    };
}

/** Records connection attempts instead of opening ODBC connections. */
final class CrossSqlServerDriver extends SqlServerDriver
{
    public static array $attempts = [];

    protected function openConnection(string $dsn, string $authentication, string $username, string $password)
    {
        self::$attempts[] = $dsn;
        return 'connection-' . count(self::$attempts);
    }

    public function disconnect() {}
}

final class CrossDatabase extends Database
{
    private CrossSqlServerDriver $crossDriver;

    public function __construct(array $configuration)
    {
        $this->crossDriver = new CrossSqlServerDriver($configuration, false);
        $this->crossDriver->connect();
    }

    public function getConnection() { return $this->crossDriver->getConnection(); }
    public function close() {}
}

/** The real QueryEngine with its ODBC statement calls recorded. */
final class CrossRecordingEngine extends QueryEngine
{
    public array $statements = [];
    public ?string $failure = null;

    protected function prepareStatement(string $sql)
    {
        if ($this->failure !== null) return false;
        $this->statements[] = ['connection' => $this->connection, 'sql' => trim((string)preg_replace('/\s+/', ' ', $sql))];
        return new ArrayObject(['rows' => crossRows($sql)]);
    }
    protected function executeStatement($statement, array $params): bool
    {
        $this->statements[count($this->statements) - 1]['params'] = $params;
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
    protected function lastError(): string { return (string)$this->failure; }
    protected function lastSqlState(): string { return $this->failure === null ? '' : '42000'; }

    public function dataSql(): array
    {
        return array_values(array_map(fn (array $statement): string => $statement['sql'], array_filter($this->statements,
            fn (array $statement): bool => !str_contains($statement['sql'], 'INFORMATION_SCHEMA') && !str_contains($statement['sql'], 'compatibility_level'))));
    }
}

/** Name lookups always succeed; structured lookups succeed and are recorded. */
final class CrossMetadata extends MetadataRepository
{
    public array $objects = [];

    public function __construct() {}
    public function tableExists($table) { return true; }
    public function columnExists($table, $column) { return true; }
    public function getColumnDataType($table, $column) { return 'varchar'; }
    public function getColumns($table) { return ['data' => [['COLUMN_NAME' => 'Id'], ['COLUMN_NAME' => 'Name']]]; }
    public function objectExists(QualifiedObject $object): bool { $this->objects[] = $object->__debugInfo(); return true; }
    public function objectColumnExists(QualifiedObject $object, $column): bool { return true; }
    public function objectColumnDataType(QualifiedObject $object, $column) { return 'varchar'; }
    public function objectColumns(QualifiedObject $object) { return $this->getColumns($object->objectName()); }
}

/** Records SQL for builder-only checks. */
final class CrossBuilderEngine extends QueryEngine
{
    public array $statements = [];

    public function __construct() {}
    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->statements[] = ['sql' => trim((string)preg_replace('/\s+/', ' ', $sql)), 'params' => $params];
        return ['executionTime' => 0, 'rowsReturned' => 1, 'data' => crossRows($sql)];
    }
    public function executePreparedQuery($sql, array $params = [], array $context = []) { return $this->executePrepared($sql, $params, $context); }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-cross-database-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$secrets = ['company-secret', 'legacy-secret', 'company_user', 'legacy_user', 'sql01.cross.test', 'sql02.cross.test'];

try {
    foreach (['config', 'database/config', 'logs', 'operational'] as $path) mkdir($directory . '/' . $path, 0700, true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    // sql01 hosts company (default), inventory, reporting, archive (disabled), and
    // offline (gate closed); sql02 hosts legacy.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.cross.test',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => 'company-secret', 'options' => ['encrypt' => true]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, [...$sql01, 'server' => 'sql02.cross.test', 'username' => 'legacy_user', 'password' => 'legacy-secret']);
    foreach (['company' => 'CompanyDB', 'inventory' => 'InventoryDB', 'reporting' => 'ReportingDB', 'offline' => 'OfflineDB'] as $id => $physical) {
        $registry->saveDatabase($id, ucfirst($id), 'sql01', true, $physical);
    }
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $registry->saveDatabase('legacy', 'Legacy', 'sql02', true, 'LegacyDB');
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'reporting', 'legacy'] as $id) $availability->setAvailable(true, $id);
    $planner = new DatabaseQueryPlanner(new DatabaseContextResolver($registry, $availability));
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $logger = new Logger($directory . '/logs');
    $payloads = [];

    $select = fn (array $source, array $extra = []): array => array_replace(['action' => 'select', 'source' => $source, 'fields' => ['Id']], $extra);
    $join = fn (array $source, string $left = 'Id', string $right = 'Id', string $type = 'INNER'): array
        => ['type' => $type, 'source' => $source, 'on' => ['left' => $left, 'right' => $right]];
    // Builder-level SQL for a request under its own plan.
    $build = function (array $request, ?CrossMetadata $metadata = null) use ($validator, $normalizer, $planner, $logger): array {
        $validator->validate($request);
        $plan = $planner->plan($request);
        $engine = new CrossBuilderEngine();
        $repository = new QueryRepository($engine, $metadata ?? new CrossMetadata(), $logger, null, new SourceResolver($plan));
        $repository->select($normalizer->normalize($request));
        return array_values(array_filter($engine->statements, fn (array $statement): bool => !str_contains($statement['sql'], 'compatibility_level')));
    };
    $sql = fn (array $request): string => $build($request)[0]['sql'];

    $customerProduct = $select(['database' => 'company', 'table' => 'Customer', 'alias' => 'c'], ['database' => 'company', 'fields' => ['c.Id', 'p.Name'],
        'joins' => [$join(['database' => 'inventory', 'table' => 'Product', 'alias' => 'p'], 'c.ProductId', 'p.Id')]]);
    $customerProductSql = 'SELECT c.Id, p.Name FROM [CompanyDB]..[Customer] AS [c] INNER JOIN [InventoryDB]..[Product] AS [p] ON c.ProductId = p.Id ORDER BY [Id] ASC';

    // 1-7. Basic cross-database SELECT.
    crossAssert($sql($customerProduct) === $customerProductSql, 'A same-profile cross-database JOIN was not generated.');
    $twoDatabases = $select(['table' => 'Customer'], ['joins' => [$join(['database' => 'inventory', 'table' => 'Product'], 'Customer.Id', 'Product.CustomerId')]]);
    crossAssert($sql($twoDatabases) === 'SELECT Id FROM [CompanyDB]..[Customer] AS [Customer] INNER JOIN [InventoryDB]..[Product] AS [Product] ON Customer.Id = Product.CustomerId ORDER BY [Id] ASC',
        'Unaliased sources were not addressed by their names.');
    $explicitPrimary = $select(['table' => 'Product', 'alias' => 'p'], ['database' => 'inventory', 'fields' => ['p.Id'],
        'joins' => [$join(['database' => 'company', 'table' => 'Customer', 'alias' => 'c'], 'p.CustomerId', 'c.Id')]]);
    crossAssert($sql($explicitPrimary) === 'SELECT p.Id FROM [InventoryDB]..[Product] AS [p] INNER JOIN [CompanyDB]..[Customer] AS [c] ON p.CustomerId = c.Id ORDER BY [Id] ASC',
        'A source without a database did not use the explicit primary database.');
    $baseSource = $select(['database' => 'reporting', 'table' => 'Sales', 'alias' => 's'], ['fields' => ['s.Id'],
        'joins' => [$join(['database' => 'company', 'table' => 'Customer', 'alias' => 'c'], 's.CustomerId', 'c.Id')]]);
    crossAssert($planner->plan($baseSource)->primaryDatabase->id === 'reporting'
        && str_contains($sql($baseSource), 'FROM [ReportingDB]..[Sales] AS [s] INNER JOIN [CompanyDB]..[Customer] AS [c]'), 'The base source database was not used.');
    $schemaSource = $select(['database' => 'company', 'schema' => 'sales', 'table' => 'Customer', 'alias' => 'c'], ['fields' => ['c.Id'],
        'joins' => [$join(['database' => 'inventory', 'schema' => 'dbo', 'table' => 'Product', 'alias' => 'p'], 'c.Id', 'p.CustomerId')]]);
    crossAssert(str_contains($sql($schemaSource), 'FROM [CompanyDB].[sales].[Customer] AS [c] INNER JOIN [InventoryDB].[dbo].[Product] AS [p] ON c.Id = p.CustomerId'),
        'A schema-qualified cross-database source was not generated.');
    $aliasSql = $sql($customerProduct);
    crossAssert(!preg_match('/\[(?:CompanyDB|InventoryDB)\][^ ]*\]\.\[?(?:Id|Name|ProductId)/', $aliasSql) && str_contains($aliasSql, 'c.Id, p.Name')
        && str_contains($aliasSql, 'ON c.ProductId = p.Id'), 'Column references were not alias-based.');

    // 8-14. Filters keep bound values.
    $filtered = $build(array_replace($customerProduct, ['filterLogic' => 'OR', 'filters' => [
        ['field' => 'p.Status', 'operator' => '=', 'value' => 'ACTIVE'],
        ['field' => 'c.Name', 'operator' => 'LIKE', 'value' => 'A%'],
        ['field' => 'c.Id', 'operator' => 'BETWEEN', 'value' => [1, 5]],
        ['field' => 'p.Deleted', 'operator' => 'IS NULL'],
        ['field' => 'c.Region', 'operator' => 'IN', 'value' => ['N', 'S']],
        ['field' => 'p.Code', 'operator' => 'IS NOT NULL'],
    ]]))[0];
    crossAssert($filtered['sql'] === 'SELECT c.Id, p.Name FROM [CompanyDB]..[Customer] AS [c] INNER JOIN [InventoryDB]..[Product] AS [p] ON c.ProductId = p.Id'
        . ' WHERE p.Status = ? OR c.Name LIKE ? OR c.Id BETWEEN ? AND ? OR p.Deleted IS NULL OR c.Region IN (?, ?) OR p.Code IS NOT NULL ORDER BY [Id] ASC'
        && $filtered['params'] === ['ACTIVE', 'A%', 1, 5, 'N', 'S'], 'Cross-database filters were not generated with bound values.');
    $andFilters = $build(array_replace($customerProduct, ['filters' => [['field' => 'p.Status', 'operator' => '=', 'value' => 'ACTIVE'], ['field' => 'c.Id', 'operator' => '>', 'value' => 3]]]))[0];
    crossAssert(str_contains($andFilters['sql'], 'WHERE p.Status = ? AND c.Id > ?') && $andFilters['params'] === ['ACTIVE', 3], 'AND filters were not generated.');
    crossAssert(!str_contains($filtered['sql'] . $andFilters['sql'], 'ACTIVE') && !str_contains($filtered['sql'], "'"), 'A filter value was inlined into SQL.');

    // 15-18. JOIN types, conditions, and several databases.
    $joinTypes = $select(['table' => 'Customer', 'alias' => 'c'], ['fields' => ['c.Id'], 'joins' => [
        $join(['database' => 'inventory', 'table' => 'Product', 'alias' => 'p'], 'c.Id', 'p.CustomerId', 'LEFT'),
        $join(['database' => 'reporting', 'table' => 'Sales', 'alias' => 's'], 'p.Id', 's.ProductId', 'RIGHT'),
        $join(['table' => 'Region', 'alias' => 'r'], 'c.RegionId', 'r.Id', 'INNER'),
    ]]);
    crossAssert($sql($joinTypes) === 'SELECT c.Id FROM [CompanyDB]..[Customer] AS [c] LEFT JOIN [InventoryDB]..[Product] AS [p] ON c.Id = p.CustomerId'
        . ' RIGHT JOIN [ReportingDB]..[Sales] AS [s] ON p.Id = s.ProductId INNER JOIN [CompanyDB]..[Region] AS [r] ON c.RegionId = r.Id ORDER BY [Id] ASC'
        && $planner->plan($joinTypes)->databaseIds() === ['company', 'inventory', 'reporting'], 'Several cross-database JOIN types were not generated.');
    $payloads[] = crossResponse(crossFailure(fn () => $validator->validate($select(['table' => 'Customer'], ['joins' => [
        ['type' => 'FULL', 'source' => ['database' => 'inventory', 'table' => 'Product'], 'on' => ['left' => 'Id', 'right' => 'Id']]]])), 'An unsupported JOIN type was accepted.'))[3];
    // Same table name in two databases: distinct aliases are required, as SQL Server requires distinct exposed names.
    crossAssert(str_contains($sql($select(['table' => 'Customer', 'alias' => 'c'], ['fields' => ['c.Id', 'ic.Id'],
        'joins' => [$join(['database' => 'inventory', 'table' => 'Customer', 'alias' => 'ic'], 'c.Id', 'ic.Id')]])),
        'FROM [CompanyDB]..[Customer] AS [c] INNER JOIN [InventoryDB]..[Customer] AS [ic] ON c.Id = ic.Id'), 'Aliased same-name sources were not generated.');
    [$status, $code, $path] = crossResponse(crossFailure(fn () => $build($select(['table' => 'Customer'], ['joins' => [$join(['database' => 'inventory', 'table' => 'Customer'])]])),
        'Two unaliased same-name sources were accepted.'));
    crossAssert($status === 400 && $code === 'INVALID_REQUEST' && $path === 'joins.0.source', 'Ambiguous same-name sources were not rejected.');

    // 19-21. Subqueries resolve through the one plan; correlation is unchanged.
    $subquery = $select(['table' => 'Customer', 'alias' => 'c'], ['fields' => ['c.Id'], 'filters' => [['field' => 'c.Id', 'operator' => 'IN', 'query' => [
        'source' => ['database' => 'inventory', 'table' => 'Orders', 'alias' => 'o'], 'fields' => ['o.CustomerId'],
        'filters' => [['operator' => 'EXISTS', 'query' => ['source' => ['database' => 'reporting', 'table' => 'Flags'], 'fields' => ['Id']]], ['field' => 'o.Total', 'operator' => '>', 'value' => 10]],
    ]]]]);
    $subqueryStatement = $build($subquery)[0];
    crossAssert($subqueryStatement['sql'] === 'SELECT c.Id FROM [CompanyDB]..[Customer] AS [c] WHERE c.Id IN ( SELECT o.CustomerId FROM [InventoryDB]..[Orders] AS [o]'
        . ' WHERE EXISTS ( SELECT Id FROM [ReportingDB]..[Flags] AS [Flags] ) AND o.Total > ?) ORDER BY [Id] ASC' && $subqueryStatement['params'] === [10]
        && $planner->plan($subquery)->databaseIds() === ['company', 'inventory', 'reporting'], 'Nested cross-database subqueries were not generated.');
    // V2 subqueries cannot reference outer aliases; cross-database subqueries keep that rule.
    $correlated = $select(['table' => 'Customer', 'alias' => 'c'], ['fields' => ['c.Id'], 'filters' => [['operator' => 'EXISTS', 'query' => [
        'source' => ['database' => 'inventory', 'table' => 'Orders', 'alias' => 'o'], 'fields' => ['o.Id'], 'filters' => [['field' => 'c.Id', 'operator' => '=', 'value' => 1]]]]]]);
    $v2Correlated = $correlated;
    unset($v2Correlated['filters'][0]['query']['source']['database']);
    foreach ([$correlated, $v2Correlated] as $request) {
        $failure = crossFailure(fn () => $build($request), 'An outer alias was resolved inside a subquery.');
        crossAssert(str_contains($failure->getMessage(), 'Unknown table alias'), 'Correlated subquery behavior changed.');
    }

    // 22-23. CTE branches hold the physical sources; the CTE stays a logical source.
    $cte = ['action' => 'select', 'with' => ['name' => 'Recent', 'query' => ['source' => ['database' => 'inventory', 'table' => 'Orders', 'alias' => 'o'], 'fields' => ['o.Id', 'o.CustomerId']]],
        'source' => ['table' => 'Recent', 'alias' => 'r'], 'fields' => ['r.Id', 'c.Name'], 'joins' => [$join(['table' => 'Customer', 'alias' => 'c'], 'r.CustomerId', 'c.Id')]];
    crossAssert($sql($cte) === 'WITH Recent AS ( SELECT o.Id, o.CustomerId FROM [InventoryDB]..[Orders] AS [o] ) SELECT r.Id, c.Name FROM Recent r INNER JOIN [CompanyDB]..[Customer] AS [c] ON r.CustomerId = c.Id ORDER BY [Id] ASC',
        'A cross-database CTE was not generated.');
    $recursive = ['action' => 'select', 'with' => ['name' => 'Tree', 'anchor' => ['source' => ['database' => 'inventory', 'table' => 'Nodes'], 'fields' => ['Id', 'ParentId']],
        'recursive' => ['source' => ['database' => 'inventory', 'table' => 'Nodes', 'alias' => 'n'], 'fields' => ['n.Id', 'n.ParentId'], 'joins' => [$join(['table' => 'Tree', 'alias' => 't'], 'n.ParentId', 't.Id')]]],
        'source' => ['table' => 'Tree'], 'fields' => ['Id']];
    crossAssert($sql($recursive) === 'WITH Tree AS ( SELECT Id, ParentId FROM [InventoryDB]..[Nodes] AS [Nodes] UNION ALL SELECT n.Id, n.ParentId FROM [InventoryDB]..[Nodes] AS [n] INNER JOIN Tree t ON n.ParentId = t.Id ) SELECT Id FROM Tree ORDER BY [Id] ASC',
        'A cross-database recursive CTE was not generated.');
    $payloads[] = crossResponse(crossFailure(fn () => $planner->plan(array_replace($cte, ['source' => ['table' => 'Recent', 'database' => 'inventory']])),
        'A CTE reference named a database.'))[3];

    // 24-25. UNION and UNION ALL branches share the plan.
    $union = ['action' => 'union', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']],
        ['source' => ['database' => 'inventory', 'table' => 'CustomerArchive', 'alias' => 'a'], 'fields' => ['a.Id'], 'filters' => [['field' => 'a.Id', 'operator' => '>', 'value' => 3]]]]];
    $unionStatement = $build($union)[0] ?? null;
    $unionAll = array_replace($union, ['action' => 'unionAll']);
    $unionAllStatement = $build($unionAll)[0] ?? null;
    crossAssert(($unionStatement['sql'] ?? '') === 'SELECT Id FROM [CompanyDB]..[Customer] AS [Customer] UNION SELECT a.Id FROM [InventoryDB]..[CustomerArchive] AS [a] WHERE a.Id > ?'
        && ($unionAllStatement['sql'] ?? '') === 'SELECT Id FROM [CompanyDB]..[Customer] AS [Customer] UNION ALL SELECT a.Id FROM [InventoryDB]..[CustomerArchive] AS [a] WHERE a.Id > ?'
        && $unionAllStatement['params'] === [3], 'A cross-database set operation was not generated.');

    // 26-30. Aggregation and windows.
    $grouped = $build($select(['database' => 'inventory', 'table' => 'Sales', 'alias' => 's'], ['fields' => ['c.Id', ['function' => 'SUM', 'field' => 's.Amount', 'alias' => 'Total'],
        ['function' => 'COUNT', 'field' => '*', 'alias' => 'N'], ['function' => 'AVG', 'field' => 's.Amount', 'alias' => 'Mean'], ['function' => 'MIN', 'field' => 's.Amount', 'alias' => 'Low'],
        ['function' => 'MAX', 'field' => 's.Amount', 'alias' => 'High']],
        'joins' => [$join(['database' => 'company', 'table' => 'Customer', 'alias' => 'c'], 's.CustomerId', 'c.Id')],
        'groupBy' => ['c.Id'], 'having' => [['function' => 'SUM', 'field' => 's.Amount', 'operator' => '>', 'value' => 100]], 'sort' => [['field' => 'Total', 'direction' => 'DESC']]]))[0];
    crossAssert($grouped['sql'] === 'SELECT c.Id, SUM(s.Amount) AS [Total], COUNT(*) AS [N], AVG(s.Amount) AS [Mean], MIN(s.Amount) AS [Low], MAX(s.Amount) AS [High]'
        . ' FROM [InventoryDB]..[Sales] AS [s] INNER JOIN [CompanyDB]..[Customer] AS [c] ON s.CustomerId = c.Id GROUP BY c.Id HAVING SUM(s.Amount) > ? ORDER BY [Total] DESC'
        && $grouped['params'] === [100], 'Cross-database aggregation was not generated.');
    $window = $build(array_replace($customerProduct, ['fields' => ['c.Id', ['function' => 'ROW_NUMBER', 'alias' => 'Rn', 'partitionBy' => ['c.Id'],
        'sort' => [['field' => 'p.CreatedAt', 'direction' => 'DESC']]]], 'sort' => [['field' => 'c.Name']], 'pagination' => ['page' => 2, 'pageSize' => 5]]));
    crossAssert($window[0]['sql'] === 'SELECT COUNT(*) AS TotalRows FROM ( SELECT c.Id, ROW_NUMBER() OVER (PARTITION BY c.Id ORDER BY p.CreatedAt DESC) AS [Rn]'
        . ' FROM [CompanyDB]..[Customer] AS [c] INNER JOIN [InventoryDB]..[Product] AS [p] ON c.ProductId = p.Id ) AS CountQuery'
        && $window[1]['sql'] === 'SELECT c.Id, ROW_NUMBER() OVER (PARTITION BY c.Id ORDER BY p.CreatedAt DESC) AS [Rn] FROM [CompanyDB]..[Customer] AS [c]'
        . ' INNER JOIN [InventoryDB]..[Product] AS [p] ON c.ProductId = p.Id ORDER BY c.Name ASC OFFSET 5 ROWS FETCH NEXT 5 ROWS ONLY',
        'A cross-database window query, ORDER BY, or pagination was not generated.');

    // 31-32. ORDER BY and pagination.
    crossAssert(str_ends_with($sql(array_replace($customerProduct, ['sort' => [['field' => 'p.CreatedAt', 'direction' => 'DESC'], ['field' => 'c.Name']]])),
        'ORDER BY p.CreatedAt DESC, c.Name ASC'), 'Cross-database ORDER BY was not generated.');
    $cteWindow = $build(array_replace($cte, ['pagination' => ['page' => 1, 'pageSize' => 10]]));
    crossAssert(str_starts_with($cteWindow[0]['sql'], 'WITH Recent AS ( SELECT o.Id, o.CustomerId FROM [InventoryDB]..[Orders] AS [o] ) SELECT COUNT(*) AS TotalRows')
        && str_ends_with($cteWindow[1]['sql'], 'OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY'), 'Cross-database CTE pagination was not generated.');

    // Metadata of each source comes from its own database, through the plan.
    $metadata = new CrossMetadata();
    $build($schemaSource, $metadata);
    crossAssert(in_array(['database' => 'company', 'schema' => 'sales', 'object' => 'Customer'], $metadata->objects, true)
        && in_array(['database' => 'inventory', 'schema' => 'dbo', 'object' => 'Product'], $metadata->objects, true), 'Source metadata was not checked by object.');

    // 33-37. Security.
    foreach ([
        [$select(['table' => 'Customer'], ['joins' => [$join(['database' => 'InventoryDB', 'table' => 'Product'])]]), 'INVALID_REQUEST', 'joins.0.source.database'],
        [$select(['table' => 'Customer'], ['joins' => [$join(['database' => 'inventorydb', 'table' => 'Product'])]]), 'DATABASE_NOT_FOUND', 'joins.0.source.database'],
        [$select(['table' => 'sql01.InventoryDB.dbo.Product']), 'INVALID_REQUEST', 'source.table'],
        [$select(['table' => 'InventoryDB.dbo.Product']), 'INVALID_REQUEST', 'source.table'],
        [$select(['table' => 'Customer'], ['joins' => [$join(['table' => 'InventoryDB.dbo.Product'])]]), 'INVALID_REQUEST', 'joins.0.source.table'],
        [$select(['table' => 'Customer', 'server' => 'sql02.cross.test']), 'INVALID_REQUEST', 'source.server'],
        [$select(['table' => 'Customer'], ['server' => 'sql02.cross.test']), 'INVALID_REQUEST', 'server'],
        [$select(['table' => 'Customer'], ['joins' => [$join(['table' => 'Product', 'database' => 'inventory', 'connectionString' => 'x'])]]), 'INVALID_REQUEST', 'joins.0.source.connectionString'],
        [$select(['table' => 'Customer; DROP TABLE x']), 'INVALID_REQUEST', 'source.table'],
    ] as [$request, $expectedCode, $expectedPath]) {
        [, $code, $path, $payload] = crossResponse(crossFailure(function () use ($validator, $planner, $request): void {
            $validator->validate($request);
            $planner->plan($request);
        }, 'An unsafe request was accepted: ' . json_encode($request)));
        crossAssert($code === $expectedCode && $path === $expectedPath, "Expected {$expectedCode} at {$expectedPath}; got {$code} at {$path}.");
        $payloads[] = $payload;
    }

    // 38-40. End to end: one plan, one connection to the primary database, one statement.
    $connections = new DatabaseConnectionManager(static fn (array $configuration): Database => new CrossDatabase($configuration));
    $request = array_replace($customerProduct, ['filters' => [['field' => 'p.Status', 'operator' => '=', 'value' => 'ACTIVE']]]);
    $validator->validate($request);
    DatabaseQueryPlanContext::set($planner->plan($request));
    CrossSqlServerDriver::$attempts = [];
    $engine = new CrossRecordingEngine(null, $logger, 0, true, $connections);
    $result = (new QueryRepository($engine, null, $logger))->select($normalizer->normalize($request));
    crossAssert(count(CrossSqlServerDriver::$attempts) === 1 && str_contains(CrossSqlServerDriver::$attempts[0], 'Server=sql01.cross.test;')
        && str_contains(CrossSqlServerDriver::$attempts[0], 'Database=CompanyDB;') && $connections->openConnectionCount() === 1,
        'A cross-database request did not use one connection to its primary database.');
    crossAssert(array_unique(array_column($engine->statements, 'connection')) === ['connection-1'], 'A statement ran on another connection.');
    crossAssert($engine->dataSql() === ['SELECT c.Id, p.Name FROM [CompanyDB]..[Customer] AS [c] INNER JOIN [InventoryDB]..[Product] AS [p] ON c.ProductId = p.Id WHERE p.Status = ? ORDER BY [Id] ASC']
        && $result['data'] === [['Id' => 1, 'Name' => 'Row']], 'The cross-database SELECT was not one statement whose rows are returned as is.');
    $catalogSql = implode("\n", array_column($engine->statements, 'sql'));
    crossAssert(str_contains($catalogSql, 'FROM [InventoryDB].INFORMATION_SCHEMA.') && preg_match('/FROM INFORMATION_SCHEMA\.(?:TABLES|COLUMNS) WHERE TABLE_NAME = \?/', $catalogSql) === 1,
        'Source metadata did not come from each database on the one connection.');
    // A set operation over two databases is one statement on the same connection: no PHP-side merging.
    $validator->validate($unionAll);
    DatabaseQueryPlanContext::set($planner->plan($unionAll));
    $engine->statements = [];
    $unionResult = (new QueryRepository($engine, null, $logger))->select($normalizer->normalize($unionAll));
    crossAssert($engine->dataSql() === [$unionAllStatement['sql']] && array_unique(array_column($engine->statements, 'connection')) === ['connection-1']
        && count(CrossSqlServerDriver::$attempts) === 1 && $unionResult['data'] === [['Id' => 1, 'Name' => 'Row']],
        'A cross-database set operation was split, merged in PHP, or run on another connection.');
    $engine->close();

    // Collation conflicts are reported as COLLATION_CONFLICT, without driver text.
    $engine = new CrossRecordingEngine(null, $logger, 0, false);
    $engine->failure = "[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Cannot resolve the collation conflict between \"Latin1_General_CI_AS\" and \"SQL_Latin1_General_CP1_CI_AS\" in the equal to operation. Server=sql01.cross.test;UID=company_user";
    [$status, $code, , $payload] = crossResponse(crossFailure(fn () => $engine->executePrepared('SELECT 1', [], ['queryPhase' => 'data']), 'A collation conflict succeeded.'));
    crossAssert($status === 422 && $code === 'COLLATION_CONFLICT' && !str_contains($payload, 'Latin1') && !str_contains($payload, 'ODBC'), 'A collation conflict was not mapped safely.');
    $payloads[] = $payload;
    $engine->failure = '[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name.';
    crossAssert(!(crossFailure(fn () => $engine->executePrepared('SELECT 1', [], ['queryPhase' => 'data']), 'A failure succeeded.') instanceof ApiRequestException),
        'Other SQL errors were reported as collation conflicts.');

    // 41-42. Cross-server requests are rejected before any connection.
    CrossSqlServerDriver::$attempts = [];
    DatabaseQueryPlanContext::clear();
    foreach ([
        $select(['table' => 'Customer'], ['joins' => [$join(['database' => 'legacy', 'table' => 'Old'])]]),
        ['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']], ['source' => ['database' => 'legacy', 'table' => 'Old'], 'fields' => ['Id']]]],
        $select(['table' => 'Customer'], ['filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['database' => 'legacy', 'table' => 'Old'], 'fields' => ['Id']]]]]),
    ] as $request) {
        $validator->validate($request);
        [$status, $code, , $payload] = crossResponse(crossFailure(fn () => $planner->plan($request), 'A cross-server request was planned.'));
        crossAssert($status === 400 && $code === 'CROSS_SERVER_QUERY_NOT_SUPPORTED', 'A cross-server request was not rejected.');
        $payloads[] = $payload;
    }
    crossAssert(CrossSqlServerDriver::$attempts === [] && DatabaseQueryPlanContext::current() === null, 'A cross-server rejection opened a connection.');

    // 43-44. Every referenced database must be enabled and available.
    foreach ([['archive', 403, 'DATABASE_DISABLED'], ['offline', 503, 'DATABASE_UNAVAILABLE']] as [$id, $expectedStatus, $expectedCode]) {
        $request = $select(['table' => 'Customer'], ['joins' => [$join(['database' => $id, 'table' => 'X'])]]);
        [$status, $code, , $payload] = crossResponse(crossFailure(fn () => $planner->plan($request), "Database {$id} was planned."));
        crossAssert($status === $expectedStatus && $code === $expectedCode, "Referenced database {$id} returned {$status} {$code}.");
        $payloads[] = $payload;
    }
    // A database closed after planning is still checked when the connection opens.
    DatabaseQueryPlanContext::set($planner->plan($customerProduct));
    $availability->setAvailable(false, 'inventory');
    [$status, $code] = crossResponse(crossFailure(fn () => new CrossRecordingEngine(null, $logger, 0, true,
        new DatabaseConnectionManager(static fn (array $configuration): Database => new CrossDatabase($configuration))), 'A closed referenced database connected.'));
    crossAssert($status === 503 && $code === 'DATABASE_UNAVAILABLE' && CrossSqlServerDriver::$attempts === [], 'A closed referenced database was not rejected before connecting.');
    $availability->setAvailable(true, 'inventory');
    DatabaseQueryPlanContext::clear();

    // 45-50. Requests without other databases render exactly as before.
    $v2 = [
        [$select(['table' => 'Customer'], ['fields' => ['Id', 'Name']]), 'SELECT Id, Name FROM Customer ORDER BY [Id] ASC'],
        [$select(['table' => 'Customer', 'alias' => 'C'], ['fields' => ['C.Id', 'O.Total'], 'joins' => [$join(['table' => 'Orders', 'alias' => 'O'], 'C.Id', 'O.CustomerId', 'LEFT')]]),
            'SELECT C.Id, O.Total FROM Customer C LEFT JOIN Orders O ON C.Id = O.CustomerId ORDER BY [Id] ASC'],
        [$select(['table' => 'Customer'], ['filters' => [['field' => 'Name', 'operator' => 'LIKE', 'value' => 'A%'], ['field' => 'Id', 'operator' => 'BETWEEN', 'value' => [1, 9]]], 'filterLogic' => 'OR']),
            'SELECT Id FROM Customer WHERE Name LIKE ? OR Id BETWEEN ? AND ? ORDER BY [Id] ASC'],
        [['action' => 'select', 'with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Orders'], 'fields' => ['Id']]], 'source' => ['table' => 'Recent'], 'fields' => ['Id']],
            'WITH Recent AS ( SELECT Id FROM Orders ) SELECT Id FROM Recent ORDER BY [Id] ASC'],
        [['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']], ['source' => ['table' => 'Archive'], 'fields' => ['Id']]]],
            'SELECT Id FROM Customer UNION ALL SELECT Id FROM Archive'],
        [$select(['table' => 'Customer'], ['database' => 'inventory', 'fields' => ['Id', 'Name']]), 'SELECT Id, Name FROM Customer ORDER BY [Id] ASC'],
        [$select(['table' => 'Customer', 'database' => 'company', 'schema' => 'sales']), 'SELECT Id FROM [sales].[Customer] ORDER BY [Id] ASC'],
    ];
    foreach ($v2 as [$request, $expected]) {
        $plan = $planner->plan($request);
        crossAssert(!$plan->isCrossDatabase && $sql($request) === $expected, 'A single-database query changed: ' . $sql($request));
    }

    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    crossAssert(str_contains($logText, '["company","inventory"]'), 'The planned databases were not logged.');
    foreach ($secrets as $secret) {
        crossAssert(!str_contains(implode("\n", $payloads), $secret), "An error exposed {$secret}.");
        crossAssert(!str_contains($logText, $secret), "A log exposed {$secret}.");
    }

    echo "Cross-database SELECT tests passed.\n";
} finally {
    DatabaseQueryPlanContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    crossRemoveDirectory($directory);
}
