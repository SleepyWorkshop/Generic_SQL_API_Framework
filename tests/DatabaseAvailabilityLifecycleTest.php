<?php

require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';
require_once __DIR__ . '/../core/QueryEngine.php';
require_once __DIR__ . '/../sqlparser/src/SqlParserRequestHandler.php';

/*
 * Database Connect/Disconnect is an application availability gate over the
 * existing per-request connection architecture. These tests use injected
 * connection testers and a fake Database; no SQL Server is required.
 */

function databaseLifecycleAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function databaseLifecycleRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') databaseLifecycleRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function databaseLifecycleFailure(callable $operation): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        return $exception;
    }
    throw new RuntimeException('A failing database operation was accepted.');
}

final class DatabaseLifecycleCountingDatabase extends Database
{
    public static int $opened = 0;
    public static int $closed = 0;

    public function __construct() { self::$opened++; }
    public function getConnection() { return null; }
    public function close() { self::$closed++; }
}

$directory = sys_get_temp_dir() . '/generic-database-lifecycle-' . bin2hex(random_bytes(8));
$configurationDirectory = $directory . '/config';
$logs = $directory . '/logs';
$databasePath = $directory . '/database.json';
$healthCachePath = $directory . '/health/database-health.json';
$password = 'lifecycle-secret-' . bin2hex(random_bytes(6));
$key = base64_encode(random_bytes(32));
$oldEnvironment = getenv('GENERIC_APP_ENV');
$oldConfigurationDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');
$oldKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);

