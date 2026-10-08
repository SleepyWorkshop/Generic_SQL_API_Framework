<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Database/MssqlIdentifier.php';
require_once __DIR__ . '/../app/Database/QualifiedObject.php';
require_once __DIR__ . '/../app/Database/QuerySource.php';
require_once __DIR__ . '/../app/Database/SourceResolver.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../app/Repositories/SqlRepository.php';
require_once __DIR__ . '/../app/Repositories/Write/WriteSqlBuilder.php';
require_once __DIR__ . '/../app/Repositories/Write/InsertBuilder.php';
require_once __DIR__ . '/../app/Repositories/Query/SqlExpressionBuilder.php';
require_once __DIR__ . '/../app/Resources/DatabaseObjectName.php';
require_once __DIR__ . '/../app/Resources/SqlResourceStatement.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 5: SQL Server identifiers, qualified physical objects, and source
 * resolution. No SQL Server is required; SQL is recorded, not executed.
 */

function sourceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function sourceFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code, first detail path, encoded payload] of the API response an exception produces. */
function sourceResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, $payload['error']['details'][0]['path'] ?? null, json_encode($payload)];
}

function sourceRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** Records SQL instead of executing it; optionally reports the connected database. */
final class SourceRecordingEngine extends QueryEngine
{
    public array $statements = [];

    public function __construct(?string $databaseContextId = null) { $this->databaseContextId = $databaseContextId; }

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->statements[] = ['sql' => trim((string)preg_replace('/\s+/', ' ', $sql)), 'params' => $params];
        if (str_contains($sql, 'compatibility_level')) return ['data' => [['CompatibilityLevel' => 150]]];
        if (str_contains($sql, 'COUNT(*) AS TotalRows')) return ['data' => [['TotalRows' => 4]]];
        if (str_contains($sql, 'COUNT(*) AS Total')) return ['data' => [['Total' => 1]]];
        if (str_contains($sql, 'DATA_TYPE FROM')) return ['data' => [['DATA_TYPE' => 'int']]];
        return ['executionTime' => 0, 'rowsReturned' => 1, 'data' => [['Id' => 1]]];
    }

    public function executePreparedQuery($sql, array $params = [], array $context = []) { return $this->executePrepared($sql, $params, $context); }

    /** Data statements only, without metadata lookups. */
    public function dataSql(): array
    {
        return array_values(array_map(fn (array $statement): string => $statement['sql'], array_filter($this->statements,
            fn (array $statement): bool => !str_contains($statement['sql'], 'INFORMATION_SCHEMA') && !str_contains($statement['sql'], 'compatibility_level'))));
    }
}

/** Name lookups always succeed; structured lookups are recorded. */
final class SourceMetadata extends MetadataRepository
{
    public array $objects = [];

