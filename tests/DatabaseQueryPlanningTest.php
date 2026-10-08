<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../app/Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Database/AllowEnabledDatabasesPolicy.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Requests/WriteRequestValidator.php';
require_once __DIR__ . '/../app/Resources/RoutineResolver.php';
require_once __DIR__ . '/../app/Authorization/Principal.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';
require_once __DIR__ . '/../core/QueryEngine.php';

/*
 * V3 Phase 4: database references, collection, resolution, access policy, and
 * query planning. No SQL Server is required; connections are recorded.
 */

function planAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function planFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code, first detail path, encoded payload] of the API response an exception produces. */
function planResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, $payload['error']['details'][0]['path'] ?? null, json_encode($payload)];
}

function planRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** Records connection attempts instead of opening ODBC connections. */
final class PlanningSqlServerDriver extends SqlServerDriver
{
    public static array $attempts = [];

    protected function openConnection(string $dsn, string $authentication, string $username, string $password)
    {
        self::$attempts[] = $dsn;
        return 'recorded-connection';
    }

    public function disconnect()
    {
    }
}

final class PlanningDatabase extends Database
{
    private PlanningSqlServerDriver $planningDriver;

    public function __construct(array $configuration)
    {
        $this->planningDriver = new PlanningSqlServerDriver($configuration, false);
        $this->planningDriver->connect();
    }

    public function getConnection() { return $this->planningDriver->getConnection(); }
    public function close() {}
}

/** A future-style policy: records what it is asked and denies one database. */
final class RecordingDenyPolicy implements DatabaseAccessPolicy
{
    public array $seen = [];

    public function __construct(private string $denied) {}

    public function assertAllowed(DatabaseContext $database, ?Principal $principal): void
    {
        $this->seen[] = [$database->id, $principal?->username];
        if ($database->id === $this->denied) {
            throw new ApiRequestException('Database access denied.', 'DATABASE_ACCESS_DENIED', [['path' => 'database', 'message' => 'Denied.']], 403);
        }
    }
}

/** A registry reader whose second database references a missing server profile. */
final class MissingProfileRegistryReader implements DatabaseRegistryReader
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

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-database-planning-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$secrets = ['company-secret', 'legacy-secret', 'company_user', 'legacy_user', 'sql01.plan.test', 'sql02.plan.test',
    'CompanyDB', 'InventoryDB', 'ReportingDB', 'LegacyDB', 'ArchiveDB'];

