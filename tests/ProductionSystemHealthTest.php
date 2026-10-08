<?php

require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Backup/BackupRecoveryService.php';
require_once __DIR__ . '/../sqlparser/src/SqlParserRequestHandler.php';

/*
 * System Health must describe the real hosting model: web-server-managed
 * services with application availability in production, managed processes in
 * development, explicit database/encryption state, a no-SQL readiness probe,
 * and non-fatal backup health derived from the existing backup service.
 */

function systemHealthAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function systemHealthRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') systemHealthRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

final class SystemHealthProcessProbe extends ApiProcessManager
{
    public int $operations = 0;

    public function __construct() {}
    public function status(): array
    {
        $this->operations++;
        return ['running' => true, 'healthy' => true, 'service' => 'api', 'status' => 'running',
            'pid' => 4242, 'port' => 8001, 'startedAt' => '2026-10-05T00:00:00+00:00', 'uptimeSeconds' => 5];
    }
    public function start(): array { $this->operations++; return []; }
    public function stop(): array { $this->operations++; return []; }
    public function restart(): array { $this->operations++; return []; }
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/generic-system-health-' . bin2hex(random_bytes(8));
$configurationDirectory = $directory . '/config';
$runtimeDirectory = $directory . '/runtime';
$logs = $directory . '/logs';
$backupDirectory = $directory . '/backups';
$databasePath = $directory . '/database/database.json';
$cachePath = $runtimeDirectory . '/health/database-health.json';
$password = 'health-secret-' . bin2hex(random_bytes(6));
$databaseKey = base64_encode(random_bytes(32));
$signingKey = base64_encode(random_bytes(32));
$environment = [
    'GENERIC_APP_ENV' => getenv('GENERIC_APP_ENV'),
    'GENERIC_RUNTIME_CONFIG_DIR' => getenv('GENERIC_RUNTIME_CONFIG_DIR'),
    DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE => getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE),
    BackupSigningKey::ENVIRONMENT_VARIABLE => getenv(BackupSigningKey::ENVIRONMENT_VARIABLE),
    BackupSigningKey::FILE_ENVIRONMENT_VARIABLE => getenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE),
];

