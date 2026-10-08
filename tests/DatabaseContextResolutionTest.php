<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionException.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';
require_once __DIR__ . '/../core/QueryEngine.php';
require_once __DIR__ . '/../core/JsonFileStore.php';

function contextAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function contextFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, error code] of the API response an exception produces. */
function contextResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, json_encode($payload)];
}

function contextRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/** SQL Server driver that records connection attempts instead of opening ODBC connections. */
final class RecordingSqlServerDriver extends SqlServerDriver
{
    public static array $attempts = [];
    public static ?string $error = null;

    protected function openConnection(string $dsn, string $authentication, string $username, string $password)
    {
        self::$attempts[] = ['dsn' => $dsn, 'username' => $username, 'password' => $password];
        return self::$error === null ? 'recorded-connection' : false;
    }

    protected function connectionError(): string
    {
        return (string)self::$error;
    }

    public function disconnect()
    {
    }
}

/** Database whose driver is the recording driver. */
final class RecordingDatabase extends Database
{
    public static int $closed = 0;
    private RecordingSqlServerDriver $recordingDriver;

    public function __construct(array $configuration)
    {
        $this->recordingDriver = new RecordingSqlServerDriver($configuration, false);
        $this->recordingDriver->connect();
    }

    public function getConnection() { return $this->recordingDriver->getConnection(); }
    public function close() { self::$closed++; }
}

/** Database that fails the way a real connection failure reaches the query engine. */
final class FailingContextDatabase extends Database
{
    public function __construct() {}
    public function getConnection()
    {
        throw DatabaseConnectionException::fromDriverError("HYT00 [Microsoft][ODBC Driver 18 for SQL Server]Login timeout expired; Server=sql01.context.test;UID=company_user;PWD=company-secret");
    }
}