try {
    foreach (['config', 'database/config', 'logs', 'operational'] as $path) mkdir($directory . '/' . $path, 0700, true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    // Topology: sql01 hosts company (default), inventory, reporting, archive
    // (disabled); sql02 hosts legacy; sql01-alt is the sql01 host under another
    // login; sql03 is a disabled profile.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.plan.test', 'port' => '1433',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => 'company-secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false]];
    $sql02 = [...$sql01, 'server' => 'sql02.plan.test', 'username' => 'legacy_user', 'password' => 'legacy-secret'];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, $sql02);
    $registry->saveServer('sql01-alt', 'SQL Server 01 (other login)', true, [...$sql01, 'username' => 'other_user']);
    $registry->saveServer('sql03', 'SQL Server 03', false, [...$sql02, 'server' => 'sql03.plan.test']);
    $registry->saveDatabase('company', 'Company', 'sql01', true, 'CompanyDB');
    $registry->saveDatabase('inventory', 'Inventory', 'sql01', true, 'InventoryDB');
    $registry->saveDatabase('reporting', 'Reporting', 'sql01', true, 'ReportingDB');
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $registry->saveDatabase('legacy', 'Legacy', 'sql02', true, 'LegacyDB');
    $registry->saveDatabase('alt', 'Alternate login', 'sql01-alt', true, 'ReportingDB');
    $registry->saveDatabase('offline', 'Offline', 'sql03', true, 'OfflineDB');
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'inventory', 'reporting', 'legacy', 'alt'] as $id) $availability->setAvailable(true, $id);
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver);
    $collector = new DatabaseReferenceCollector();
    $validator = new QueryRequestValidator();
    $plan = function (array $request) use ($validator, $planner): DatabaseQueryPlan {
        $validator->validate($request);
        return $planner->plan($request);
    };
    $collect = function (array $request) use ($validator, $collector): array {
        $validator->validate($request);
        return $collector->collect($request)->ids();
    };
    $rejected = function (array $request, int $status, string $code, ?string $path = null) use ($plan): string {
        $label = json_encode($request);
        [$actualStatus, $actualCode, $actualPath, $payload] = planResponse(planFailure(fn () => $plan($request), "Request was planned: {$label}"));
        planAssert($actualStatus === $status && $actualCode === $code && ($path === null || $actualPath === $path),
            "Expected {$status} {$code} at {$path} for {$label}; got {$actualStatus} {$actualCode} at {$actualPath}.");
        return $payload;
    };
    $payloads = [];

    $select = fn (array $source, array $extra = []): array => ['action' => 'select', 'source' => $source, 'fields' => ['Id']] + $extra;
    $join = fn (array $source): array => ['type' => 'INNER', 'source' => $source, 'on' => ['left' => 'Id', 'right' => 'Id']];

    // 1-4. Primary database: top-level, then base source, then default.
    $v2 = $plan($select(['table' => 'Customer']));
    planAssert($v2->primaryDatabase->id === 'company' && $v2->databaseIds() === ['company'], 'No database did not resolve to the default.');
    $explicit = $plan($select(['table' => 'Product'], ['database' => 'inventory']));
    planAssert($explicit->primaryDatabase->id === 'inventory' && $explicit->databaseIds() === ['inventory'], 'The top-level database was not primary.');
    $baseSource = $plan($select(['database' => 'inventory', 'table' => 'Product']));
    planAssert($baseSource->primaryDatabase->id === 'inventory' && !$baseSource->isCrossDatabase, 'The base source database was not primary.');
    $precedence = $plan($select(['database' => 'inventory', 'table' => 'Product'], ['database' => 'company']));
    planAssert($precedence->primaryDatabase->id === 'company' && $precedence->databaseIds() === ['company', 'inventory'],
        'The top-level database did not take precedence over the base source.');
    $unionBase = $plan(['action' => 'union', 'queries' => [
        ['source' => ['database' => 'reporting', 'table' => 'A'], 'fields' => ['Id']],
        ['source' => ['database' => 'inventory', 'table' => 'B'], 'fields' => ['Id']],
    ]]);
    planAssert($unionBase->primaryDatabase->id === 'reporting' && $unionBase->databaseIds() === ['reporting', 'inventory'],
        'The first set-operation branch is not the base source.');

    // 5-10. Collection: every source position, logical ids only, de-duplicated.
    planAssert($collect($select(['database' => 'inventory', 'table' => 'Product'])) === ['inventory'], 'The base source database was not collected.');
    planAssert($collect($select(['table' => 'Customer'], ['joins' => [$join(['database' => 'inventory', 'table' => 'Product'])]])) === ['inventory'],
        'A JOIN database was not collected.');
    planAssert($collect($select(['table' => 'Customer'], ['filters' => [['field' => 'Id', 'operator' => 'IN',
        'query' => ['source' => ['database' => 'reporting', 'table' => 'Sales'], 'fields' => ['Id']]]]])) === ['reporting'],
        'A subquery database was not collected.');
    planAssert($collect($select(['table' => 'Customer'], ['filters' => [['operator' => 'EXISTS',
        'query' => ['source' => ['table' => 'Sales'], 'fields' => ['Id'], 'joins' => [$join(['database' => 'inventory', 'table' => 'Product'])]]]]])) === ['inventory'],
        'A database joined inside a subquery was not collected.');
    planAssert($collect($select(['table' => 'Recent'], ['with' => ['name' => 'Recent',
        'query' => ['source' => ['database' => 'reporting', 'table' => 'Orders'], 'fields' => ['Id']]]])) === ['reporting'],
        'A CTE database was not collected.');
    planAssert($collect($select(['table' => 'Tree'], ['with' => ['name' => 'Tree',
        'anchor' => ['source' => ['database' => 'inventory', 'table' => 'Nodes'], 'fields' => ['Id']],
        'recursive' => ['source' => ['table' => 'Tree'], 'fields' => ['Id']]]])) === ['inventory'],
        'A recursive CTE database was not collected.');
    planAssert($collect(['action' => 'unionAll', 'database' => 'company', 'queries' => [
        ['source' => ['table' => 'A'], 'fields' => ['Id']],
        ['source' => ['database' => 'inventory', 'table' => 'B'], 'fields' => ['Id']],
    ]]) === ['company', 'inventory'], 'A set-operation database was not collected.');
    $duplicates = $select(['database' => 'company', 'table' => 'Customer'], ['database' => 'company', 'joins' => [
        $join(['database' => 'inventory', 'table' => 'Product']), $join(['database' => 'inventory', 'table' => 'Stock']), $join(['table' => 'Orders']),
    ]]);
    planAssert($collect($duplicates) === ['company', 'inventory'] && $plan($duplicates)->databaseIds() === ['company', 'inventory'],
        'Duplicate references were not normalized.');
    $collected = $collector->collect($duplicates);
    planAssert($collected->requestDatabase->path === 'database' && $collected->baseSourceDatabase->path === 'source.database'
        && $collected->references[1]->path === 'joins.0.source.database', 'References do not record where they were named.');
    planAssert(DatabaseReferenceCollector::declaredPrimaryId($select(['database' => 'inventory', 'table' => 'P'])) === 'inventory'
        && DatabaseReferenceCollector::declaredPrimaryId(['action' => 'sql', 'database' => 'inventory']) === null
        && DatabaseReferenceCollector::declaredPrimaryId(['action' => 'union', 'queries' => [['source' => ['database' => 'reporting']]]]) === 'reporting',
        'The early availability gate does not see the selected database.');
    $payloads[] = $rejected($select(['table' => 'Recent'], ['with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Orders'], 'fields' => ['Id']]],
        'joins' => [$join(['database' => 'inventory', 'table' => 'recent'])]]), 400, 'INVALID_REQUEST', 'joins.0.source.database');
    // Collection is structural: it never reads the registry.
    $offlineRegistry = DatabaseRegistry::forLegacyPath($directory . '/missing/database.json');
    planAssert((new DatabaseReferenceCollector())->collect($duplicates)->ids() === ['company', 'inventory'] && $offlineRegistry->defaultDatabaseId() === null,
        'Collection depended on configuration.');

    // 11-14. Resolution errors report where the database was named.
    $payloads[] = $rejected($select(['table' => 'Customer'], ['joins' => [$join(['database' => 'missing', 'table' => 'X'])]]), 404, 'DATABASE_NOT_FOUND', 'joins.0.source.database');
    $payloads[] = $rejected($select(['table' => 'Customer'], ['database' => 'archive']), 403, 'DATABASE_DISABLED', 'database');
    $payloads[] = $rejected($select(['database' => 'offline', 'table' => 'Customer']), 403, 'SERVER_PROFILE_DISABLED', 'source.database');
    $orphanPlanner = new DatabaseQueryPlanner(new DatabaseContextResolver(new MissingProfileRegistryReader($registry), $availability));
    [$status, $code] = planResponse(planFailure(fn () => $orphanPlanner->plan($select(['table' => 'X'], ['joins' => [$join(['database' => 'orphan', 'table' => 'Y'])]])),
        'A database with a missing server profile was planned.'));
    planAssert($status === 503 && $code === 'SERVER_PROFILE_NOT_FOUND', 'A missing server profile was not reported.');
    $availability->setAvailable(false, 'reporting');
    $payloads[] = $rejected($select(['table' => 'Customer'], ['joins' => [$join(['database' => 'reporting', 'table' => 'X'])]]), 503, 'DATABASE_UNAVAILABLE');
    $availability->setAvailable(true, 'reporting');
    $sameProfile = $plan($select(['database' => 'inventory', 'table' => 'Product'], ['joins' => [$join(['database' => 'reporting', 'table' => 'Sales'])]]));
    planAssert($sameProfile->databaseIds() === ['inventory', 'reporting'] && $sameProfile->serverProfileId === 'sql01',
        'Several databases on one profile were not planned together.');

    // 15-16. The server profile id is the cross-server boundary, never the host.
    $payloads[] = $rejected($select(['table' => 'Customer'], ['joins' => [$join(['database' => 'legacy', 'table' => 'Old'])]]),
        400, 'CROSS_SERVER_QUERY_NOT_SUPPORTED', 'joins.0.source.database');
    $payloads[] = $rejected(['action' => 'union', 'queries' => [
        ['source' => ['database' => 'legacy', 'table' => 'A'], 'fields' => ['Id']],
        ['source' => ['database' => 'company', 'table' => 'B'], 'fields' => ['Id']],
    ]], 400, 'CROSS_SERVER_QUERY_NOT_SUPPORTED', 'queries.1.source.database');
    // Same host and catalog, different profile: still another server.
    $payloads[] = $rejected($select(['database' => 'reporting', 'table' => 'A'], ['joins' => [$join(['database' => 'alt', 'table' => 'B'])]]),
        400, 'CROSS_SERVER_QUERY_NOT_SUPPORTED');
    $plannerSource = (string)file_get_contents(dirname(__DIR__) . '/app/Database/DatabaseQueryPlanner.php');
    planAssert(!preg_match('/->server\(\)|->port\(\)|->username\(\)|driverConfiguration|OPENQUERY|OPENROWSET|OPENDATASOURCE/i', $plannerSource),
        'The planner compares connection details or uses linked-server access.');

    // 17-19. Access policy.
    $policy = new AllowEnabledDatabasesPolicy();
    $companyContext = $resolver->resolve('company');
    $policy->assertAllowed($companyContext, null);
    $disabledContext = new DatabaseContext('company', 'Company', false, 'CompanyDB', $companyContext->serverProfile);
    planAssert(planResponse(planFailure(fn () => $policy->assertAllowed($disabledContext, null), 'A disabled database was allowed.'))[1] === 'DATABASE_DISABLED',
        'The policy allowed a disabled database.');
    $disabledProfile = new DatabaseServerProfile('sql01', 'SQL Server 01', false, $registry->serverConnection('sql01'));
    planAssert(planResponse(planFailure(fn () => $policy->assertAllowed(new DatabaseContext('company', 'Company', true, 'CompanyDB', $disabledProfile), null),
        'A database on a disabled profile was allowed.'))[1] === 'SERVER_PROFILE_DISABLED', 'The policy allowed a disabled server profile.');
    $principal = new Principal('u1', 'reader', 'api_key', null, false, null, true, []);
    planAssert($planner->plan($select(['database' => 'inventory', 'table' => 'P']), $principal)->primaryDatabase->id === 'inventory',
        'Planning required a database permission.');
    $recordingPolicy = new RecordingDenyPolicy('reporting');
    $policyPlanner = new DatabaseQueryPlanner($resolver, $recordingPolicy);
    [$status, $code, $path] = planResponse(planFailure(fn () => $policyPlanner->plan($select(['table' => 'C'], ['database' => 'company',
        'joins' => [$join(['database' => 'inventory', 'table' => 'P']), $join(['database' => 'reporting', 'table' => 'R'])]]), $principal),
        'A denied database was planned.'));
    planAssert($status === 403 && $code === 'DATABASE_ACCESS_DENIED' && $path === 'joins.1.source.database'
        && $recordingPolicy->seen === [['company', 'reader'], ['inventory', 'reader'], ['reporting', 'reader']],
        'The access policy is not consulted for every referenced database with the principal.');
    $permissionSources = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') $permissionSources .= (string)file_get_contents($file->getPathname());
    }
    $permissionSources .= (string)file_get_contents(dirname(__DIR__) . '/api/index.php');
    $authorizationMiddleware = (string)file_get_contents(dirname(__DIR__) . '/app/Middleware/AuthorizationMiddleware.php');
    planAssert(!preg_match('/[\'"]database\.use[\'"]/', $permissionSources) && !str_contains(strtolower($authorizationMiddleware), 'database'),
        'A database permission was introduced.');

    // 20-25. Plans.
    planAssert(!$v2->isCrossDatabase && $v2->serverProfileId === 'sql01' && count($v2->referencedDatabases) === 1
        && $v2->referencedDatabases[0] === $v2->primaryDatabase && $v2->references[0]->id === 'company', 'The single-database plan is wrong.');
    // A schema is one identifier, never a qualified name.
    $payloads[] = $rejected($select(['database' => 'company', 'schema' => 'dbo.Customer', 'table' => 'Customer']), 400, 'INVALID_REQUEST', 'source.schema');
    $cross = $plan($select(['database' => 'company', 'table' => 'Customer'], ['database' => 'company',
        'joins' => [$join(['database' => 'inventory', 'table' => 'Product'])]]));
    planAssert($cross->primaryDatabase->id === 'company' && $cross->databaseIds() === ['company', 'inventory']
        && $cross->serverProfileId === 'sql01' && $cross->isCrossDatabase
        && $cross->database('inventory')?->physicalName() === 'InventoryDB' && $cross->database('legacy') === null,
        'The multi-database plan is wrong.');
    planAssert(array_map(fn (DatabaseReference $reference): string => $reference->path, $cross->references) === ['database', 'joins.0.source.database'],
        'The plan does not keep the logical references.');
    planAssert(!$v2->isCrossDatabase && $sameProfile->isCrossDatabase && !$baseSource->isCrossDatabase, 'isCrossDatabase is wrong.');

    // 26-29. Security.
    $payloads[] = $rejected($select(['table' => 'Customer'], ['database' => 'CompanyDB']), 400, 'INVALID_REQUEST', 'database');
    $payloads[] = $rejected($select(['database' => 'companydb', 'table' => 'Customer']), 404, 'DATABASE_NOT_FOUND', 'source.database');
    $payloads[] = $rejected($select(['database' => '', 'table' => 'Customer']), 400, 'INVALID_REQUEST', 'source.database');
    $payloads[] = $rejected($select(['database' => 7, 'table' => 'Customer']), 400, 'INVALID_REQUEST', 'source.database');
    $payloads[] = $rejected($select(['table' => 'Customer'], ['database' => ['id' => 'company', 'server' => 'sql01.plan.test']]), 400, 'INVALID_REQUEST', 'database');
    foreach (['server', 'host', 'port', 'username', 'password', 'connectionString', 'catalog'] as $key) {
        $payloads[] = $rejected($select(['table' => 'Customer'], [$key => 'x']), 400, 'INVALID_REQUEST', $key);
        $payloads[] = $rejected($select(['table' => 'Customer', $key => 'x']), 400, 'INVALID_REQUEST', "source.{$key}");
    }
    $payloads[] = $rejected($select(['table' => 'Customer'], ['joins' => [$join(['table' => 'P', 'password' => 'x'])]]), 400, 'INVALID_REQUEST', 'joins.0.source.password');
    foreach (['CompanyDB.dbo.Customer', 'sql01.CompanyDB.dbo.Customer'] as $name) {
        $payloads[] = $rejected($select(['table' => $name]), 400, 'INVALID_REQUEST', 'source.table');
        $payloads[] = $rejected($select(['table' => 'Customer'], ['joins' => [$join(['table' => $name])]]), 400, 'INVALID_REQUEST', 'joins.0.source.table');
    }
    planAssert(planResponse(planFailure(fn () => (new WriteRequestValidator())->validate(['action' => 'insert', 'table' => 'CompanyDB.dbo.Customer', 'data' => ['A' => 1]]),
        'A database-qualified write table was accepted.'))[1] === 'INVALID_REQUEST'
        && planResponse(planFailure(fn () => RoutineResolver::name('CompanyDB.dbo.Report', 'procedure'), 'A database-qualified routine was accepted.'))[1] === 'INVALID_ROUTINE',
        'Database-qualified write or routine names were accepted.');
    // SQL Resources, writes, and routines do not name databases; metadata names
    // its database only at the top level.
    foreach ([
        ['action' => 'sql', 'resource' => 'reports/sales', 'database' => 'inventory'],
        ['action' => 'insert', 'table' => 'Customer', 'data' => ['A' => 1], 'database' => 'inventory'],
        ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Run', 'database' => 'inventory']],
        ['action' => 'metadata.columns', 'source' => ['table' => 'Customer', 'database' => 'inventory']],
        ['action' => 'metadata.databases', 'database' => 'inventory'],
        ['action' => 'select', 'source' => ['table' => 'C'], 'fields' => ['Id'], 'filters' => [['operator' => 'EXISTS',
            'query' => ['database' => 'inventory', 'source' => ['table' => 'P'], 'fields' => ['Id']]]]],
    ] as $request) {
        $payloads[] = $rejected($request, 400, 'INVALID_REQUEST');
    }
    $planDump = print_r($cross, true) . var_export((array)json_decode(json_encode($cross), true), true);
    planAssert(planFailure(fn () => serialize($cross), 'A query plan was serialized.') instanceof LogicException, 'A query plan can be serialized.');

    // 30. V2 compatibility: requests without a database are unchanged.
    $normalizer = new QueryRequestNormalizer();
    $v2Request = $select(['table' => 'Customer', 'alias' => 'C'], ['joins' => [$join(['table' => 'Orders'])], 'filters' => [['field' => 'Id', 'operator' => 'IN',
        'query' => ['source' => ['table' => 'Sales'], 'fields' => ['Id']]]]]);
    $v3Request = $select(['table' => 'Customer', 'alias' => 'C', 'database' => 'company'], ['database' => 'company', 'joins' => [$join(['table' => 'Orders', 'database' => 'company'])],
        'filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Sales', 'database' => 'company'], 'fields' => ['Id']]]]]);
    $validator->validate($v2Request);
    $validator->validate($v3Request);
    // V2 normalization is unchanged; database ids travel as structured source locations only.
    $withoutDatabases = function (array $value) use (&$withoutDatabases): array {
        unset($value['database']);
        return array_map(fn ($item) => is_array($item) ? $withoutDatabases($item) : $item, $value);
    };
    $v2Normalized = $normalizer->normalize($v2Request);
    $v3Normalized = $normalizer->normalize($v3Request);
    planAssert(!str_contains(json_encode($v2Normalized), 'database') && ($v3Normalized['database'] ?? null) === 'company'
        && ($v3Normalized['joins'][0]['database'] ?? null) === 'company' && ($v3Normalized['where'][0]['subquery']['database'] ?? null) === 'company'
        && $withoutDatabases($v3Normalized) === $v2Normalized,
        'Database locations changed the V2 normalized request.');
    foreach ([['action' => 'sql', 'resource' => 'reports/sales'], ['action' => 'metadata.tables'], ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Run']],
        ['action' => 'insert', 'table' => 'Customer', 'data' => ['A' => 1]]] as $request) {
        planAssert($planner->plan($request)->databaseIds() === ['company'], 'A V2 action did not plan the default database.');
    }
    // The early gate uses the selected database, else the default, as in V2.
    $middleware = new DatabaseAvailabilityMiddleware(null, $resolver);
    $availability->setAvailable(false, 'company');
    planAssert(planResponse(planFailure(fn () => $middleware->handle($select(['table' => 'Customer'])), 'A closed default database was requestable.'))[1] === 'DATABASE_UNAVAILABLE',
        'The V2 default gate changed.');
    $middleware->handle($select(['database' => 'inventory', 'table' => 'Product']));
    $availability->setAvailable(true, 'company');
    // Execution: the engine connects to the plan's primary database.
    $logger = new Logger($directory . '/logs');
    $connections = fn (): DatabaseConnectionManager => new DatabaseConnectionManager(static fn (array $c): Database => new PlanningDatabase($c));
    DatabaseQueryPlanContext::set($explicit);
    PlanningSqlServerDriver::$attempts = [];
    $engine = new QueryEngine(null, $logger, null, true, $connections());
    planAssert(count(PlanningSqlServerDriver::$attempts) === 1 && str_contains(PlanningSqlServerDriver::$attempts[0], 'Database=InventoryDB;'),
        'A single-database plan did not connect to its primary database.');
    $engine->close();
    // A same-profile cross-database plan connects once, to its primary database.
    DatabaseQueryPlanContext::set($cross);
    $crossEngine = new QueryEngine(null, $logger, null, true, $connections());
    planAssert(count(PlanningSqlServerDriver::$attempts) === 2 && str_contains(PlanningSqlServerDriver::$attempts[1], 'Database=CompanyDB;'),
        'A cross-database plan did not open one connection to its primary database.');
    $crossEngine->close();
    DatabaseQueryPlanContext::clear();
    $api = (string)file_get_contents(dirname(__DIR__) . '/api/index.php');
    planAssert(strpos($api, 'new AuthorizationMiddleware') < strpos($api, '$validator->validate($publicRequest)')
        && strpos($api, '$normalizer->normalize($publicRequest)') < strpos($api, 'new DatabaseQueryPlanner')
        && strpos($api, 'new DatabaseQueryPlanner') < strpos($api, '$instance->$action($request)'),
        'Planning does not run after authorization and validation and before execution.');

    // 29. No physical names, hosts, or credentials in errors, plans, or logs.
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    planAssert(str_contains($logText, 'inventory'), 'The planned database was not logged.');
    foreach ($secrets as $secret) {
        planAssert(!str_contains(implode("\n", $payloads), $secret), "An error exposed {$secret}.");
        planAssert(!str_contains($planDump, $secret), "A query plan exposed {$secret}.");
        planAssert(!str_contains($logText, $secret), "A log exposed {$secret}.");
    }

    echo "Database query planning tests passed.\n";
} finally {
    DatabaseQueryPlanContext::clear();
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    planRemoveDirectory($directory);
}
