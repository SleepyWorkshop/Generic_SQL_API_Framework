<?php

require_once __DIR__ . '/../core/JsonFileStore.php';
require_once __DIR__ . '/../app/Security/ApiRateLimiter.php';
require_once __DIR__ . '/../app/Security/LoginRateLimiter.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../app/Runtime/PortSelector.php';
require_once __DIR__ . '/../app/Runtime/ApplicationRuntimeManager.php';

function concurrencyAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function concurrencyStartWorker(array $arguments): array
{
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    $command[] = __DIR__ . '/RuntimeConcurrencyWorker.php';
    array_push($command, ...$arguments);
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    concurrencyAssert(is_resource($process), 'Unable to start concurrency worker.');
    fclose($pipes[0]);
    return [$process, $pipes, implode(' ', $arguments)];
}

function concurrencyWaitWorkers(array $workers): void
{
    foreach ($workers as [$process, $pipes, $label]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        concurrencyAssert($status === 0, "Concurrency worker {$label} failed: {$stdout}{$stderr}");
        concurrencyAssert($stderr === '', "Concurrency worker {$label} emitted diagnostics: {$stderr}");
    }
}

function concurrencyRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

function concurrencyProcessExists(int $pid): bool
{
    if ($pid < 1) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output, $code);
        return $code === 0 && str_contains(implode("\n", $output), (string)$pid);
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-runtime-concurrency-' . bin2hex(random_bytes(8));
$manager = null;