try {
    foreach ([$configurationDirectory, $runtimeDirectory, $logs, dirname($databasePath)] as $path) mkdir($path, 0700, true);
    putenv('GENERIC_APP_ENV=production');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $databaseKey);
    putenv(BackupSigningKey::ENVIRONMENT_VARIABLE . '=' . $signingKey);
    putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE);
    RuntimeConfiguration::ensure();
    JsonFileStore::save($databasePath, (new DatabaseCredentialEncryption())->encryptConfiguration([
        'provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'health.example.internal', 'port' => '1433',
        'database' => 'HealthDb', 'authentication' => 'sql', 'username' => 'health_user', 'password' => $password,
        'options' => ['encrypt' => true, 'trustServerCertificate' => false],
    ]));

    $configuration = new AdminConfigurationRepository();
    $applicationStatePath = RuntimeConfiguration::path(RuntimeConfiguration::APPLICATION_RUNTIME_STATE_FILE);
    $applicationRuntime = new ApplicationRuntimeManager($applicationStatePath);
    $availability = new DatabaseAvailabilityManager(RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE));
    $connectionTests = 0;
    $connectionSucceeds = true;
    $tester = function () use (&$connectionTests, &$connectionSucceeds): void {
        $connectionTests++;
        if (!$connectionSucceeds) throw new RuntimeException('Network error contacting server');
    };
    $monitorOptions = [
        'root' => $root, 'configurationDirectory' => $configurationDirectory, 'databasePath' => $databasePath,
        'runtimeDirectory' => $runtimeDirectory, 'logDirectory' => $logs,
        'databaseCachePath' => $cachePath, 'databaseTester' => $tester,
        'databaseAvailable' => fn (): bool => $availability->available(),
        'diskSpace' => static fn (): int => PHP_INT_MAX,
    ];
    $monitor = new ApplicationHealthMonitor($monitorOptions);
    $backupManager = new ApplicationBackupManager($directory, [
        'config/auth.json' => RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE),
        'config/installation.json' => RuntimeConfiguration::path(RuntimeConfiguration::INSTALLATION_FILE),
        'config/admin.json' => RuntimeConfiguration::path(RuntimeConfiguration::ADMIN_FILE),
        'config/authorization.json' => RuntimeConfiguration::path(RuntimeConfiguration::AUTHORIZATION_FILE),
        'config/api-keys.json' => RuntimeConfiguration::path(RuntimeConfiguration::API_KEYS_FILE),
        // The registry beside the V2 file; the fixture keeps the V2 file, so
        // backups convert it (read-through migration).
        'database/config/databases.json' => dirname($databasePath) . '/databases.json',
    ], 'test', null, $runtimeDirectory . '/.backup-recovery.lock');
    $schedule = BackupSchedule::defaults();
    $logger = new Logger($logs);
    $backups = new BackupRecoveryService($backupManager, $backupDirectory, $logger,
        static fn (): array => ['healthy' => true], new OperationalLogger($logs),
        function () use (&$schedule): array { return $schedule; });
    $apiProbe = new SystemHealthProcessProbe();
    $portProbes = 0;
    $parserManager = new SqlParserProcessManager($configuration,
        new PortSelector(static function () use (&$portProbes): bool { $portProbes++; return false; }),
        null, $runtimeDirectory . '/sqlparser-process.json', $root);
    $service = new AdminService($configuration, $databasePath, $tester, $apiProbe, null, $parserManager, null,
        $availability, $logger, $monitor, $applicationRuntime, $backups);

    // 1-7. Production services are web-server managed with application availability only.
    $health = $service->status();
    systemHealthAssert(
        $health['hosting']['mode'] === 'production' && $health['hosting']['listenerManagement'] === 'web-server'
            && $health['adminConsole']['infrastructure']['managedExternally'] === true
            && $health['adminConsole']['running'] === true && $health['adminConsole']['healthy'] === true
            && $health['adminConsole']['pid'] === null && $health['adminConsole']['port'] === null
            && $health['adminConsole']['startedAt'] === null,
        'Production Admin Console health was not web-server managed or exposed worker process details.'
    );
    foreach (['api', 'sqlParser'] as $name) {
        systemHealthAssert(
            $health[$name]['pid'] === null && $health[$name]['port'] === null && $health[$name]['startedAt'] === null
                && $health[$name]['infrastructure']['managedExternally'] === true
                && $health[$name]['applicationRuntime']['enabled'] === true && $health[$name]['status'] === 'enabled',
            "Production {$name} health fabricated process metadata or misreported availability."
        );
    }
    systemHealthAssert(
        array_keys($health['monitoring']['checks']) === ['application', 'configuration', 'database', 'logging', 'encryption', 'processes', 'backup'],
        'Production System Health does not contain exactly the operational checks.'
    );
    $services = $health['monitoring']['checks']['processes']['services'];
    systemHealthAssert(
        $services['adminConsole']['pid'] === null && $services['api']['port'] === null && $services['sqlParser']['pid'] === null,
        'Detailed production process check fabricated PID or port values.'
    );
    $service->controlApi('stop');
    $service->controlSqlParser('stop');
    $disabled = $service->status();
    systemHealthAssert(
        $disabled['api']['status'] === 'disabled' && $disabled['api']['applicationRuntime']['enabled'] === false
            && $disabled['sqlParser']['status'] === 'disabled' && $disabled['sqlParser']['applicationRuntime']['enabled'] === false
            && $disabled['adminConsole']['running'] === true,
        'Production API/SQL Parser disabled state was not reflected.'
    );
    $service->controlApi('start');
    $service->controlSqlParser('start');
    systemHealthAssert($apiProbe->operations === 0 && $portProbes === 0 && !is_file($runtimeDirectory . '/sqlparser-process.json'),
        'Production health or lifecycle queried a development process manager.');

    // 9-11. Database health distinguishes disabled, connected, and unhealthy.
    $database = $service->status()['database'];
    systemHealthAssert($database['state'] === 'disabled' && $database['status'] === 'disconnected'
        && $database['reason'] === 'application_access_disabled', 'Disabled database access was not reported.');
    $service->controlDatabase('connect');
    $database = $service->status()['database'];
    systemHealthAssert($database['state'] === 'connected' && $database['status'] === 'connected' && $database['reason'] === null,
        'Connected database was not reported.');
    $connectionSucceeds = false;
    $service->controlDatabase('disconnect');
    $availability->setAvailable(true);
    $database = $service->status()['database'];
    systemHealthAssert($database['state'] === 'unhealthy' && $database['available'] === true
        && $database['reason'] === 'database_unavailable', 'A failing database was not reported as unhealthy.');

    // 18-21. Liveness is constant; readiness never connects and follows the documented contract.
    $testsBeforeReadiness = $connectionTests;
    $live = $monitor->liveness('api');
    systemHealthAssert($live['status'] === 'healthy' && !isset($live['checks']), 'Liveness performed readiness checks.');
    $ready = $monitor->readiness();
    systemHealthAssert(
        $ready['status'] === 'unhealthy' && $ready['checks']['database']['category'] === 'database_unavailable'
            && $ready['checks']['application']['category'] === 'api_enabled' && $connectionTests === $testsBeforeReadiness,
        'Readiness ignored a cached connectivity failure or opened a database connection.'
    );
    $monitor->forgetDatabaseHealth();
    $ready = $monitor->readiness();
    systemHealthAssert($ready['status'] === 'healthy' && $connectionTests === $testsBeforeReadiness,
        'Readiness opened a database connection instead of using the availability gate and configuration.');
    $applicationRuntime->control('api', 'stop');
    $ready = $monitor->readiness();
    systemHealthAssert($ready['status'] === 'unhealthy' && $ready['checks']['application']['category'] === 'api_disabled',
        'A disabled production API was reported ready.');
    $applicationRuntime->control('sqlParser', 'stop');
    $applicationRuntime->control('api', 'start');
    systemHealthAssert($monitor->readiness()['status'] === 'healthy', 'A disabled SQL Parser made the API unready.');
    $applicationRuntime->control('sqlParser', 'start');
    $availability->setAvailable(false);
    systemHealthAssert($monitor->readiness()['checks']['database']['category'] === 'database_disconnected',
        'Readiness did not enforce the database availability gate.');
    $development = new ApplicationHealthMonitor(['production' => false] + $monitorOptions);
    $applicationRuntime->control('api', 'stop');
    systemHealthAssert($development->readiness()['checks']['application']['category'] === 'process_managed',
        'Development readiness used production application availability.');
    $applicationRuntime->control('api', 'start');
    $healthSource = (string)file_get_contents($root . '/app/Health/ApplicationHealthMonitor.php');
    preg_match('/public function readiness\(\): array.*?\n    }\n/s', $healthSource, $readinessSource);
    systemHealthAssert(
        isset($readinessSource[0]) && !preg_match('/databaseTester|QueryEngine|new Database|odbc_/', $readinessSource[0])
            && !preg_match('/odbc_pconnect|static\s+\$connection/', $healthSource),
        'Readiness can open or retain a database connection.'
    );
    $connectionSucceeds = true;

    // 12-13. API, SQL Parser, and database availability stay independent.
    $service->controlApi('stop');
    systemHealthAssert($availability->available() === false, 'API availability changed database availability.');
    $service->controlApi('start');
    [$parserStatus] = (new SqlParserRequestHandler())->handle('POST', '{"sql":"SELECT ItemCode FROM Items"}', 32);
    systemHealthAssert($parserStatus === 200 && $applicationRuntime->enabled('sqlParser'),
        'SQL Parser depends on database availability.');

    // 31-33. Lifecycle and configuration changes do not leave stale database health.
    $availability->setAvailable(true);
    $connectionSucceeds = false;
    $service->status();
    systemHealthAssert(is_file($cachePath), 'Detailed health did not cache its connectivity check.');
    $connectionSucceeds = true;
    $service->controlDatabase('connect');
    systemHealthAssert(!is_file($cachePath) && $service->status()['database']['state'] === 'connected',
        'Database Connect left stale database health.');
    $service->status();
    $service->controlDatabase('disconnect');
    systemHealthAssert(!is_file($cachePath) && $service->status()['database']['state'] === 'disabled',
        'Database Disconnect left stale database health.');
    $availability->setAvailable(true);
    $connectionSucceeds = false;
    $service->status();
    $connectionSucceeds = true;
    $stored = JsonFileStore::load($databasePath);
    JsonFileStore::save($databasePath, (new DatabaseCredentialEncryption())->encryptConfiguration(
        DatabaseConfigurationResolver::resolve($stored)
    ));
    systemHealthAssert($monitor->readiness()['status'] === 'healthy'
        && $service->status()['database']['state'] === 'connected',
        'A database configuration change did not invalidate cached database health.');

    // 14-17. Encryption health detects key and configuration failures without secrets.
    $encryption = static fn (): array => $service->status()['monitoring']['checks']['encryption'];
    systemHealthAssert($encryption() === ['status' => 'healthy', 'category' => 'configured'], 'Valid encryption was not healthy.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    systemHealthAssert($encryption()['category'] === 'missing', 'A missing encryption key was not detected.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    systemHealthAssert($encryption()['category'] === 'invalid', 'An invalid encryption key was not detected.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $databaseKey);

    // 22-29. Backup health uses the existing backup service and never affects overall health.
    $backupHealth = static fn (): array => $service->status()['monitoring']['checks']['backup'];
    $fresh = $backupHealth();
    systemHealthAssert($fresh['status'] === 'not_configured' && $fresh['category'] === 'no_backups'
        && $fresh['recoveryPoints'] === 0 && !is_dir($backupDirectory),
        'A fresh installation without backups was not reported as not configured, or health created storage.');
    $freshOverall = $service->status()['monitoring'];
    $overallWithoutBackup = $monitor->detailed($freshOverall['checks']['processes']['services'])['status'];
    systemHealthAssert($freshOverall['status'] === $overallWithoutBackup, 'Missing backups changed overall System Health.');

    $created = $backups->create();
    $verified = $backupHealth();
    $history = $backups->history();
    systemHealthAssert(
        $verified['status'] === 'healthy' && $verified['category'] === 'verified' && $verified['verification'] === 'valid'
            && $verified['latestRecoveryPointId'] === $created['recoveryPointId']
            && $verified['latestRecoveryPointId'] === $history[0]['recoveryPointId']
            && $verified['latestBackupAt'] === $history[0]['createdAt'] && $verified['recoveryPoints'] === count($history),
        'Backup health did not reflect the existing verified backup history.'
    );

    JsonFileStore::save($backupDirectory . '/.schedule-status.json', [
        'version' => 1, 'attemptedAt' => gmdate(DATE_ATOM, time() + 60), 'status' => 'failed',
        'recoveryPointId' => null, 'errorCode' => 'BACKUP_STORAGE_UNAVAILABLE',
    ]);
    $failed = $backupHealth();
    systemHealthAssert($failed['status'] === 'degraded' && $failed['category'] === 'backup_failed'
        && $failed['lastScheduledAttempt']['errorCode'] === 'BACKUP_STORAGE_UNAVAILABLE',
        'A failed scheduled backup was not represented.');
    @unlink($backupDirectory . '/.schedule-status.json');

    $schedule = ['enabled' => true, 'frequency' => 'hourly', 'time' => '00:05', 'retention' => 5];
    $scheduled = $backupHealth();
    systemHealthAssert($scheduled['scheduleEnabled'] === true && $scheduled['frequency'] === 'hourly'
        && is_string($scheduled['nextBackupAt']) && $scheduled['status'] === 'healthy', 'Backup schedule was not reported.');
    $schedule = ['enabled' => true, 'frequency' => 'never'];
    systemHealthAssert($backupHealth()['category'] === 'configuration_invalid', 'Invalid backup configuration was not detected.');
    $schedule = BackupSchedule::defaults();

    putenv(BackupSigningKey::ENVIRONMENT_VARIABLE);
    putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE . '=' . $directory . '/missing-signing.key');
    systemHealthAssert($backupHealth()['category'] === 'signing_key_unavailable', 'An unavailable signing key was not detected.');
    putenv(BackupSigningKey::FILE_ENVIRONMENT_VARIABLE);
    putenv(BackupSigningKey::ENVIRONMENT_VARIABLE . '=' . $signingKey);

    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    systemHealthAssert($backupHealth()['category'] === 'verified',
        'A missing database key was misreported as a corrupt backup.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $databaseKey);

    $archive = glob($backupDirectory . '/backup-*.zip')[0];
    $original = (string)file_get_contents($archive);
    file_put_contents($archive, substr($original, 0, -40) . str_repeat("\0", 40));
    $invalid = $backupHealth();
    systemHealthAssert($invalid['status'] === 'unhealthy' && $invalid['category'] === 'verification_failed'
        && $invalid['verification'] === 'invalid', 'A failed backup verification was not represented.');
    file_put_contents($archive, $original);

    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        chmod($backupDirectory, 0500);
        $unavailable = $backupHealth();
        chmod($backupDirectory, 0700);
        systemHealthAssert($unavailable['status'] === 'unhealthy' && $unavailable['category'] === 'storage_unavailable',
            'Unavailable backup storage was not detected.');
    }

    $serialized = json_encode($service->status(), JSON_THROW_ON_ERROR);
    foreach ([$password, $databaseKey, $signingKey, 'ciphertext', 'nonce', 'GENERIC_SQL_API_ENCRYPTION_KEY',
        'GENERIC_BACKUP_SIGNING_KEY', $backupDirectory, $databasePath] as $secret) {
        systemHealthAssert(!str_contains($serialized, $secret), 'System Health exposed secret material or private paths.');
    }

    // 8. Development health keeps real managed-process details and is not web-server managed.
    putenv('GENERIC_APP_ENV=development');
    $developmentHealth = $service->status();
    systemHealthAssert(
        $developmentHealth['hosting']['mode'] === 'development'
            && $developmentHealth['api']['pid'] === 4242 && $developmentHealth['api']['port'] === 8001
            && $developmentHealth['api']['startedAt'] === '2026-10-05T00:00:00+00:00'
            && $developmentHealth['adminConsole']['pid'] === getmypid()
            && !isset($developmentHealth['adminConsole']['infrastructure'])
            && $developmentHealth['sqlParser']['status'] === 'stopped'
            && $apiProbe->operations > 0,
        'Development health no longer reports managed process details.'
    );

    echo "Production system health tests passed.\n";
} finally {
    foreach ($environment as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    systemHealthRemove($directory);
}