    public function __construct() {}
    public function tableExists($table) { return true; }
    public function columnExists($table, $column) { return true; }
    public function getColumnDataType($table, $column) { return 'varchar'; }
    public function getColumns($table) { return ['data' => [['COLUMN_NAME' => 'Id'], ['COLUMN_NAME' => 'Name']]]; }
    public function objectExists(QualifiedObject $object): bool { $this->objects[] = $object->__debugInfo(); return true; }
    public function objectColumnExists(QualifiedObject $object, $column): bool { $this->objects[] = $object->__debugInfo() + ['column' => $column]; return true; }
    public function objectColumnDataType(QualifiedObject $object, $column) { return 'varchar'; }
    public function objectColumns(QualifiedObject $object) { return $this->getColumns($object->objectName()); }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-source-resolution-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$secrets = ['company-secret', 'legacy-secret', 'company_user', 'legacy_user', 'sql01.source.test', 'sql02.source.test'];

try {
    foreach (['config', 'database/config', 'logs', 'operational'] as $path) mkdir($directory . '/' . $path, 0700, true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.source.test',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => 'company-secret', 'options' => ['encrypt' => true]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, [...$sql01, 'server' => 'sql02.source.test', 'username' => 'legacy_user', 'password' => 'legacy-secret']);
    $registry->saveDatabase('company', 'Company', 'sql01', true, 'CompanyDB');
    $registry->saveDatabase('inventory', 'Inventory', 'sql01', true, 'InventoryDB');
    $registry->saveDatabase('reporting', 'Reporting', 'sql01', true, 'ReportingDB');
    // SQL Server catalogs may contain punctuation; delimiting keeps it one part.
    $registry->saveDatabase('sales', 'Sales', 'sql01', true, 'Sales.2024]Q');
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $registry->saveDatabase('legacy', 'Legacy', 'sql02', true, 'LegacyDB');
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'reporting', 'sales', 'legacy'] as $id) $availability->setAvailable(true, $id);
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver);
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $payloads = [];
    $invalid = function (array $request, string $path) use ($validator, &$payloads): void {
        [$status, $code, $actualPath, $payload] = sourceResponse(sourceFailure(fn () => $validator->validate($request), 'Request was accepted: ' . json_encode($request)));
        sourceAssert($status === 400 && $code === 'INVALID_REQUEST' && $actualPath === $path, "Expected INVALID_REQUEST at {$path}; got {$code} at {$actualPath}.");
        $payloads[] = $payload;
    };
    $select = fn (array $source, array $extra = []): array => array_replace(['action' => 'select', 'source' => $source, 'fields' => ['Id']], $extra);
    $join = fn (array $source): array => ['type' => 'INNER', 'source' => $source, 'on' => ['left' => 'Id', 'right' => 'Id']];

    // 1-8. MssqlIdentifier.
    sourceAssert(MssqlIdentifier::object('Customer')->quoted() === '[Customer]' && MssqlIdentifier::database('company')->quoted() === '[company]'
        && MssqlIdentifier::column('ProductID')->quoted() === '[ProductID]' && MssqlIdentifier::alias('p')->quoted() === '[p]',
        'Identifiers were not quoted.');
    sourceAssert(MssqlIdentifier::object('Order Details')->quoted() === '[Order Details]', 'An identifier with spaces was not quoted.');
    sourceAssert(MssqlIdentifier::object('A]B')->quoted() === '[A]]B]' && MssqlIdentifier::database('X]]')->quoted() === '[X]]]]]',
        'A closing bracket was not escaped.');
    $kinds = [MssqlIdentifier::DATABASE, MssqlIdentifier::SCHEMA, MssqlIdentifier::OBJECT, MssqlIdentifier::COLUMN, MssqlIdentifier::ALIAS];
    $clientKinds = [MssqlIdentifier::SCHEMA, MssqlIdentifier::OBJECT, MssqlIdentifier::COLUMN, MssqlIdentifier::ALIAS];
    foreach ($kinds as $kind) {
        foreach (['', ' Customer', 'Customer ', str_repeat('a', 129), "Line\nBreak", "Nul\0", 'Customer; DROP TABLE x', 'a--b', 'a/*b', 'a*/b', null, 7] as $value) {
            sourceAssert(!MssqlIdentifier::isValid($kind, $value), "The {$kind} identifier " . json_encode($value) . ' was accepted.');
        }
    }
    foreach ($clientKinds as $kind) {
        foreach (['dbo.Customer', 'company.dbo.customer', "Customer) UNION SELECT * FROM x", "x'y", 'a"b', 'a(b', 'a,b', 'a=b', '[x'] as $value) {
            sourceAssert(!MssqlIdentifier::isValid($kind, $value), "The {$kind} identifier " . json_encode($value) . ' was accepted.');
        }
    }
    sourceAssert(!MssqlIdentifier::isValid('raw', 'Customer') && !DatabaseRegistry::isValidId('company.dbo.customer'),
        'An unknown identifier kind or a dotted database id was accepted.');
    $identifierFailure = sourceFailure(fn () => MssqlIdentifier::object('Customer; DROP TABLE x'), 'A SQL fragment became an identifier.');
    sourceAssert($identifierFailure instanceof InvalidArgumentException && !str_contains($identifierFailure->getMessage(), 'DROP'),
        'An identifier error repeated its value.');

    // 9-12. QualifiedObject.
    $company = $resolver->resolve('company');
    $inventory = $resolver->resolve('inventory');
    $product = QualifiedObject::in($inventory, 'dbo', 'Product');
    sourceAssert($product->render() === '[InventoryDB].[dbo].[Product]' && $product->renderLocal() === '[dbo].[Product]'
        && $product->databaseId === 'inventory' && $product->isIn($inventory) && !$product->isIn($company), 'A qualified object rendered incorrectly.');
    $sales = QualifiedObject::in($resolver->resolve('sales'), 'sales', 'Order Details');
    sourceAssert($sales->render() === '[Sales.2024]]Q].[sales].[Order Details]' && substr_count($product->render(), '].[') === 2,
        'Parts were not quoted independently.');
    sourceAssert(QualifiedObject::in($inventory, null, 'Product')->render() === '[InventoryDB]..[Product]', 'A schema-less object rendered incorrectly.');
    foreach ([['dbo', 'CompanyDB.dbo.Customer'], ['dbo.sales', 'Customer'], ['dbo', 'srv.CompanyDB.dbo.Customer'], ['dbo', 'Customer]; DROP TABLE x']] as [$schema, $object]) {
        sourceFailure(fn () => QualifiedObject::in($inventory, $schema, $object), "A multi-part name became a qualified object: {$schema} {$object}");
    }
    $objectClass = new ReflectionClass(QualifiedObject::class);
    sourceAssert($objectClass->getConstructor()->isPrivate() && !$objectClass->hasProperty('server') && !$objectClass->hasProperty('alias')
        && !$objectClass->hasProperty('sql'), 'QualifiedObject can hold a server, alias, or raw SQL.');
    sourceAssert(sourceFailure(fn () => serialize($product), 'A qualified object was serialized.') instanceof LogicException
        && !str_contains(print_r($product, true), 'InventoryDB'), 'A qualified object exposed its physical database.');

    // 13-20. SourceResolver within a plan.
    $crossRequest = $select(['table' => 'Product'], ['database' => 'inventory', 'joins' => [$join(['database' => 'company', 'table' => 'Customer'])]]);
    $validator->validate($crossRequest);
    $crossPlan = $planner->plan($crossRequest);
    $sources = new SourceResolver($crossPlan);
    $explicit = $sources->resolve(['database' => 'company', 'table' => 'Customer']);
    sourceAssert($explicit->object->databaseId === 'company' && $explicit->object->render() === '[CompanyDB]..[Customer]',
        'An explicit source database was not used.');
    $fallback = $sources->resolve(['table' => 'Product']);
    sourceAssert($fallback->object->databaseId === 'inventory' && $fallback->object->databaseName() === $registry->databaseCatalog('inventory'),
        'The primary database was not the fallback, or the physical name did not come from the registry.');
    $schemaSource = $sources->resolve(['schema' => 'sales', 'table' => 'Customer']);
    sourceAssert($schemaSource->object->render() === '[InventoryDB].[sales].[Customer]', 'An explicit schema was not used.');
    sourceAssert($fallback->object->schemaName() === null && $fallback->renderFrom() === 'Product'
        && DatabaseObjectName::parse('Customer', 'table', 'INVALID_WRITE_TABLE', 'x')['schema'] === 'dbo',
        'Default schema behavior changed: SELECT sources keep SQL Server resolution; writes and routines keep dbo.');
    foreach (['reporting', 'missing', 'archive', 'InventoryDB'] as $id) {
        sourceAssert(sourceFailure(fn () => $sources->resolve(['database' => $id, 'table' => 'X']), "Source database {$id} was resolved outside the plan.") instanceof LogicException,
            "Source database {$id} did not require the plan.");
    }
    foreach ([['missing', 'DATABASE_NOT_FOUND'], ['archive', 'DATABASE_DISABLED']] as [$id, $expected]) {
        $request = $select(['database' => $id, 'table' => 'X']);
        [, $code, , $payload] = sourceResponse(sourceFailure(fn () => $planner->plan($request), "Database {$id} was planned."));
        sourceAssert($code === $expected, "Database {$id} returned {$code}.");
        $payloads[] = $payload;
    }
    foreach ([['schema' => 'sys', 'table' => 'Customer'], ['schema' => 'dbo.x', 'table' => 'Customer'], ['table' => 'dbo.Customer'], ['table' => 'Customer', 'alias' => 'p.q']] as $source) {
        [$status, $code, , $payload] = sourceResponse(sourceFailure(fn () => $sources->resolve($source), 'An invalid source resolved: ' . json_encode($source)));
        sourceAssert($status === 400 && $code === 'INVALID_REQUEST', 'An invalid source was not a validation error.');
        $payloads[] = $payload;
    }

    // 21-23. Aliases stay separate from physical objects.
    $aliased = $sources->resolve(['database' => 'inventory', 'schema' => 'dbo', 'table' => 'Product', 'alias' => 'p']);
    sourceAssert($aliased->alias === 'p' && $aliased->reference() === 'p' && $aliased->object->render() === '[InventoryDB].[dbo].[Product]'
        && !in_array('p', $aliased->object->__debugInfo(), true), 'The alias leaked into the qualified object.');
    sourceAssert($aliased->renderFrom(true) === '[InventoryDB].[dbo].[Product] AS [p]' && $aliased->renderFrom() === '[dbo].[Product] p'
        && $sources->resolve(['table' => 'Product', 'alias' => 'p'])->renderFrom() === 'Product p', 'A FROM source rendered incorrectly.');
    $expressions = new SqlExpressionBuilder();
    $expressions->registerTable('p', 'Product');
    sourceAssert($aliased->column('ProductID') === '[p].[ProductID]' && $expressions->resolveColumn('p.ProductID') === ['table' => 'Product', 'column' => 'ProductID']
        && sourceFailure(fn () => $aliased->column('p.ProductID'), 'A dotted column became an identifier.') instanceof InvalidArgumentException,
        'Column references and object identifiers were confused.');

    // 24-30. Existing queries render exactly as before, with and without a plan.
    $run = function (array $request, ?DatabaseQueryPlan $plan, ?SourceMetadata $metadata = null) use ($validator, $normalizer, $directory): array {
        $validator->validate($request);
        $normalized = $normalizer->normalize($request);
        $engine = new SourceRecordingEngine();
        $repository = new QueryRepository($engine, $metadata ?? new SourceMetadata(), new Logger($directory . '/logs'), null,
            $plan === null ? null : new SourceResolver($plan));
        $repository->select($normalized);
        return $engine->dataSql();
    };
    $expected = [
        'select' => [$select(['table' => 'Customer'], ['fields' => ['Id', 'Name']]), 'SELECT Id, Name FROM Customer ORDER BY [Id] ASC'],
        'join' => [['action' => 'select', 'source' => ['table' => 'Customer', 'alias' => 'C'], 'fields' => ['C.Id', 'O.Total'],
            'joins' => [['type' => 'LEFT', 'source' => ['table' => 'Orders', 'alias' => 'O'], 'on' => ['left' => 'C.Id', 'right' => 'O.CustomerId']]]],
            'SELECT C.Id, O.Total FROM Customer C LEFT JOIN Orders O ON C.Id = O.CustomerId ORDER BY [Id] ASC'],
        'filters' => [$select(['table' => 'Customer'], ['filters' => [['field' => 'Name', 'operator' => 'LIKE', 'value' => 'A%'],
            ['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Orders', 'alias' => 'O'], 'fields' => ['O.CustomerId']]]]]),
            'SELECT Id FROM Customer WHERE Name LIKE ? AND Id IN ( SELECT O.CustomerId FROM Orders O ) ORDER BY [Id] ASC'],
        'group' => [['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => ['CustomerId', ['function' => 'SUM', 'field' => 'Total', 'alias' => 'Amount']],
            'groupBy' => ['CustomerId'], 'having' => [['function' => 'SUM', 'field' => 'Total', 'operator' => '>', 'value' => 5]]],
            'SELECT CustomerId, SUM(Total) AS [Amount] FROM Orders GROUP BY CustomerId HAVING SUM(Total) > ? ORDER BY CustomerId'],
        'sort' => [$select(['table' => 'Customer'], ['sort' => [['field' => 'Name', 'direction' => 'DESC']]]), 'SELECT Id FROM Customer ORDER BY Name DESC'],
        'cte' => [['action' => 'select', 'with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Orders'], 'fields' => ['Id', 'CustomerId']]],
            'source' => ['table' => 'Recent', 'alias' => 'R'], 'fields' => ['R.Id'], 'joins' => [['type' => 'INNER', 'source' => ['table' => 'Customer', 'alias' => 'C'],
            'on' => ['left' => 'R.CustomerId', 'right' => 'C.Id']]]],
            'WITH Recent AS ( SELECT Id, CustomerId FROM Orders ) SELECT R.Id FROM Recent R INNER JOIN Customer C ON R.CustomerId = C.Id ORDER BY [Id] ASC'],
        'union' => [['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Customer'], 'fields' => ['Id']], ['source' => ['table' => 'Archive', 'alias' => 'A'], 'fields' => ['A.Id']]]],
            'SELECT Id FROM Customer UNION ALL SELECT A.Id FROM Archive A'],
    ];
    foreach ($expected as $name => [$request, $sql]) {
        $plan = $planner->plan($request);
        $without = $name === 'union' ? null : $run($request, null);
        if ($name === 'union') {
            $validator->validate($request);
            $engine = new SourceRecordingEngine();
            DatabaseQueryPlanContext::set($plan);
            (new QueryRepository($engine, new SourceMetadata(), new Logger($directory . '/logs')))->select($normalizer->normalize($request));
            DatabaseQueryPlanContext::clear();
            $with = $engine->dataSql();
        } else {
            $with = $run($request, $plan);
            sourceAssert($with === $without, "The {$name} query rendered differently with a database plan.");
        }
        sourceAssert(str_starts_with($with[0], $sql), "The {$name} query changed: {$with[0]}");
        sourceAssert(!str_contains(implode(' ', $with), 'CompanyDB') && !str_contains(implode(' ', $with), '].['), "The {$name} query gained qualified identifiers.");
    }
    // A non-default primary database renders the same SQL on its own connection.
    $inventoryRequest = $select(['table' => 'Customer'], ['database' => 'inventory', 'fields' => ['Id', 'Name']]);
    sourceAssert($run($inventoryRequest, $planner->plan($inventoryRequest))[0] === $expected['select'][1], 'A non-default primary database changed the SQL.');
    // Schema-qualified sources render delimited parts and use schema-aware metadata.
    $metadata = new SourceMetadata();
    $schemaRequest = $select(['schema' => 'sales', 'table' => 'Customer', 'alias' => 'C'], ['fields' => ['C.Id'],
        'joins' => [$join(['schema' => 'dbo', 'table' => 'Orders', 'alias' => 'O']), $join(['table' => 'Region'])]]);
    $schemaSql = $run($schemaRequest, $planner->plan($schemaRequest), $metadata)[0];
    sourceAssert(str_contains($schemaSql, 'FROM [sales].[Customer] C INNER JOIN [dbo].[Orders] O ON Id = Id INNER JOIN Region ON')
        && !str_contains($schemaSql, 'CompanyDB'), "A schema-qualified source rendered incorrectly: {$schemaSql}");
    sourceAssert(in_array(['database' => 'company', 'schema' => 'sales', 'object' => 'Customer'], $metadata->objects, true)
        && in_array(['database' => 'company', 'schema' => 'dbo', 'object' => 'Orders'], $metadata->objects, true),
        'Schema-qualified sources were not checked by object.');
    $conflict = $select(['schema' => 'sales', 'table' => 'Customer'], ['joins' => [$join(['schema' => 'dbo', 'table' => 'Customer', 'alias' => 'D'])]]);
    $payloads[] = sourceResponse(sourceFailure(fn () => $run($conflict, $planner->plan($conflict)), 'Two objects with one table name were accepted.'))[3];
    sourceAssert(sourceFailure(fn () => $run($schemaRequest, null), 'A schema source was built without a plan.') instanceof LogicException,
        'A schema source was built without a database plan.');
    // Builders never render another database: cross-database plans stay unexecuted.
    [$status, $code] = sourceResponse(sourceFailure(fn () => $run($crossRequest, $crossPlan), 'A cross-database query was built.'));
    sourceAssert($status === 501 && $code === 'CROSS_DATABASE_EXECUTION_NOT_SUPPORTED', 'The builder accepted a cross-database plan.');
    DatabaseQueryPlanContext::set($crossPlan);
    [$status, $code] = sourceResponse(sourceFailure(fn () => new QueryEngine(null, new Logger($directory . '/logs')), 'A cross-database plan connected.'));
    DatabaseQueryPlanContext::clear();
    sourceAssert($status === 501 && $code === 'CROSS_DATABASE_EXECUTION_NOT_SUPPORTED', 'The engine accepted a cross-database plan.');
    $writeSql = (new InsertBuilder())->build(['schema' => 'dbo', 'table' => 'Customer', 'identityColumn' => null], ['Name' => 'A'])['sql'];
    sourceAssert(str_contains($writeSql, 'INSERT INTO [dbo].[Customer] ([Name])')
        && (new RoutineBuilder())->buildProcedure(['schema' => 'dbo', 'name' => 'RunReport'], [1])['sql'] === 'EXEC [dbo].[RunReport] ?',
        'Write or routine identifiers changed.');

    // Metadata: structured lookups are bound, and only for the connected database.
    $engine = new SourceRecordingEngine('company');
    $catalog = new MetadataRepository($engine);
    sourceAssert($catalog->objectExists(QualifiedObject::in($company, 'sales', 'Customer'))
        && $catalog->objectColumnDataType(QualifiedObject::in($company, 'sales', 'Customer'), 'Name') === 'int', 'Structured metadata lookups failed.');
    sourceAssert($engine->statements[0]['params'] === ['sales', 'Customer'] && str_contains($engine->statements[0]['sql'], 'TABLE_SCHEMA = ? AND TABLE_NAME = ?')
        && !str_contains($engine->statements[0]['sql'], 'Customer'), 'Structured metadata lookups interpolated identifiers.');
    sourceAssert(sourceFailure(fn () => $catalog->objectExists(QualifiedObject::in($inventory, 'dbo', 'Product')), 'Another database was looked up.') instanceof LogicException,
        'Metadata of another database was read through this connection.');

    // SQL Resource parsing keeps every name part.
    $statement = SqlResourceStatement::analyze('SELECT o.Id, c.Name FROM OtherDB.dbo.Orders o JOIN dbo.Customer c ON c.Id = o.CustomerId JOIN Region r ON r.Id = c.RegionId');
    $parsed = array_map(fn (array $source): array => [$source['database'], $source['schema'], $source['table'], $source['qualifier']], $statement->topLevelSources());
    sourceAssert($parsed === [['OtherDB', 'dbo', 'Orders', 'o'], [null, 'dbo', 'Customer', 'c'], [null, null, 'Region', 'r']],
        'A multi-part SQL Resource source lost its parts.');
    $sqlRepository = (new ReflectionClass(SqlRepository::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(SqlRepository::class, 'metadataRepository'))->setValue($sqlRepository, new SourceMetadata());
    $resolveSourceColumn = new ReflectionMethod(SqlRepository::class, 'resolveSourceColumn');
    sourceAssert($resolveSourceColumn->invoke($sqlRepository, $statement, 'Total', false) === null
        && $resolveSourceColumn->invoke($sqlRepository, SqlResourceStatement::analyze('SELECT c.Total FROM dbo.Customer c'), 'Total', false)['table'] === 'Customer',
        'A database-qualified SQL Resource source was resolved against the connected database.');
    foreach (['SELECT Id FROM srv.CompanyDB.dbo.Customer', 'SELECT Id FROM dbo.A a JOIN srv.Db.dbo.B b ON a.Id = b.Id',
        "SELECT * FROM OPENQUERY(srv, 'SELECT 1')", "SELECT x FROM (SELECT * FROM openrowset('SQLNCLI', 'x', 'SELECT 1') r) q",
        "SELECT Id FROM dbo.T WHERE Id IN (SELECT Id FROM OPENDATASOURCE('SQLNCLI', 'x').Db.dbo.T)"] as $sql) {
        sourceAssert(sourceFailure(fn () => SqlResourceStatement::analyze($sql), "A remote SQL Resource source was accepted: {$sql}") instanceof RuntimeException,
            'A linked-server or remote rowset source was accepted.');
    }
    SqlResourceStatement::analyze("SELECT [openquery], 'OPENROWSET' AS Note FROM dbo.T -- OPENQUERY");

    // 31-35. Security of the request API.
    $invalid($select(['table' => 'Customer'], ['database' => 'CompanyDB']), 'database');
    foreach (['server', 'host', 'connectionString', 'password'] as $key) {
        $invalid($select(['table' => 'Customer'], [$key => 'x']), $key);
        $invalid($select(['table' => 'Customer', $key => 'x']), "source.{$key}");
    }
    foreach (['server.database.dbo.Customer', 'CompanyDB.dbo.Customer', 'dbo.Customer', 'Customer; DROP TABLE x', 'Customer--', '[Customer]'] as $table) {
        $invalid($select(['table' => $table]), 'source.table');
        $invalid($select(['table' => 'Customer'], ['joins' => [$join(['table' => $table])]]), 'joins.0.source.table');
    }
    foreach (['dbo]; DROP TABLE x', 'sys', 'INFORMATION_SCHEMA', 'dbo.sales', ''] as $schema) {
        $invalid($select(['table' => 'Customer', 'schema' => $schema]), 'source.schema');
    }
    $invalid($select(['table' => 'Customer', 'alias' => 'c.x']), 'source.alias');
    $cteSchema = $select(['table' => 'Recent', 'schema' => 'dbo'], ['with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Orders'], 'fields' => ['Id']]]]);
    $validator->validate($cteSchema);
    [$status, $code, $path] = sourceResponse(sourceFailure(fn () => $planner->plan($cteSchema), 'A CTE reference with a schema was planned.'));
    sourceAssert($status === 400 && $code === 'INVALID_REQUEST' && $path === 'source.schema', 'A CTE reference accepted a schema.');
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    $debug = print_r([$product, $aliased, $crossPlan], true);
    foreach ($secrets as $secret) {
        sourceAssert(!str_contains(implode("\n", $payloads), $secret), "An error exposed {$secret}.");
        sourceAssert(!str_contains($logText, $secret), "A log exposed {$secret}.");
        sourceAssert(!str_contains($debug, $secret), "Debug output exposed {$secret}.");
    }
    foreach (['CompanyDB', 'InventoryDB', 'Sales.2024'] as $physical) {
        sourceAssert(!str_contains(implode("\n", $payloads), $physical) && !str_contains($debug, $physical), "A physical database name was exposed: {$physical}");
    }

    echo "Source resolution tests passed.\n";
} finally {
    DatabaseQueryPlanContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    sourceRemoveDirectory($directory);
}