try {
    mkdir($directory, 0700, true);

    $jsonPath = $directory . '/concurrent.json';
    JsonFileStore::save($jsonPath, ['version' => 1, 'worker' => 0, 'iteration' => 0, 'payload' => str_repeat('0', 4096)]);
    $workers = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $workers[] = concurrencyStartWorker(['json-write', $jsonPath, (string)$worker, '50']);
    }
    for ($reader = 0; $reader < 2; $reader++) {
        $workers[] = concurrencyStartWorker(['json-read', $jsonPath, '200']);
    }
    concurrencyWaitWorkers($workers);
    $finalJson = JsonFileStore::load($jsonPath);
    concurrencyAssert(strlen($finalJson['payload'] ?? '') === 4096, 'Concurrent JSON writes left an incomplete document.');
    concurrencyAssert(glob($jsonPath . '.tmp.*') === [], 'Concurrent JSON writes left temporary files behind.');

    $configurationPath = $directory . '/admin.json';
    (new AdminConfigurationRepository($configurationPath))->save(AdminConfigurationRepository::defaults());
    $workers = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $workers[] = concurrencyStartWorker(['admin-update', $configurationPath, '20']);
    }
    for ($reader = 0; $reader < 2; $reader++) {
        $workers[] = concurrencyStartWorker(['admin-read', $configurationPath, '100']);
    }
    concurrencyWaitWorkers($workers);
    concurrencyAssert(
        (new AdminConfigurationRepository($configurationPath))->load()['runtime']['query']['timeoutSeconds'] === 45,
        'Concurrent runtime configuration updates lost a serialized mutation.'
    );

    $workerCount = 8;
    $operationsPerWorker = 25;
    $identity = 'api-key:concurrent-test';
    $apiDirectory = $directory . '/api-rate';
    $workers = [];
    for ($worker = 0; $worker < $workerCount; $worker++) {
        $workers[] = concurrencyStartWorker(['api-rate', $apiDirectory, $identity, (string)$operationsPerWorker]);
    }
    concurrencyWaitWorkers($workers);
    $apiRecord = JsonFileStore::load($apiDirectory . '/' . hash('sha256', $identity) . '.json');
    concurrencyAssert(
        ($apiRecord['count'] ?? null) === $workerCount * $operationsPerWorker,
        'Concurrent API rate-limit updates were lost.'
    );

    $loginDirectory = $directory . '/login-rate';
    $workers = [];
    for ($worker = 0; $worker < $workerCount; $worker++) {
        $workers[] = concurrencyStartWorker(['login-rate', $loginDirectory, (string)$operationsPerWorker]);
    }
    concurrencyWaitWorkers($workers);
    $loginKey = hash('sha256', "192.0.2.25\0concurrent.user");
    $loginRecord = JsonFileStore::load($loginDirectory . '/' . $loginKey . '.json');
    concurrencyAssert(
        count($loginRecord['attempts'] ?? []) === $workerCount * $operationsPerWorker,
        'Concurrent login rate-limit updates were lost.'
    );

    $applicationStatePath = $directory . '/application-runtime-state.json';
    JsonFileStore::save($applicationStatePath, [
        'version' => 1,
        'generation' => 0,
        'services' => [
            'api' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
            'sqlParser' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
        ],
    ]);
    $applicationWorkers = [];
    $applicationWorkerCount = 6;
    $applicationOperations = 30;
    for ($worker = 0; $worker < $applicationWorkerCount; $worker++) {
        $applicationWorkers[] = concurrencyStartWorker([
            'application-runtime',
            $applicationStatePath,
            $worker % 2 === 0 ? 'api' : 'sqlParser',
            (string)$applicationOperations,
        ]);
    }
    concurrencyWaitWorkers($applicationWorkers);
    $applicationState = JsonFileStore::load($applicationStatePath);
    concurrencyAssert(
        $applicationState['generation'] === $applicationWorkerCount * $applicationOperations,
        'Concurrent production runtime controls lost a serialized state mutation.'
    );
    concurrencyAssert(
        (new ApplicationRuntimeManager($applicationStatePath))->status('api')['controlMode'] === 'application'
            && glob($applicationStatePath . '.tmp.*') === [],
        'Concurrent production runtime controls left invalid or partial state.'
    );
    $loginLimiter = new LoginRateLimiter(
        $loginDirectory,
        ['enabled' => true, 'maximumAttempts' => 100000, 'windowSeconds' => 60, 'lockoutSeconds' => 30],
        static fn (): int => 2000
    );
    $loginLimiter->reset('192.0.2.25', 'Concurrent.User');
    concurrencyAssert(!is_file($loginDirectory . '/' . $loginKey . '.json'), 'Login rate-limit reset did not remove state under lock.');

    $corruptApiIdentity = 'corrupt-recovery';
    $corruptApiPath = $apiDirectory . '/' . hash('sha256', $corruptApiIdentity) . '.json';
    file_put_contents($corruptApiPath, '{partial');
    (new ApiRateLimiter(
        $apiDirectory,
        ['enabled' => true, 'requests' => 10, 'windowSeconds' => 60],
        static fn (): int => 1000
    ))->consume($corruptApiIdentity);
    concurrencyAssert(JsonFileStore::load($corruptApiPath)['count'] === 1, 'API rate limiter did not recover malformed state safely.');

    $port = (new PortSelector())->firstAvailable('127.0.0.1', 18600, 18900);
    $processConfiguration = AdminConfigurationRepository::defaults();
    $processConfiguration['server']['apiPortMinimum'] = $port;
    $processConfiguration['server']['apiPortMaximum'] = $port;
    $processConfiguration['server']['adminPort'] = $port === 18900 ? 18599 : $port + 1;
    $processConfigurationPath = $directory . '/process-admin.json';
    (new AdminConfigurationRepository($processConfigurationPath))->save($processConfiguration);
    $statePath = $directory . '/api-process.json';
    $root = dirname(__DIR__);
    $manager = new ApiProcessManager(new AdminConfigurationRepository($processConfigurationPath), null, null, $statePath, $root);

    $startResults = [$directory . '/start-one.json', $directory . '/start-two.json'];
    concurrencyWaitWorkers([
        concurrencyStartWorker(['process', $processConfigurationPath, $statePath, $root, 'start', $startResults[0]]),
        concurrencyStartWorker(['process', $processConfigurationPath, $statePath, $root, 'start', $startResults[1]]),
    ]);
    $firstStart = JsonFileStore::load($startResults[0]);
    $secondStart = JsonFileStore::load($startResults[1]);
    concurrencyAssert($firstStart['pid'] === $secondStart['pid'], 'Concurrent starts created duplicate API processes.');

    $restartResults = [$directory . '/restart-one.json', $directory . '/restart-two.json'];
    concurrencyWaitWorkers([
        concurrencyStartWorker(['process', $processConfigurationPath, $statePath, $root, 'restart', $restartResults[0]]),
        concurrencyStartWorker(['process', $processConfigurationPath, $statePath, $root, 'restart', $restartResults[1]]),
    ]);
    $restartPids = [
        JsonFileStore::load($restartResults[0])['pid'],
        JsonFileStore::load($restartResults[1])['pid'],
    ];
    $finalStatus = $manager->status();
    concurrencyAssert($finalStatus['running'] === true && in_array($finalStatus['pid'], $restartPids, true), 'Concurrent restarts lost final process state.');
    foreach (array_unique($restartPids) as $pid) {
        if ($pid !== $finalStatus['pid']) concurrencyAssert(!concurrencyProcessExists($pid), 'Concurrent restart left an unmanaged API process.');
    }
    $manager->stop();
    $manager = null;

    echo "Runtime concurrency tests passed.\n";
} finally {
    if ($manager instanceof ApiProcessManager) {
        try { $manager->stop(); } catch (Throwable $exception) {}
    }
    concurrencyRemoveDirectory($directory);
}