try {
    mkdir($configurationDirectory, 0700, true);
    mkdir($logs, 0700, true);
    putenv('GENERIC_APP_ENV=production');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    RuntimeConfiguration::ensure();

    $database = [
        'provider' => 'sqlserver',
        'driver' => 'auto',
        'server' => 'db.example.internal',
        'port' => '1433',
        'database' => 'LifecycleDb',
        'authentication' => 'sql',
        'username' => 'lifecycle_user',
        'password' => $password,
        'options' => ['encrypt' => true, 'trustServerCertificate' => false],
    ];
    JsonFileStore::save($databasePath, (new DatabaseCredentialEncryption())->encryptConfiguration($database));
    $storedConfiguration = (string)file_get_contents($databasePath);

    $applicationStatePath = RuntimeConfiguration::path(RuntimeConfiguration::APPLICATION_RUNTIME_STATE_FILE);
    $applicationRuntime = new ApplicationRuntimeManager($applicationStatePath);
    $availability = new DatabaseAvailabilityManager(RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE));
    $testedConfigurations = [];
    $connectionSucceeds = true;
    $tester = function (array $configuration) use (&$testedConfigurations, &$connectionSucceeds): void {
        $testedConfigurations[] = $configuration;
        if (!$connectionSucceeds) throw new RuntimeException("Login failed; password={$configuration['password']}");
    };
    $monitor = new ApplicationHealthMonitor([
        'databasePath' => $databasePath,
        'databaseCachePath' => $healthCachePath,
        'databaseAvailable' => fn (): bool => $availability->available(),
        'databaseTester' => $tester,
    ]);
    $logger = new Logger($logs);
    $service = new AdminService(
        new AdminConfigurationRepository(),
        $databasePath,
        $tester,
        null,
        null,
        null,
        null,
        $availability,
        $logger,
        $monitor,
        $applicationRuntime
    );
    $runtimeState = static fn (): array => JsonFileStore::load($applicationStatePath);

    // Fresh installations start with application database access disabled.
    $initial = $service->status()['database'];
    databaseLifecycleAssert(
        $initial['available'] === false && $initial['state'] === 'disabled'
            && $initial['status'] === 'disconnected' && $initial['reason'] === 'application_access_disabled'
            && $testedConfigurations === [],
        'Disabled database access was not reported as an administrator-disabled state.'
    );

    // 8 and 9. Connect decrypts through the resolver, performs one test connection, then enables access.
    $runtimeBefore = $runtimeState();
    $connected = $service->controlDatabase('connect');
    databaseLifecycleAssert(
        $connected['available'] === true && $connected['connected'] === true && $connected['state'] === 'connected'
            && count($testedConfigurations) === 1
            && $testedConfigurations[0]['password'] === $password
            && $testedConfigurations[0]['server'] === 'db.example.internal'
            && $availability->available() === true,
        'Connect did not verify the decrypted configuration before enabling database access.'
    );
    databaseLifecycleAssert(
        !preg_match('/password|secret|ciphertext/i', json_encode($connected, JSON_THROW_ON_ERROR)),
        'Connect result exposed credential material.'
    );
    $healthy = $service->status()['database'];
    databaseLifecycleAssert(
        $healthy['state'] === 'connected' && $healthy['status'] === 'connected' && $healthy['reason'] === null
            && !str_contains(json_encode($healthy, JSON_THROW_ON_ERROR), $password),
        'Connected database state was not reported safely.'
    );

    // 1-6. Disconnect only disables access and preserves everything else.
    $testsBeforeDisconnect = count($testedConfigurations);
    $disconnected = $service->controlDatabase('disconnect');
    databaseLifecycleAssert(
        $disconnected['available'] === false && $disconnected['connected'] === false
            && $disconnected['state'] === 'disabled' && !array_key_exists('pid', $disconnected)
            && $availability->available() === false,
        'Disconnect did not disable application database access.'
    );
    databaseLifecycleAssert(
        count($testedConfigurations) === $testsBeforeDisconnect,
        'Disconnect opened a database connection.'
    );
    databaseLifecycleAssert(
        is_file($databasePath) && (string)file_get_contents($databasePath) === $storedConfiguration
            && DatabaseConfigurationResolver::usesEncryption(DatabaseConfigurationResolver::readStored($databasePath))
            && DatabaseConfigurationResolver::load($databasePath)['password'] === $password
            && !str_contains($storedConfiguration, $password),
        'Disconnect changed the stored configuration or its encrypted credentials.'
    );
    databaseLifecycleAssert(
        $runtimeState() === $runtimeBefore && $applicationRuntime->enabled('api') && $applicationRuntime->enabled('sqlParser'),
        'Database lifecycle changed API or SQL Parser application availability.'
    );
    foreach (['app/Services/AdminService.php', 'app/Runtime/DatabaseAvailabilityManager.php', 'app/Middleware/DatabaseAvailabilityMiddleware.php'] as $source) {
        databaseLifecycleAssert(
            !preg_match('/proc_open|shell_exec|passthru|popen|\bexec\(|\bsystem\(|net\s+stop|sc\s+stop|systemctl|MSSQLSERVER|mssql-server/i', (string)file_get_contents(dirname(__DIR__) . '/' . $source)),
            "{$source} can control SQL Server or operating-system services."
        );
    }

    // 7. While disabled, database-dependent API requests are denied with DATABASE_UNAVAILABLE.
    try {
        (new DatabaseAvailabilityMiddleware($availability))->handle(['action' => 'select']);
        databaseLifecycleAssert(false, 'Database-dependent request was accepted while access was disabled.');
    } catch (ApiRequestException $exception) {
        [$status, $payload] = ExceptionHandler::responseFor($exception);
        databaseLifecycleAssert(
            $status === 503 && ($payload['error']['code'] ?? null) === 'DATABASE_UNAVAILABLE',
            'Disabled database access did not return 503 DATABASE_UNAVAILABLE.'
        );
    }
    $apiIndex = (string)file_get_contents(dirname(__DIR__) . '/api/index.php');
    databaseLifecycleAssert(
        strpos($apiIndex, "new ApplicationRuntimeMiddleware('api')") < strpos($apiIndex, 'new DatabaseAvailabilityMiddleware')
            && strpos($apiIndex, 'new DatabaseAvailabilityMiddleware') < strpos($apiIndex, '$validator->validate($publicRequest)'),
        'The API no longer gates database work after the API runtime gate and before execution.'
    );

    // 18. The SQL Parser is independent of database availability.
    [$parserStatus, $parserPayload] = (new SqlParserRequestHandler())->handle('POST', '{"sql":"SELECT ItemCode FROM Items"}', 32);
    databaseLifecycleAssert(
        $parserStatus === 200 && ($parserPayload['success'] ?? null) === true
            && !str_contains((string)file_get_contents(dirname(__DIR__) . '/sqlparser/index.php'), 'DatabaseAvailability'),
        'SQL Parser depends on database availability.'
    );

    // 19. Disabling the API leaves database availability untouched, and vice versa.
    $service->controlApi('stop');
    databaseLifecycleAssert($availability->available() === false, 'API availability changed database availability.');
    $service->controlDatabase('connect');
    databaseLifecycleAssert(
        $applicationRuntime->enabled('api') === false && $availability->available() === true,
        'Database Connect re-enabled the API runtime.'
    );
    $service->controlApi('start');

    // Enabled access with an unusable database is unhealthy, not disconnected.
    $connectionSucceeds = false;
    $unhealthy = $service->status()['database'];
    databaseLifecycleAssert(
        $unhealthy['available'] === true && $unhealthy['state'] === 'unhealthy'
            && $unhealthy['connected'] === false && $unhealthy['status'] !== 'disconnected'
            && $unhealthy['reason'] === 'authentication_failure'
            && !str_contains(json_encode($unhealthy, JSON_THROW_ON_ERROR), $password),
        'An enabled but failing database was not reported as unhealthy.'
    );

    // 10. Failed Connect never reports connected and leaves access disabled.
    $failure = databaseLifecycleFailure(fn () => $service->controlDatabase('connect'));
    [$failureStatus, $failurePayload] = ExceptionHandler::responseFor($failure);
    databaseLifecycleAssert(
        $failure->getErrorCode() === 'DATABASE_CONNECTION_FAILED' && $failureStatus === 422
            && $availability->available() === false
            && $service->status()['database']['state'] === 'disabled'
            && !str_contains(json_encode($failurePayload, JSON_THROW_ON_ERROR), $password),
        'Failed Connect reported success, left access enabled, or exposed credentials.'
    );
    databaseLifecycleFailure(fn () => $service->controlDatabase('restart'));
    databaseLifecycleAssert($availability->available() === false, 'Failed Restart left database access enabled.');

    // A recovered database connects immediately, without a stale cached failure.
    $connectionSucceeds = true;
    $service->controlDatabase('connect');
    databaseLifecycleAssert(
        $service->status()['database']['state'] === 'connected',
        'A stale connection-check result hid a successful Connect.'
    );

    // 11 and 12. Test Connection never changes availability or persists credentials.
    foreach ([true, false] as $enabled) {
        $availability->setAvailable($enabled);
        $submitted = [...$database, 'server' => 'other.example.internal', 'password' => 'submitted-secret'];
        $connectionSucceeds = true;
        databaseLifecycleAssert($service->testDatabase($submitted) === ['connected' => true], 'Test Connection did not report success.');
        $connectionSucceeds = false;
        $testFailure = databaseLifecycleFailure(fn () => $service->testDatabase($submitted));
        databaseLifecycleAssert(
            $testFailure->getErrorCode() === 'DATABASE_CONNECTION_FAILED'
                && $testFailure->getMessage() === 'Database connection failed.'
                && $availability->available() === $enabled,
            'Test Connection changed database availability.'
        );
        databaseLifecycleAssert(
            (string)file_get_contents($databasePath) === $storedConfiguration,
            'Test Connection persisted submitted configuration.'
        );
    }
    $connectionSucceeds = true;
    $persisted = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $persisted .= (string)file_get_contents($file->getPathname());
    }
    // 13. Credentials stay encrypted at rest and never reach state, cache, or logs.
    databaseLifecycleAssert(
        !str_contains($persisted, $password) && !str_contains($persisted, 'submitted-secret') && !str_contains($persisted, $key),
        'A plaintext password or encryption key was written to disk.'
    );

    // 14. A missing encryption key fails safely before any connection attempt.
    $availability->setAvailable(true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    $testsBeforeKey = count($testedConfigurations);
    $missingKey = databaseLifecycleFailure(fn () => $service->controlDatabase('connect'));
    [$missingStatus, $missingPayload] = ExceptionHandler::responseFor($missingKey);
    databaseLifecycleAssert(
        $missingKey->getErrorCode() === 'DATABASE_CONFIGURATION_UNAVAILABLE' && $missingStatus === 503
            && ($missingKey->getDetails()[0]['reason'] ?? null) === 'encryption_key_missing'
            && count($testedConfigurations) === $testsBeforeKey
            && $availability->available() === false
            && !preg_match('/' . preg_quote($password, '/') . '|ciphertext|nonce/i', json_encode($missingPayload, JSON_THROW_ON_ERROR)),
        'A missing encryption key did not fail Connect safely.'
    );
    databaseLifecycleAssert(
        (string)file_get_contents($databasePath) === $storedConfiguration,
        'A missing encryption key changed the stored configuration.'
    );

    // 15. An invalid key, tampered envelope, or missing file fails safely.
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    $wrongKey = databaseLifecycleFailure(fn () => $service->controlDatabase('connect'));
    databaseLifecycleAssert(
        ($wrongKey->getDetails()[0]['reason'] ?? null) === 'configuration_invalid'
            && $availability->available() === false && count($testedConfigurations) === $testsBeforeKey,
        'A wrong encryption key did not fail Connect safely.'
    );
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    $tampered = json_decode($storedConfiguration, true, 512, JSON_THROW_ON_ERROR);
    $tampered['ciphertext'] = base64_encode(random_bytes(48));
    JsonFileStore::save($databasePath, $tampered);
    databaseLifecycleAssert(
        (databaseLifecycleFailure(fn () => $service->controlDatabase('connect'))->getDetails()[0]['reason'] ?? null) === 'configuration_invalid',
        'A tampered encrypted configuration was accepted.'
    );
    rename($databasePath, $databasePath . '.moved');
    databaseLifecycleAssert(
        (databaseLifecycleFailure(fn () => $service->controlDatabase('connect'))->getDetails()[0]['reason'] ?? null) === 'configuration_missing'
            && $availability->available() === false,
        'A missing database configuration did not fail Connect safely.'
    );
    rename($databasePath . '.moved', $databasePath);
    JsonFileStore::save($databasePath, json_decode($storedConfiguration, true, 512, JSON_THROW_ON_ERROR));

    // The real driver path fails without falsely enabling access.
    $driverService = new AdminService(new AdminConfigurationRepository(), $databasePath, null, null, null, null, null,
        $availability, $logger, $monitor, $applicationRuntime);
    $unreachable = [...$database, 'driver' => 'Generic Lifecycle Missing ODBC Driver', 'server' => '127.0.0.1', 'port' => '1'];
    JsonFileStore::save($databasePath, (new DatabaseCredentialEncryption())->encryptConfiguration($unreachable));
    $driverFailure = databaseLifecycleFailure(fn () => $driverService->controlDatabase('connect'));
    databaseLifecycleAssert(
        $driverFailure->getErrorCode() === 'DATABASE_CONNECTION_FAILED' && $availability->available() === false,
        'A real driver connection failure was reported as connected.'
    );
    $adminSource = (string)file_get_contents(dirname(__DIR__) . '/app/Services/AdminService.php');
    databaseLifecycleAssert(
        preg_match('/\$driver = new SqlServerDriver\(\$database\);\s*try \{\s*\$driver->connect\(\);\s*\} finally \{\s*\$driver->disconnect\(\);/', $adminSource) === 1,
        'The Connect verification no longer closes its test connection.'
    );
    JsonFileStore::save($databasePath, json_decode($storedConfiguration, true, 512, JSON_THROW_ON_ERROR));

    // 16 and 17. Requests still open and close their own connections; nothing is persistent or global.
    for ($request = 1; $request <= 3; $request++) {
        $engine = new QueryEngine(new DatabaseLifecycleCountingDatabase(), $logger, 5);
        unset($engine);
    }
    databaseLifecycleAssert(
        DatabaseLifecycleCountingDatabase::$opened === 3 && DatabaseLifecycleCountingDatabase::$closed === 3,
        'Database requests no longer use request-scoped connections.'
    );
    foreach (['core/Database.php', 'core/QueryEngine.php', 'database/drivers/SqlServerDriver.php', 'database/factory/DriverFactory.php',
        'app/Services/AdminService.php', 'app/Runtime/DatabaseAvailabilityManager.php'] as $source) {
        databaseLifecycleAssert(
            !preg_match('/odbc_pconnect|static\s+(?:\?\w+\s+)?\$(?:connection|db|database|driver)\b|\$GLOBALS/i', (string)file_get_contents(dirname(__DIR__) . '/' . $source)),
            "{$source} introduces a persistent or global database connection."
        );
    }
    $state = JsonFileStore::load(RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE));
    databaseLifecycleAssert(
        array_keys($state) === ['version', 'databases'] && $state['version'] === 2
            && $state['databases'] !== []
            && array_filter($state['databases'], static fn ($entry): bool => array_keys($entry) !== ['available', 'updatedAt']) === [],
        'Database runtime state stores more than per-database availability.'
    );

    // 20. Every lifecycle operation is audited with a safe reason and no secrets.
    $auditSource = implode("\n", array_map(
        static fn (string $path): string => (string)file_get_contents($path),
        glob($logs . '/*.log') ?: []
    ));
    $audits = [];
    foreach (preg_split('/\R/', $auditSource) ?: [] as $line) {
        $record = json_decode($line, true);
        if (is_array($record)) $audits[] = $record;
    }
    $lifecycle = static fn (string $action, string $outcome, ?string $reason = null): bool => array_filter($audits,
        static fn (array $record): bool => ($record['event'] ?? null) === 'runtime.lifecycle'
            && ($record['component'] ?? null) === 'database' && ($record['action'] ?? null) === $action
            && ($record['outcome'] ?? null) === $outcome
            && ($reason === null || ($record['reason'] ?? null) === $reason)) !== [];
    databaseLifecycleAssert(
        $lifecycle('connect', 'success') && $lifecycle('disconnect', 'success')
            && $lifecycle('connect', 'failure', 'connection_failed')
            && $lifecycle('restart', 'failure', 'connection_failed')
            && $lifecycle('connect', 'failure', 'encryption_key_missing')
            && $lifecycle('connect', 'failure', 'configuration_invalid')
            && $lifecycle('connect', 'failure', 'configuration_missing'),
        'Database lifecycle operations were not audited with safe reasons.'
    );
    databaseLifecycleAssert(
        array_filter($audits, static fn (array $record): bool => ($record['event'] ?? null) === 'database.connection_test'
            && ($record['outcome'] ?? null) === 'failure') !== []
            && !str_contains($auditSource, $password) && !str_contains($auditSource, 'submitted-secret') && !str_contains($auditSource, $key),
        'Database connection tests were not audited safely.'
    );

    echo "Database availability lifecycle tests passed.\n";
} finally {
    $oldEnvironment === false ? putenv('GENERIC_APP_ENV') : putenv('GENERIC_APP_ENV=' . $oldEnvironment);
    $oldConfigurationDirectory === false
        ? putenv('GENERIC_RUNTIME_CONFIG_DIR')
        : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldConfigurationDirectory);
    $oldKey === false
        ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)
        : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    databaseLifecycleRemove($directory);
}
