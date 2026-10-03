<?php

require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Runtime/PortSelector.php';
require_once __DIR__ . '/../app/Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../app/Runtime/SqlParserProcessManager.php';
require_once __DIR__ . '/../app/Runtime/RuntimeDetector.php';
require_once __DIR__ . '/../app/Services/AdminService.php';

function runtimeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function runtimeFailure(callable $operation, string $message, ?string $code = null): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($code !== null) runtimeAssert($exception instanceof ApiRequestException && $exception->getErrorCode() === $code, $message);
        return;
    }
    throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/generic-api-runtime-' . bin2hex(random_bytes(6));
$configurationPath = $directory . '/admin.json';
$databasePath = $directory . '/database.json';
$databaseStatePath = $directory . '/database-state.json';
$statePath = $directory . '/api-process.json';
$parserStatePath = $directory . '/sqlparser-process.json';
$oldConfigurationPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$oldAdminStartedAt = getenv('GENERIC_ADMIN_STARTED_AT');
$oldServerPort = $_SERVER['SERVER_PORT'] ?? null;
$manager = null;
$parserManager = null;
$reservedPort = null;

try {
    mkdir($directory, 0700, true);
    $validator = new AdminRequestValidator();
    $validServer = $validator->validate([
        'action' => 'admin.server.save',
        'server' => [
            'apiPortMinimum' => 18120,
            'apiPortMaximum' => 18125,
            'parserPortMinimum' => 18126,
            'parserPortMaximum' => 18130,
            'adminPort' => 18123,
            'bindAddress' => '127.0.0.1',
        ],
    ])['server'];
    runtimeAssert($validServer['apiPortMinimum'] === 18120, 'Valid port range was not normalized.');
    runtimeAssert(
        $validator->validate(['action' => 'admin.console.restart'])['action'] === 'admin.console.restart',
        'Admin Console restart action was not accepted by the fixed action allowlist.'
    );
    runtimeFailure(fn () => $validator->validate([
        'action' => 'admin.server.save',
        'server' => [...$validServer, 'apiPortMinimum' => 18130],
    ]), 'Minimum greater than maximum was accepted.');
    runtimeFailure(fn () => $validator->validate([
        'action' => 'admin.server.save',
        'server' => [...$validServer, 'bindAddress' => '0.0.0.0'],
    ]), 'Remote bind address was accepted.');

    $probed = [];
    $selector = new PortSelector(function (string $address, int $port) use (&$probed): bool {
        $probed[] = [$address, $port];
        return $port === 18121;
    });
    runtimeAssert($selector->firstAvailable('127.0.0.1', 18120, 18122) === 18121, 'Occupied first port did not fall back.');
    runtimeAssert(count($probed) === 2, 'Port range was not searched in order.');
    runtimeFailure(
        fn () => (new PortSelector(fn (): bool => false))->firstAvailable('127.0.0.1', 18120, 18122),
        'Exhausted port range was accepted.'
    );

    $repository = new AdminConfigurationRepository($configurationPath);
    JsonFileStore::save($configurationPath, [
        'version' => 1,
        'cors' => AdminConfigurationRepository::defaults()['cors'],
        'authentication' => ['mode' => 'none'],
    ]);
    $migrated = $repository->load();
    runtimeAssert(
        $migrated['version'] === 6
            && $migrated['authentication']['mode'] === 'none'
            && isset($migrated['server'])
            && $migrated['runtime'] === RuntimeControls::defaults()
            && $migrated['backup'] === BackupSchedule::defaults()
            && !isset($migrated['features']),
        'Version-1 Admin configuration was not safely migrated.'
    );
    $versionTwo = AdminConfigurationRepository::defaults();
    $versionTwo['version'] = 2;
    $versionTwo['features'] = ['readData' => true, 'writeData' => true, 'pagination' => true, 'sorting' => true, 'metadata' => true];
    unset($versionTwo['server']['parserPortMinimum'], $versionTwo['server']['parserPortMaximum']);
    unset($versionTwo['runtime'], $versionTwo['backup']);
    JsonFileStore::save($configurationPath, $versionTwo);
    $migratedVersionTwo = $repository->load();
    runtimeAssert(
        $migratedVersionTwo['version'] === 6
            && $migratedVersionTwo['server']['parserPortMinimum'] === 8101
            && !isset($migratedVersionTwo['features']),
        'Version-2 Admin configuration was not safely migrated.'
    );
    $versionFour = AdminConfigurationRepository::defaults();
    $versionFour['version'] = 4;
    $versionFour['authentication']['mode'] = 'api_key';
    unset($versionFour['runtime']);
    JsonFileStore::save($configurationPath, $versionFour);
    $migratedVersionFour = $repository->load();
    runtimeAssert(
        $migratedVersionFour['version'] === 6
            && $migratedVersionFour['authentication']['mode'] === 'api_key'
            && $migratedVersionFour['runtime'] === RuntimeControls::defaults()
            && $migratedVersionFour['backup'] === BackupSchedule::defaults(),
        'Version-4 Admin configuration was not safely migrated.'
    );
    $configuration = AdminConfigurationRepository::defaults();
    $configuration['server'] = $validServer;
    $repository->save($configuration);
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $configurationPath);

    $runtime = (new RuntimeDetector())->information();
    runtimeAssert($runtime['operatingSystem'] !== '' && $runtime['phpVersion'] === PHP_VERSION, 'Runtime detection is incomplete.');
    runtimeAssert($runtime['runtimePath'] !== '', 'PHP runtime path was not detected.');

    $realSelector = new PortSelector();
    $first = $realSelector->firstAvailable('127.0.0.1', 18200, 18300);
    $reservedPort = stream_socket_server("tcp://127.0.0.1:{$first}", $socketErrorNumber, $socketErrorMessage);
    runtimeAssert(is_resource($reservedPort), 'Unable to reserve the first lifecycle test port.');
    $configuration['server'] = [
        'apiPortMinimum' => $first,
        'apiPortMaximum' => min(65535, $first + 5),
        'parserPortMinimum' => $first,
        'parserPortMaximum' => min(65535, $first + 5),
        'adminPort' => $first + 1,
        'bindAddress' => '127.0.0.1',
    ];
    $repository->save($configuration);
    $_SERVER['SERVER_PORT'] = (string)($first + 4);
    putenv('GENERIC_ADMIN_STARTED_AT=2026-09-23T07:00:00+00:00');
    $manager = new ApiProcessManager($repository, null, null, $statePath, dirname(__DIR__));
    $parserManager = new SqlParserProcessManager($repository, null, null, $parserStatePath, dirname(__DIR__));
    $databaseConfiguration = [
        'provider' => 'sqlserver',
        'driver' => 'auto',
        'server' => 'localhost\\SQLEXPRESS',
        'port' => '1433',
        'database' => 'ApplicationDb',
        'authentication' => 'sql',
        'username' => 'api_user',
        'password' => 'secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false],
    ];
    JsonFileStore::save($databasePath, $databaseConfiguration);
    JsonFileStore::save($databaseStatePath, ['version' => 1, 'available' => false, 'updatedAt' => null]);
    $databaseAvailability = new DatabaseAvailabilityManager($databaseStatePath);
    $connectionTests = 0;
    $connectionTester = static function () use (&$connectionTests): void { $connectionTests++; };
    $adminService = new AdminService(
        $repository,
        $databasePath,
        $connectionTester,
        $manager,
        null,
        $parserManager,
        null,
        $databaseAvailability,
        null,
        new ApplicationHealthMonitor([
            'databasePath' => $databasePath,
            'runtimeDirectory' => $directory,
            'databaseCachePath' => $directory . '/database-health.json',
            'databaseAvailable' => static fn (): bool => $databaseAvailability->available(),
            'databaseTester' => $connectionTester,
        ])
    );
    $initialApi = $manager->status();
    $initialParser = $parserManager->status();
    runtimeAssert(
        $initialApi['running'] === false
            && $initialApi['pid'] === null
            && $initialApi['port'] === null
            && $initialApi['startedAt'] === null,
        'Missing API PID state did not return a clean stopped status.'
    );
    runtimeAssert(
        $initialParser['running'] === false
            && $initialParser['pid'] === null
            && $initialParser['port'] === null
            && $initialParser['startedAt'] === null,
        'Missing SQL Parser PID state did not return a clean stopped status.'
    );
    $initialHealth = $adminService->status();
    $adminRestart = $adminService->restartAdminConsole();
    runtimeAssert(
        $adminRestart['accepted'] === true
            && $adminRestart['status'] === 'restarting'
            && $adminRestart['controlMode'] === 'application',
        'Admin Console application restart was not accepted through the existing Admin service boundary.'
    );
    runtimeAssert(
        $initialHealth['database']['available'] === false
            && $initialHealth['database']['status'] === 'disconnected'
            && $connectionTests === 0,
        'Database did not start disconnected or health polling opened a connection.'
    );
    runtimeAssert(
        $initialHealth['adminConsole']['pid'] === getmypid()
            && $initialHealth['adminConsole']['port'] === $first + 4
            && $initialHealth['adminConsole']['startedAt'] === '2026-09-23T07:00:00+00:00',
        'System Health did not use the Admin process runtime PID, port, and start time.'
    );
    $started = $manager->start();
    runtimeAssert(
        $started['running'] && $started['healthy']
            && $started['port'] === $first + 2
            && is_int($started['pid'])
            && is_string($started['startedAt']),
        'API did not select and report the first actual available non-admin port.'
    );
    $parserStarted = $parserManager->start();
    runtimeAssert(
        $parserStarted['running'] && $parserStarted['healthy']
            && $parserStarted['service'] === 'sqlparser'
            && $parserStarted['port'] === $first + 3
            && is_int($parserStarted['pid'])
            && is_string($parserStarted['startedAt']),
        'SQL Parser did not select and report its actual available port.'
    );
    $runningHealth = $adminService->status();
    runtimeAssert(
        $runningHealth['api']['port'] === $started['port']
            && $runningHealth['api']['pid'] === $started['pid']
            && $runningHealth['api']['startedAt'] === $started['startedAt']
            && $runningHealth['sqlParser']['port'] === $parserStarted['port']
            && $runningHealth['sqlParser']['pid'] === $parserStarted['pid']
            && $runningHealth['sqlParser']['startedAt'] === $parserStarted['startedAt'],
        'System Health did not expose actual managed process metadata.'
    );
    $unchangedServer = $adminService->saveServer($configuration['server']);
    runtimeAssert(
        !$unchangedServer['apiRestartRequired']
            && !$unchangedServer['parserRestartRequired']
            && !$unchangedServer['adminRestartRequired'],
        'Unchanged server configuration requested unnecessary restarts.'
    );
    runtimeFailure(
        fn () => $adminService->saveServer([...$configuration['server'], 'adminPort' => $started['port']]),
        'Running API/Admin port conflict was accepted.',
        'INVALID_ADMIN_REQUEST'
    );
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill')) {
        @posix_kill($started['pid'], defined('SIGSTOP') ? SIGSTOP : 19);
        usleep(100000);
        $busy = $manager->status();
        runtimeAssert(
            $busy['running'] === true && $busy['healthy'] === false && $busy['status'] === 'unresponsive'
                && $busy['pid'] === $started['pid'],
            'A temporarily unresponsive API process was terminated or treated as crashed.'
        );
        @posix_kill($started['pid'], defined('SIGCONT') ? SIGCONT : 18);
        usleep(200000);
    }
    $again = $manager->start();
    runtimeAssert(($again['alreadyRunning'] ?? false) === true && $again['pid'] === $started['pid'], 'Duplicate API process was started.');
    $restarted = $manager->restart();
    runtimeAssert($restarted['running'] && $restarted['healthy'], 'API restart failed.');
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill')) {
        @posix_kill($restarted['pid'], defined('SIGKILL') ? SIGKILL : 9);
        usleep(200000);
        $crashed = $manager->status();
        runtimeAssert(
            !$crashed['running']
                && $crashed['pid'] === null
                && $crashed['port'] === null
                && $crashed['startedAt'] === null,
            'Crashed API process was not recovered without stale runtime metadata.'
        );
        runtimeAssert($manager->start()['healthy'] === true, 'API could not start after crash recovery.');
    }
    $stopped = $manager->stop();
    runtimeAssert(
        $stopped['running'] === false
            && $stopped['pid'] === null
            && $stopped['port'] === null
            && $stopped['startedAt'] === null,
        'API stop retained stale runtime metadata.'
    );
    runtimeAssert($parserManager->status()['running'] === true, 'API stop terminated the SQL Parser.');
    runtimeAssert(($manager->stop()['alreadyStopped'] ?? false) === true, 'Already-stopped API was not idempotent.');
    JsonFileStore::save($statePath, ['version' => 2, 'service' => 'api', 'pid' => getmypid(), 'port' => $first, 'startedAt' => gmdate(DATE_ATOM)]);
    $stale = $manager->status();
    runtimeAssert(
        !$stale['running'] && ($stale['staleStateRecovered'] ?? false)
            && $stale['pid'] === null
            && $stale['port'] === null
            && $stale['startedAt'] === null,
        'Stale or foreign API PID was not recovered without stale runtime metadata.'
    );
    $parserRestarted = $parserManager->restart();
    runtimeAssert($parserRestarted['running'] && $parserRestarted['healthy'], 'SQL Parser restart failed.');
    if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill')) {
        @posix_kill($parserRestarted['pid'], defined('SIGKILL') ? SIGKILL : 9);
        usleep(200000);
        $parserCrashed = $parserManager->status();
        runtimeAssert(
            $parserCrashed['running'] === false
                && $parserCrashed['pid'] === null
                && $parserCrashed['port'] === null
                && $parserCrashed['startedAt'] === null,
            'Crashed SQL Parser process was not recovered without stale runtime metadata.'
        );
        runtimeAssert($parserManager->start()['healthy'] === true, 'SQL Parser could not start after crash recovery.');
    }
    $parserStopped = $parserManager->stop();
    runtimeAssert(
        $parserStopped['running'] === false
            && $parserStopped['pid'] === null
            && $parserStopped['port'] === null
            && $parserStopped['startedAt'] === null,
        'SQL Parser stop retained stale runtime metadata.'
    );
    JsonFileStore::save($parserStatePath, ['version' => 2, 'service' => 'sqlparser', 'pid' => getmypid(), 'port' => $first + 2, 'startedAt' => gmdate(DATE_ATOM)]);
    $parserStale = $parserManager->status();
    runtimeAssert(
        !$parserStale['running'] && ($parserStale['staleStateRecovered'] ?? false)
            && $parserStale['pid'] === null
            && $parserStale['port'] === null
            && $parserStale['startedAt'] === null,
        'SQL Parser stale or foreign PID was not recovered without stale runtime metadata.'
    );

    $connected = $adminService->controlDatabase('connect');
    runtimeAssert($connected['available'] === true && $connectionTests === 1, 'Database connect did not validate and enable runtime access.');
    $databaseHealth = $adminService->status()['database'];
    runtimeAssert(
        $databaseHealth['available'] === true
            && $databaseHealth['status'] === 'connected'
            && $databaseHealth['server'] === 'localhost\\SQLEXPRESS'
            && $databaseHealth['port'] === '1433'
            && $databaseHealth['database'] === 'ApplicationDb'
            && !array_key_exists('pid', $databaseHealth),
        'Database health did not expose only safe connection and runtime status fields.'
    );
    runtimeAssert(!str_contains(json_encode($databaseHealth, JSON_THROW_ON_ERROR), 'secret'), 'Database health exposed credentials.');
    runtimeAssert($adminService->controlDatabase('disconnect')['available'] === false, 'Database disconnect failed.');
    $reconnected = $adminService->controlDatabase('restart');
    runtimeAssert($reconnected['available'] === true && $connectionTests === 3, 'Database restart did not reconnect runtime access.');
    $adminService->controlDatabase('disconnect');

    $adminHtml = (string)file_get_contents(__DIR__ . '/../admin/index.php');
    runtimeAssert(str_contains($adminHtml, 'id="navigation" hidden'), 'Unauthenticated navigation is not hidden.');
    foreach (['System Health', 'System Info', 'Configuration', 'Users'] as $label) {
        runtimeAssert(str_contains($adminHtml, $label), "Authenticated navigation is missing {$label}.");
    }
    runtimeAssert(!str_contains($adminHtml, 'SQL parser'), 'SQL Parser remains coupled to Admin navigation.');
    $adminServiceSource = (string)file_get_contents(__DIR__ . '/../app/Services/AdminService.php');
    runtimeAssert(!str_contains($adminServiceSource, 'SqlGenerator'), 'Admin service remains coupled to SQL Parser.');
    $parserIndex = (string)file_get_contents(__DIR__ . '/../sqlparser/index.php');
    runtimeAssert(!str_contains($parserIndex, 'Admin') && !str_contains($parserIndex, 'Database'), 'SQL Parser entry point depends on Admin or Database.');
    $windowsLauncher = (string)file_get_contents(__DIR__ . '/../start-windows.bat');
    $linuxLauncher = (string)file_get_contents(__DIR__ . '/../start-linux.sh');
    runtimeAssert(str_contains($windowsLauncher, 'runtime\windows\php\php.exe'), 'Windows runtime is not automatically selected.');
    runtimeAssert(str_contains($linuxLauncher, 'runtime/linux/php/php'), 'Linux bundled runtime is not preferred.');
    foreach ([$windowsLauncher, $linuxLauncher] as $launcher) {
        runtimeAssert(str_contains($launcher, 'api-runtime-control.php') && str_contains($launcher, 'start'), 'Launcher does not establish the managed API lifecycle.');
        runtimeAssert(str_contains($launcher, 'sqlparser-runtime-control.php') && str_contains($launcher, 'start'), 'Launcher does not establish the managed SQL Parser lifecycle.');
        runtimeAssert(str_contains($launcher, 'database-runtime-control.php') && str_contains($launcher, 'connect'), 'Launcher does not enable application database runtime access.');
        runtimeAssert(str_contains($launcher, 'verify-development-runtime.php'), 'Launcher does not verify the complete development runtime.');
        runtimeAssert(str_contains($launcher, '-t') && str_contains($launcher, 'router.php'), 'Launcher does not start the independent Admin app.');
    }

    $adminJavaScript = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.js');
    $compactAdminJavaScript = str_replace('"', "'", preg_replace('/\s+/', '', $adminJavaScript));
    runtimeAssert(!str_contains(strtolower($adminJavaScript), 'test saved configuration'), 'Removed saved-configuration runtime test remains in the Admin Console.');
    runtimeAssert(!str_contains($adminJavaScript, 'Runtime access'), 'Database runtime controls remain under Configuration.');
    runtimeAssert(!str_contains($adminJavaScript, 'data-database="'), 'Legacy Configuration database runtime controls remain.');
    runtimeAssert(str_contains($adminJavaScript, 'data-database-runtime'), 'System Health database runtime controls are missing.');
    runtimeAssert(str_contains($compactAdminJavaScript, "['Port',service.port]"), 'System Health does not render actual development API and SQL Parser ports.');

    $adminApiSource = (string)file_get_contents(__DIR__ . '/../admin/api.php');
    $adminControllerSource = (string)file_get_contents(__DIR__ . '/../app/Controllers/AdminController.php');
    $adminValidatorSource = (string)file_get_contents(__DIR__ . '/../app/Requests/AdminRequestValidator.php');
    foreach ([$adminApiSource, $adminControllerSource, $adminValidatorSource] as $source) {
        runtimeAssert(!str_contains($source, 'admin.database.testCurrent'), 'Test Saved Configuration remains exposed as an Admin action.');
    }

    echo "Admin runtime management tests passed.\n";
} finally {
    if (is_resource($reservedPort)) fclose($reservedPort);
    if ($manager instanceof ApiProcessManager) {
        try { $manager->stop(); } catch (Throwable $exception) {}
    }
    if ($parserManager instanceof SqlParserProcessManager) {
        try { $parserManager->stop(); } catch (Throwable $exception) {}
    }
    foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
    $oldConfigurationPath === false
        ? putenv('GENERIC_ADMIN_CONFIG_PATH')
        : putenv('GENERIC_ADMIN_CONFIG_PATH=' . $oldConfigurationPath);
    $oldAdminStartedAt === false
        ? putenv('GENERIC_ADMIN_STARTED_AT')
        : putenv('GENERIC_ADMIN_STARTED_AT=' . $oldAdminStartedAt);
    if ($oldServerPort === null) unset($_SERVER['SERVER_PORT']);
    else $_SERVER['SERVER_PORT'] = $oldServerPort;
}