/** A registry reader whose database references a missing server profile. */
final class DanglingRegistryReader implements DatabaseRegistryReader
{
    public function metadata(): array
    {
        return ['defaultDatabase' => 'orphan', 'servers' => [],
            'databases' => ['orphan' => ['name' => 'Orphan', 'server' => 'gone', 'enabled' => true]]];
    }
    public function serverConnection(string $serverId): array { throw new DatabaseCredentialException('Database server profile is not configured.'); }
    public function databaseCatalog(string $databaseId): string { return 'OrphanDB'; }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-database-context-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$secrets = ['company-secret', 'legacy-secret', 'company_user', 'legacy_user', 'sql01.context.test', 'sql02.context.test', 'CompanyDB', 'InventoryDB', 'LegacyDB'];

try {
    foreach (['config', 'database/config', 'logs', 'operational', 'runtime/health'] as $path) mkdir($directory . '/' . $path, 0700, true);
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    RuntimeConfiguration::ensure();

    // Topology: sql01 hosts company (default), inventory, reporting; sql02 hosts legacy.
    $legacyPath = $directory . '/database/config/database.json';
    $registry = DatabaseRegistry::forLegacyPath($legacyPath);
    $sql01 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.context.test', 'port' => '1433',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => 'company-secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false, 'loginTimeoutSeconds' => 7]];
    $sql02 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql02.context.test',
        'authentication' => 'sql', 'username' => 'legacy_user', 'password' => 'legacy-secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $sql01);
    $registry->saveServer('sql02', 'SQL Server 02', true, $sql02);
    // The same host as sql01 under different credentials is a separate profile.
    $registry->saveServer('sql01-reporting', 'SQL Server 01 (reporting login)', true, [...$sql01, 'username' => 'reporting_user']);
    $registry->saveDatabase('company', 'Company', 'sql01', true, 'CompanyDB');
    $registry->saveDatabase('inventory', 'Inventory', 'sql01', true, 'InventoryDB');
    $registry->saveDatabase('reporting', 'Reporting', 'sql01', true, 'ReportingDB');
    $registry->saveDatabase('reports_ro', 'Reports (read only)', 'sql01-reporting', true, 'ReportingDB');
    $registry->saveDatabase('legacy', 'Legacy', 'sql02', true, 'LegacyDB');
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $registry->saveServer('sql03', 'SQL Server 03', false, [...$sql02, 'server' => 'sql03.context.test']);
    $registry->saveDatabase('offline', 'Offline', 'sql03', true, 'OfflineDB');
    $statePath = $directory . '/config/database-state.json';
    $availability = new DatabaseAvailabilityManager($statePath, $registry);
    $resolver = new DatabaseContextResolver($registry, $availability);

    // 1-4. Resolution: default, explicit, and the server profile as the connection group.
    $company = $resolver->resolve();
    contextAssert($company->id === 'company' && $company->physicalName() === 'CompanyDB' && $company->serverProfileId() === 'sql01'
        && $resolver->defaultDatabaseId() === 'company', 'The default database did not resolve.');
    $inventory = $resolver->resolve('inventory');
    contextAssert($inventory->id === 'inventory' && $inventory->name === 'Inventory' && $inventory->physicalName() === 'InventoryDB'
        && $inventory->serverProfile->server() === 'sql01.context.test', 'An explicit database did not resolve.');
    $reporting = $resolver->resolve('reporting');
    contextAssert($company->sharesServerProfileWith($inventory) && $inventory->sharesServerProfileWith($reporting),
        'Databases on one server profile were not grouped.');
    $legacy = $resolver->resolve('legacy');
    contextAssert(!$company->sharesServerProfileWith($legacy) && $legacy->serverProfileId() === 'sql02', 'Databases on different profiles were grouped.');
    $reportsReadOnly = $resolver->resolve('reports_ro');
    contextAssert($reportsReadOnly->serverProfile->server() === $reporting->serverProfile->server()
        && !$reportsReadOnly->sharesServerProfileWith($reporting), 'Connection groups were derived from hostnames.');
    contextAssert($inventory->serverProfile->loginTimeoutSeconds() === 7 && $legacy->serverProfile->loginTimeoutSeconds() === 15,
        'Profile login timeouts were not resolved.');

    // 5-7. Missing, disabled, and dangling configuration.
    foreach ([
        ['missing', 404, 'DATABASE_NOT_FOUND'],
        ['archive', 403, 'DATABASE_DISABLED'],
        ['offline', 403, 'SERVER_PROFILE_DISABLED'],
        ['Bad Id', 400, 'INVALID_REQUEST'],
    ] as [$id, $expectedStatus, $expectedCode]) {
        [$status, $code] = contextResponse(contextFailure(fn () => $resolver->resolve($id), "Database {$id} resolved."));
        contextAssert($status === $expectedStatus && $code === $expectedCode, "Database {$id} returned {$status} {$code}.");
    }
    [$status, $code] = contextResponse(contextFailure(fn () => (new DatabaseContextResolver(new DanglingRegistryReader(), $availability))->resolve(),
        'A database with a missing server profile resolved.'));
    contextAssert($status === 503 && $code === 'SERVER_PROFILE_NOT_FOUND', 'A missing server profile was not reported.');
    $emptyRegistry = DatabaseRegistry::forLegacyPath($directory . '/empty/database.json');
    $emptyResolver = new DatabaseContextResolver($emptyRegistry, $availability);
    contextAssert(contextResponse(contextFailure(fn () => $emptyResolver->resolve(), 'An unconfigured default resolved.'))[1] === 'DATABASE_CONFIGURATION_ERROR'
        && contextResponse(contextFailure(fn () => $emptyResolver->resolveAvailable(), 'An unconfigured default was available.'))[1] === 'DATABASE_UNAVAILABLE',
        'An unconfigured installation did not keep the V2 errors.');

    // 8, 11, 12. Successful connections select the right profile, catalog, and timeout.
    $manager = new DatabaseConnectionManager(static fn (array $configuration): Database => new RecordingDatabase($configuration));
    RecordingSqlServerDriver::$attempts = [];
    $inventoryConnection = $manager->connection($inventory);
    contextAssert($inventoryConnection->getConnection() === 'recorded-connection' && count(RecordingSqlServerDriver::$attempts) === 1,
        'A connection was not opened.');
    $attempt = RecordingSqlServerDriver::$attempts[0];
    contextAssert(str_contains($attempt['dsn'], 'Server=sql01.context.test,1433;') && str_contains($attempt['dsn'], 'Database=InventoryDB;')
        && str_contains($attempt['dsn'], 'ConnectTimeout=7;') && str_contains($attempt['dsn'], 'Encrypt=yes;')
        && $attempt['username'] === 'company_user' && $attempt['password'] === 'company-secret',
        'The inventory connection did not use its profile, catalog, and timeout.');
    contextAssert(!str_contains($attempt['dsn'], 'company-secret') && !str_contains($attempt['dsn'], 'company_user'),
        'Credentials were placed in the ODBC connection string.');
    contextAssert($manager->connection($inventory) === $inventoryConnection && count(RecordingSqlServerDriver::$attempts) === 1,
        'A request opened a second connection for the same database.');
    contextAssert(contextFailure(fn () => $manager->connection($company), 'A profile connection was reused for another database.') instanceof LogicException,
        'Cross-database reuse of a profile connection was allowed.');
    $legacyConnection = $manager->connection($legacy);
    $legacyAttempt = RecordingSqlServerDriver::$attempts[1];
    contextAssert($legacyConnection !== $inventoryConnection && $manager->openConnectionCount() === 2
        && str_contains($legacyAttempt['dsn'], 'Server=sql02.context.test;') && str_contains($legacyAttempt['dsn'], 'Database=LegacyDB;')
        && str_contains($legacyAttempt['dsn'], 'ConnectTimeout=15;') && $legacyAttempt['username'] === 'legacy_user',
        'A second server profile did not get its own connection, catalog, credentials, and default timeout.');
    RecordingDatabase::$closed = 0;
    $manager->closeAll();
    contextAssert(RecordingDatabase::$closed === 2 && $manager->openConnectionCount() === 0 && !$manager->hasConnection('sql01'),
        'Request-scoped connections were not closed.');
    $manager->test($reporting);
    contextAssert(RecordingDatabase::$closed === 3 && $manager->openConnectionCount() === 0
        && str_contains(RecordingSqlServerDriver::$attempts[2]['dsn'], 'Database=ReportingDB;'), 'A connection test kept its connection.');

    // 9, 10, 13. Failures are classified and never carry connection details.
    $failures = [
        'HYT00 [Microsoft][ODBC Driver 18 for SQL Server]Login timeout expired' => [DatabaseConnectionException::TIMEOUT, 504, 'DATABASE_CONNECTION_TIMEOUT'],
        "28000 [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Login failed for user 'company_user'." => [DatabaseConnectionException::AUTHENTICATION, 503, 'DATABASE_AUTHENTICATION_FAILED'],
        '08001 [Microsoft][ODBC Driver 18 for SQL Server]TCP Provider: No such host is known (sql01.context.test).' => [DatabaseConnectionException::FAILED, 503, 'DATABASE_CONNECTION_FAILED'],
    ];
    foreach ($failures as $driverError => [$kind, $expectedStatus, $expectedCode]) {
        RecordingSqlServerDriver::$error = $driverError;
        $failure = contextFailure(fn () => (new DatabaseConnectionManager(static fn (array $c): Database => new RecordingDatabase($c)))->connection($inventory),
            'A failed connection was reported as open.');
        [$status, $code, $payload] = contextResponse($failure);
        contextAssert($failure instanceof DatabaseConnectionException && $failure->kind() === $kind && $status === $expectedStatus && $code === $expectedCode,
            "Connection failure {$kind} was mapped to {$status} {$code}.");
        foreach ($secrets as $secret) {
            contextAssert(!str_contains($failure->getMessage() . $payload, $secret), 'A connection failure exposed connection details.');
        }
    }
    RecordingSqlServerDriver::$error = null;
    // Driver auto-detection keeps its fallback but reports the most specific failure.
    $autoDriver = new class(['provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'sql01.context.test', 'database' => 'CompanyDB',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => 'company-secret', 'options' => ['encrypt' => true]], false) extends SqlServerDriver {
        public int $attempts = 0;
        protected function openConnection(string $dsn, string $authentication, string $username, string $password)
        {
            $this->attempts++;
            return false;
        }
        protected function connectionError(): string
        {
            return $this->attempts === 1 ? 'IM002 [unixODBC][Driver Manager]Data source name not found' : 'HYT00 [Microsoft]Login timeout expired';
        }
    };
    $autoFailure = contextFailure(fn () => $autoDriver->connect(), 'An unreachable server connected.');
    contextAssert($autoFailure instanceof DatabaseConnectionException && $autoFailure->isTimeout() && $autoFailure->sqlState() === 'HYT00'
        && $autoDriver->attempts === count(SqlServerDriver::supportedDrivers()), 'Auto-detection did not report the login timeout.');

    // 13. Logs, debug output, and serialization never carry connection details.
    $logger = new Logger($directory . '/logs');
    contextFailure(fn () => new QueryEngine(new FailingContextDatabase(), $logger), 'A failed engine connection was accepted.');
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/operational', FilesystemIterator::SKIP_DOTS)) as $file) {
        $logText .= (string)file_get_contents($file->getPathname());
    }
    foreach (glob($directory . '/logs/*') ?: [] as $file) $logText .= (string)file_get_contents($file);
    contextAssert(str_contains($logText, 'Database connection failed') && str_contains($logText, 'timeout'), 'The engine connection failure was not logged.');
    $debug = print_r($inventory, true) . var_export($inventory->__debugInfo(), true) . print_r($inventory->serverProfile, true);
    foreach ($secrets as $secret) {
        contextAssert(!str_contains($logText, $secret), 'Logs exposed connection details.');
        contextAssert(!str_contains($debug, $secret), 'Debug output exposed connection details.');
    }
    contextAssert(contextFailure(fn () => serialize($inventory), 'A database context was serialized.') instanceof LogicException,
        'Database contexts can be serialized with their credentials.');

    // 14-16. Enabled and available are different; disabled is never "unavailable".
    contextAssert(contextResponse(contextFailure(fn () => $resolver->resolveAvailable('inventory'), 'A closed gate served requests.'))[1] === 'DATABASE_UNAVAILABLE',
        'An enabled database with a closed gate was usable.');
    $availability->setAvailable(true, 'inventory');
    contextAssert($resolver->resolveAvailable('inventory')->id === 'inventory' && $resolver->assertRequestable('inventory') === 'inventory',
        'An enabled, available database could not serve requests.');
    contextAssert(contextResponse(contextFailure(fn () => $resolver->resolveAvailable('company'), 'Availability leaked between databases.'))[1] === 'DATABASE_UNAVAILABLE',
        'Availability is not per database.');
    $availability->setAvailable(true, 'archive');
    contextAssert(contextResponse(contextFailure(fn () => $resolver->resolveAvailable('archive'), 'A disabled database served requests.'))[1] === 'DATABASE_DISABLED',
        'A disabled database with an open gate was reported as available.');
    // The request gate decrypts nothing: it works without the encryption key.
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    contextAssert((new DatabaseContextResolver(DatabaseRegistry::forLegacyPath($legacyPath), $availability))->assertRequestable('inventory') === 'inventory',
        'The availability gate required the encryption key.');
    contextAssert(contextResponse(contextFailure(fn () => (new DatabaseContextResolver(DatabaseRegistry::forLegacyPath($legacyPath), $availability))->resolveAvailable('inventory'),
        'A database resolved without its key.'))[1] === 'DATABASE_CONFIGURATION_ERROR', 'A missing key was not a configuration error.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    // 14-16, 20. Health reports configured, enabled, available, and reachable separately.
    $reachable = ['InventoryDB' => true, 'ReportingDB' => false];
    $tested = [];
    $tester = static function (array $configuration) use (&$reachable, &$tested): void {
        $tested[] = $configuration['database'];
        if (($reachable[$configuration['database']] ?? true) !== true) {
            throw DatabaseConnectionException::fromDriverError('HYT00 [Microsoft]Login timeout expired');
        }
    };
    $healthOptions = ['root' => dirname(__DIR__), 'configurationDirectory' => $directory . '/config',
        'databasePath' => $legacyPath, 'registry' => $registry, 'runtimeDirectory' => $directory . '/runtime',
        'logDirectory' => $directory . '/logs', 'databaseCachePath' => $directory . '/runtime/health/database-health.json',
        'diskSpace' => static fn (): int => PHP_INT_MAX, 'production' => false];
    $monitor = new ApplicationHealthMonitor($healthOptions + ['databaseTester' => $tester]);
    $availability->setAvailable(true, 'reporting');
    $inventoryHealth = $monitor->databaseStatus('inventory');
    contextAssert($inventoryHealth['status'] === 'healthy' && $inventoryHealth['category'] === 'connected' && $inventoryHealth['available']
        && $inventoryHealth['enabled'] && $inventoryHealth['serverProfile'] === 'sql01' && $tested === ['InventoryDB'], 'An available database was not healthy.');
    $reportingHealth = $monitor->databaseStatus('reporting');
    contextAssert($reportingHealth['status'] === 'unhealthy' && $reportingHealth['category'] === 'connection_timeout' && $reportingHealth['available'],
        'An unreachable database was not reported as timed out.');
    contextAssert($monitor->databaseStatus('inventory')['cached'] === true && count($tested) === 2
        && is_file($directory . '/runtime/health/database-health.inventory.json'), 'Connection checks are not cached per database.');
    $monitor->forgetDatabaseHealth('inventory');
    contextAssert(!is_file($directory . '/runtime/health/database-health.inventory.json'), 'A database health cache was not discarded.');
    foreach ([['archive', 'disabled', 'database_disabled'], ['offline', 'disabled', 'server_profile_disabled'],
        ['legacy', 'disconnected', 'database_disconnected'], ['missing', 'not_configured', 'database_not_found']] as [$id, $state, $category]) {
        $health = $monitor->databaseStatus($id);
        contextAssert($health['status'] === $state && $health['category'] === $category, "Database {$id} health was {$health['status']}.");
    }
    contextAssert(!in_array('ArchiveDB', $tested, true) && !in_array('LegacyDB', $tested, true) && !in_array('OfflineDB', $tested, true),
        'Health contacted a disabled or disconnected database.');
    $unchecked = new ApplicationHealthMonitor($healthOptions);
    contextAssert($unchecked->databaseStatus('inventory')['category'] === 'database_available' && count($tested) === 2,
        'Health without a tester opened a connection.');
    // The default database keeps the V2 checks: gate first, then the cached connection check.
    contextAssert($monitor->readiness()['checks']['database']['category'] === 'database_disconnected'
        && $monitor->detailed([])['checks']['database']['category'] === 'database_disconnected', 'Default readiness ignored the default gate.');

    // 19. The admin.database.* actions keep working on the default database.
    $adminTested = [];
    $adminTimeout = false;
    $adminTester = static function (array $configuration) use (&$adminTested, &$adminTimeout): void {
        $adminTested[] = $configuration;
        if ($adminTimeout) throw DatabaseConnectionException::fromDriverError('HYT00 [Microsoft]Login timeout expired');
    };
    $service = new AdminService(new AdminConfigurationRepository(), $legacyPath, $adminTester, null, null, null, null,
        $availability, $logger, new ApplicationHealthMonitor($healthOptions + ['databaseTester' => $adminTester]));
    $public = $service->databaseConfiguration();
    contextAssert($public['database'] === 'CompanyDB' && $public['loginTimeoutSeconds'] === 7 && !array_key_exists('password', $public),
        'admin.database.get did not return the default database.');
    $connected = $service->controlDatabase('connect');
    contextAssert($connected['database'] === 'company' && $connected['connected'] && $availability->available('company')
        && end($adminTested)['database'] === 'CompanyDB' && end($adminTested)['password'] === 'company-secret',
        'admin.database.connect did not verify and open the default database.');
    $service->controlDatabase('disconnect');
    contextAssert(!$availability->available('company') && $availability->available('inventory'), 'admin.database.disconnect changed another database.');
    $adminTimeout = true;
    $timeout = contextFailure(fn () => $service->controlDatabase('restart'), 'A timed-out restart succeeded.');
    contextAssert($timeout instanceof ApiRequestException && $timeout->getErrorCode() === 'DATABASE_CONNECTION_TIMEOUT'
        && $timeout->getStatusCode() === 504 && !$availability->available('company'), 'An admin connection timeout was not reported separately.');
    $draftTimeout = contextFailure(fn () => $service->testDatabase([...$sql01, 'database' => 'CompanyDB', 'password' => null]), 'A timed-out test succeeded.');
    contextAssert($draftTimeout instanceof ApiRequestException && $draftTimeout->getErrorCode() === 'DATABASE_CONNECTION_TIMEOUT'
        && end($adminTested)['password'] === 'company-secret', 'admin.database.test did not report a timeout or reuse the stored password.');
    $adminTimeout = false;
    $connectInventory = $service->controlDatabase('connect', 'inventory');
    contextAssert($connectInventory['database'] === 'inventory' && end($adminTested)['database'] === 'InventoryDB', 'Per-database connect did not target its database.');
    $disabledConnect = contextFailure(fn () => $service->controlDatabase('connect', 'archive'), 'A disabled database was connected.');
    contextAssert($disabledConnect instanceof ApiRequestException && $disabledConnect->getErrorCode() === 'DATABASE_DISABLED'
        && !$availability->available('archive'), 'A disabled database could be connected.');
    contextAssert(contextFailure(fn () => $service->controlDatabase('disconnect', 'missing'), 'An unknown database was disconnected.') instanceof ApiRequestException,
        'An unknown database gate could be changed.');
    $validator = new AdminRequestValidator();
    $form = ['provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'sql01.context.test', 'port' => '1433', 'database' => 'CompanyDB',
        'authentication' => 'sql', 'username' => 'company_user', 'password' => null, 'encrypt' => true, 'trustServerCertificate' => false];
    $service->saveDatabase($validator->validate(['action' => 'admin.database.save', 'database' => $form])['database']);
    contextAssert($registry->serverConnection('sql01')['options']['loginTimeoutSeconds'] === 7, 'Saving without a timeout dropped the stored one.');
    $service->saveDatabase($validator->validate(['action' => 'admin.database.save', 'database' => [...$form, 'loginTimeoutSeconds' => 30]])['database']);
    contextAssert($resolver->resolve()->serverProfile->loginTimeoutSeconds() === 30 && $resolver->resolve('inventory')->serverProfile->loginTimeoutSeconds() === 30,
        'A saved login timeout did not apply to the server profile.');
    foreach ([0, 65535, '15', 1.5] as $invalidTimeout) {
        contextAssert(contextFailure(fn () => $validator->validate(['action' => 'admin.database.save', 'database' => [...$form, 'loginTimeoutSeconds' => $invalidTimeout]]),
            'An invalid login timeout was accepted.') instanceof ApiRequestException, 'An invalid login timeout was accepted.');
    }
    contextAssert(contextFailure(fn () => $registry->saveServer('bad', 'Bad', true, [...$sql02, 'options' => ['loginTimeoutSeconds' => 0]]),
        'The registry accepted an invalid timeout.') instanceof DatabaseCredentialException, 'The registry accepted an invalid timeout.');

    // 17-18. V2 requests (no database) resolve the default database through the new path.
    $availability->setAvailable(false, 'company');
    $middlewareFailure = contextFailure(fn () => (new DatabaseAvailabilityMiddleware(null, $resolver))->handle(['action' => 'select']),
        'A request was accepted while the default database was disconnected.');
    contextAssert(contextResponse($middlewareFailure)[0] === 503 && contextResponse($middlewareFailure)[1] === 'DATABASE_UNAVAILABLE',
        'A disconnected default database did not return 503 DATABASE_UNAVAILABLE.');
    $availability->setAvailable(true, 'company');
    (new DatabaseAvailabilityMiddleware(null, $resolver))->handle(['action' => 'select']);
    $v2Manager = new DatabaseConnectionManager(static fn (array $configuration): Database => new RecordingDatabase($configuration));
    $v2Connection = $v2Manager->connection($resolver->resolveAvailable());
    contextAssert($v2Connection->getConnection() === 'recorded-connection'
        && str_contains(end(RecordingSqlServerDriver::$attempts)['dsn'], 'Database=CompanyDB;'), 'A request without a database did not use the default database.');
    $engineSource = (string)file_get_contents(dirname(__DIR__) . '/core/QueryEngine.php');
    contextAssert(str_contains($engineSource, '(new DatabaseContextResolver())->resolveAvailable()')
        && str_contains($engineSource, '$this->connections->connection($context)') && !str_contains($engineSource, 'new Database()'),
        'The query engine does not reach SQL Server through the resolver and connection manager.');
    // The default runtime path: with the gate closed, the engine opens nothing.
    $defaultAvailability = new DatabaseAvailabilityManager();
    $defaultId = (new DatabaseRegistry())->defaultDatabaseId() ?? DatabaseRegistry::DEFAULT_ID;
    $defaultAvailability->setAvailable(false, $defaultId);
    $engineFailure = contextFailure(fn () => new QueryEngine(null, $logger), 'The engine connected while the default database was unavailable.');
    contextAssert($engineFailure instanceof ApiRequestException && $engineFailure->getErrorCode() === 'DATABASE_UNAVAILABLE',
        'The engine did not apply the default database gate.');
    foreach (['app/Database/DatabaseConnectionManager.php', 'app/Database/DatabaseContextResolver.php'] as $source) {
        contextAssert(!preg_match('/odbc_pconnect|static\s+(?:\?\w+\s+)?\$(?:connection|connections|db|database|driver)\b|\$GLOBALS/i',
            (string)file_get_contents(dirname(__DIR__) . '/' . $source)), "{$source} keeps a persistent or global connection.");
    }

    echo "Database context resolution tests passed.\n";
} finally {
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    contextRemoveDirectory($directory);
}
