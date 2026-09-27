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
        'sessionDirectory' => $sessions, 'backupDirectory' => $backups,
        'databaseCachePath' => $runtime . '/health/database.json',
        'databaseAvailable' => static fn (): bool => true,
        'databaseTester' => static function () use (&$tests): void { $tests++; },
        'diskWarningBytes' => 100, 'diskCriticalBytes' => 10,
        'diskSpace' => static fn (): int => 1000,
    ]);

    $live = $monitor->liveness('api', 8000, gmdate(DATE_ATOM, time() - 5));
    healthAssert($live['status'] === 'healthy' && $live['port'] === 8000, 'Liveness did not report a lightweight success response.');
    $ready = $monitor->readiness();
    healthAssert($ready['status'] === 'healthy' && array_keys($ready['checks']) === ['configuration', 'runtime', 'database'], 'Readiness schema or success state is invalid.');
    $restoreSafety = $monitor->restoreSafety();
    healthAssert($restoreSafety['healthy'] === true
        && $restoreSafety['check'] === 'configuration_and_encryption', 'Valid schema-version 6 configuration failed restore safety validation.');

    $processes = [
        'adminConsole' => ['running' => true, 'healthy' => true, 'status' => 'running', 'pid' => 10, 'port' => 8090],
        'api' => ['running' => true, 'healthy' => true, 'status' => 'running', 'pid' => 11, 'port' => 8000],
        'sqlParser' => ['running' => false, 'healthy' => false, 'status' => 'stopped', 'pid' => null, 'port' => null],
    ];
    $detail = $monitor->detailed($processes);
    healthAssert(isset($detail['status'], $detail['checks']['database'], $detail['checks']['filesystem']) && $tests === 1, 'Detailed health schema or database test is invalid.');
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

    @rmdir($sessions);
    $sessionFailure = $monitor->detailed($processes);
    healthAssert($sessionFailure['checks']['sessions']['category'] === 'unavailable', 'Missing session directory was not detected.');
    mkdir($sessions, 0700);
    @rmdir($logs);
    healthAssert($monitor->detailed($processes)['checks']['logging']['status'] === 'degraded', 'Logging failure did not remain fail-open/degraded.');
    mkdir($logs, 0700);

    $lowDisk = new ApplicationHealthMonitor([
        'configurationDirectory' => $configuration, 'databasePath' => $database,
        'runtimeDirectory' => $runtime, 'logDirectory' => $logs, 'sessionDirectory' => $sessions,
        'backupDirectory' => $backups, 'databaseAvailable' => static fn (): bool => true,
        'diskWarningBytes' => 100, 'diskCriticalBytes' => 10, 'diskSpace' => static fn (): int => 50,
    ]);
    healthAssert($lowDisk->detailed($processes)['checks']['filesystem']['status'] === 'degraded', 'Low disk space was not classified as warning.');
    @rmdir($backups);
    healthAssert($monitor->detailed($processes)['checks']['backup']['category'] === 'unavailable', 'Backup directory capability failure was not detected.');

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
