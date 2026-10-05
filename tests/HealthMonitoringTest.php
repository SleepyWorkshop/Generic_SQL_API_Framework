<?php

require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';

function healthAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function healthWrite(string $path, array $value): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    JsonFileStore::save($path, $value);
}

function healthRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') healthRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$directory = sys_get_temp_dir() . '/generic-health-' . bin2hex(random_bytes(6));
$configuration = $directory . '/config';
$runtime = $directory . '/runtime';
$logs = $directory . '/logs';
$sessions = $directory . '/sessions';
$backups = $directory . '/backups';
$database = $directory . '/database.json';
$oldKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);

try {
    foreach ([$configuration, $runtime, $logs, $sessions, $backups] as $path) mkdir($path, 0700, true);
    healthWrite($configuration . '/auth.json', ['version' => 4, 'users' => []]);
    healthWrite($configuration . '/installation.json', ['version' => 1, 'installationId' => str_repeat('a', 64), 'initialized' => true]);
    healthWrite($configuration . '/admin.json', RuntimeConfiguration::adminDefaults());
    healthWrite($configuration . '/authorization.json', RuntimeConfiguration::authorizationDefaults());
    healthWrite($configuration . '/api-keys.json', ['version' => 3, 'keys' => []]);
    healthWrite($configuration . '/database-state.json', ['version' => 1, 'available' => true]);
    healthWrite($configuration . '/application-runtime-state.json', [
        'version' => 1,
        'generation' => 0,
        'services' => [
            'api' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
            'sqlParser' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
        ],
    ]);
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    $encrypted = (new DatabaseCredentialEncryption())->encryptConfiguration([
        'provider' => 'sqlserver', 'server' => 'fake-host', 'port' => '1433',
        'database' => 'FakeDb', 'authentication' => 'sql', 'username' => 'fake-user',
        'password' => 'fake-password', 'driver' => 'auto', 'options' => [],
    ]);
    healthWrite($database, $encrypted);

    $tests = 0;
    $monitor = new ApplicationHealthMonitor([
        'configurationDirectory' => $configuration, 'databasePath' => $database,
        'runtimeDirectory' => $runtime, 'logDirectory' => $logs,
        'backupDirectory' => $backups,
        'databaseCachePath' => $runtime . '/health/database.json',
        'databaseAvailable' => static fn (): bool => true,
        'databaseTester' => static function () use (&$tests): void { $tests++; },
        'diskWarningBytes' => 100, 'diskCriticalBytes' => 10,
        'diskSpace' => static fn (): int => 1000,
    ]);

    $live = $monitor->liveness('api', 8000, gmdate(DATE_ATOM, time() - 5));
    healthAssert($live['status'] === 'healthy' && $live['port'] === 8000, 'Liveness did not report a lightweight success response.');
    $ready = $monitor->readiness();
    healthAssert($ready['status'] === 'healthy'
        && array_keys($ready['checks']) === ['configuration', 'runtime', 'application', 'database']
        && $ready['checks']['application']['category'] === 'process_managed', 'Readiness schema or success state is invalid.');
    healthAssert($tests === 0, 'Readiness opened a database connection.');
    $restoreSafety = $monitor->restoreSafety();
    healthAssert($restoreSafety['healthy'] === true
        && $restoreSafety['check'] === 'configuration_and_encryption', 'Valid schema-version 6 configuration failed restore safety validation.');

    $processes = [
        'adminConsole' => ['running' => true, 'healthy' => true, 'status' => 'running', 'pid' => 10, 'port' => 8090],
        'api' => ['running' => true, 'healthy' => true, 'status' => 'running', 'pid' => 11, 'port' => 8000],
        'sqlParser' => ['running' => false, 'healthy' => false, 'status' => 'stopped', 'pid' => null, 'port' => null],
    ];
    $detail = $monitor->detailed($processes);
    healthAssert(isset($detail['status'])
        && array_keys($detail['checks']) === ['application', 'configuration', 'database', 'logging', 'encryption', 'processes']
        && $detail['status'] === 'healthy'
        && $detail['checks']['encryption']['category'] === 'configured'
        && $tests === 1, 'Detailed health schema, aggregation, or database test is invalid.');
    $monitor->detailed($processes);
    healthAssert($tests === 1, 'Database health cache did not prevent repeated expensive connectivity work.');
    $serialized = json_encode($detail, JSON_THROW_ON_ERROR);
    foreach (['fake-password', $key, 'ciphertext', 'GENERIC_SQL_API_ENCRYPTION_KEY'] as $secret) {
        healthAssert(!str_contains($serialized, $secret), 'Detailed health leaked sensitive configuration.');
    }

    $disconnected = new ApplicationHealthMonitor([
        'configurationDirectory' => $configuration, 'databasePath' => $database,
        'runtimeDirectory' => $runtime, 'databaseAvailable' => static fn (): bool => false,
    ]);
    healthAssert($disconnected->readiness()['status'] === 'unhealthy'
        && $disconnected->readiness()['checks']['database']['category'] === 'database_disconnected', 'Unavailable database did not fail readiness safely.');

    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    $missingKey = new ApplicationHealthMonitor(['configurationDirectory' => $configuration,
        'databasePath' => $database, 'runtimeDirectory' => $runtime, 'databaseAvailable' => static fn (): bool => true]);
    healthAssert($missingKey->readiness()['checks']['database']['category'] === 'encryption_key_missing', 'Missing key was not classified safely.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    healthAssert((new ApplicationHealthMonitor(['configurationDirectory' => $configuration,
        'databasePath' => $database, 'runtimeDirectory' => $runtime, 'databaseAvailable' => static fn (): bool => true]))
        ->readiness()['checks']['database']['category'] === 'configuration_invalid', 'Invalid key was not classified safely.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    healthWrite($configuration . '/admin.json', ['version' => 999]);
    healthAssert($monitor->readiness()['checks']['configuration']['category'] === 'configuration_invalid', 'Invalid configuration schema was not detected.');
    $invalidRestoreSafety = $monitor->restoreSafety();
    healthAssert($invalidRestoreSafety['healthy'] === false
        && $invalidRestoreSafety['check'] === 'configuration.admin.json'
        && $invalidRestoreSafety['errorCode'] === 'CONFIGURATION_INVALID', 'Restore safety did not provide a safe configuration diagnostic.');
    @unlink($configuration . '/admin.json');
    healthAssert($monitor->readiness()['checks']['configuration']['category'] === 'configuration_missing', 'Missing configuration was not detected.');
    healthWrite($configuration . '/admin.json', RuntimeConfiguration::adminDefaults());

    // Filesystem and session-directory diagnostics are not System Health checks.
    @rmdir($sessions);
    $withoutSessions = $monitor->detailed($processes);
    healthAssert(!isset($withoutSessions['checks']['sessions']) && !isset($withoutSessions['checks']['filesystem'])
        && $withoutSessions['status'] === 'healthy', 'Session or filesystem diagnostics still affect System Health.');
    mkdir($sessions, 0700);
    @rmdir($logs);
    healthAssert($monitor->detailed($processes)['checks']['logging']['status'] === 'degraded', 'Logging failure did not remain fail-open/degraded.');
    mkdir($logs, 0700);

    $diskMonitor = static fn (int $free): ApplicationHealthMonitor => new ApplicationHealthMonitor([
        'configurationDirectory' => $configuration, 'databasePath' => $database,
        'runtimeDirectory' => $runtime, 'logDirectory' => $logs,
        'databaseAvailable' => static fn (): bool => true,
        'diskWarningBytes' => 100, 'diskCriticalBytes' => 10, 'diskSpace' => static fn (): int => $free,
    ]);
    healthAssert($diskMonitor(50)->detailed($processes)['checks']['logging']['status'] === 'degraded',
        'Low disk space for logs was not classified as degraded.');
    $criticalDisk = $diskMonitor(5)->readiness();
    healthAssert($criticalDisk['status'] === 'unhealthy' && $criticalDisk['checks']['runtime']['category'] === 'disk_critical',
        'Critically low runtime disk space did not fail readiness.');
    @rmdir($backups);
    $withoutBackupDirectory = $monitor->detailed($processes);
    healthAssert(!isset($withoutBackupDirectory['checks']['backup'])
        && $withoutBackupDirectory['status'] === 'healthy', 'Backup availability still affects System Health.');
    // Supplied backup health is reported but never changes the overall status.
    foreach ([['status' => 'unhealthy', 'category' => 'storage_unavailable'], ['status' => 'not_configured', 'category' => 'no_backups']] as $backup) {
        $withBackup = $monitor->detailed($processes, $backup);
        healthAssert($withBackup['checks']['backup'] === $backup && $withBackup['status'] === 'healthy',
            'Backup health was omitted or changed overall System Health.');
    }

    // Encryption health distinguishes key and configuration failures without secrets.
    $encryptionCategory = static fn (): string => (new ApplicationHealthMonitor([
        'configurationDirectory' => $configuration, 'databasePath' => $database, 'runtimeDirectory' => $runtime,
        'logDirectory' => $logs, 'databaseAvailable' => static fn (): bool => false,
    ]))->detailed($processes)['checks']['encryption']['category'];
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=not-a-valid-key');
    healthAssert($encryptionCategory() === 'key_invalid', 'A malformed encryption key was not detected.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    healthAssert($encryptionCategory() === 'invalid', 'A wrong encryption key was not detected.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    $tampered = $encrypted;
    $tampered['ciphertext'] = base64_encode(random_bytes(64));
    healthWrite($database, $tampered);
    healthAssert($encryptionCategory() === 'invalid', 'A tampered encrypted configuration was not detected.');
    @unlink($database);
    healthAssert($encryptionCategory() === 'configuration_missing', 'A missing database configuration was not detected.');
    healthWrite($database, $encrypted);
    healthAssert($encryptionCategory() === 'configured', 'A valid encrypted configuration was not healthy.');

    $authFailure = new ApplicationHealthMonitor([
        'configurationDirectory' => $configuration, 'databasePath' => $database,
        'runtimeDirectory' => $runtime, 'databaseCachePath' => $runtime . '/health/auth-failure.json',
        'databaseAvailable' => static fn (): bool => true,
        'databaseTester' => static fn () => throw new RuntimeException('SQLSTATE 28000 login failed for fake account'),
    ]);
    healthAssert($authFailure->detailed($processes)['checks']['database']['category'] === 'authentication_failure', 'Database authentication failure was not safely categorized.');

    $source = file_get_contents(__DIR__ . '/../admin/api.php') . file_get_contents(__DIR__ . '/../app/Middleware/AdminAuthorizationMiddleware.php');
    healthAssert(str_contains($source, "'admin.health'") && str_contains($source, "'admin.manage'"), 'Detailed health is not protected by existing System Administrator authorization.');
    $endpoint = (string)file_get_contents(__DIR__ . '/../api/health.php');
    healthAssert(str_contains($endpoint, "http_response_code(\$payload['status'] === 'healthy' ? 200 : 503)"), 'Readiness HTTP status semantics are missing.');

    echo "Health monitoring tests passed.\n";
} finally {
    $oldKey === false ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE) : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    healthRemove($directory);
}
