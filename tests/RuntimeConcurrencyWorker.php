<?php

require_once __DIR__ . '/../core/JsonFileStore.php';
require_once __DIR__ . '/../app/Security/ApiRateLimiter.php';
require_once __DIR__ . '/../app/Security/LoginRateLimiter.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../app/Runtime/ApplicationRuntimeManager.php';

$mode = $argv[1] ?? '';

try {
    if ($mode === 'json-write') {
        $path = (string)($argv[2] ?? '');
        $worker = (int)($argv[3] ?? -1);
        $iterations = (int)($argv[4] ?? 0);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            JsonFileStore::save($path, [
                'version' => 1,
                'worker' => $worker,
                'iteration' => $iteration,
                'payload' => str_repeat((string)($worker % 10), 4096),
            ]);
            usleep((($worker + $iteration) % 4) * 500);
        }
        exit(0);
    }

    if ($mode === 'json-read') {
        $path = (string)($argv[2] ?? '');
        $iterations = (int)($argv[3] ?? 0);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $value = JsonFileStore::load($path);
            if (($value['version'] ?? null) !== 1
                || !is_int($value['worker'] ?? null)
                || !is_int($value['iteration'] ?? null)
                || !is_string($value['payload'] ?? null)
                || strlen($value['payload']) !== 4096) {
                throw new RuntimeException('Concurrent reader observed an invalid configuration snapshot.');
            }
            usleep(($iteration % 3) * 500);
        }
        exit(0);
    }

    if ($mode === 'api-rate') {
        $directory = (string)($argv[2] ?? '');
        $identity = (string)($argv[3] ?? '');
        $iterations = (int)($argv[4] ?? 0);
        $limiter = new ApiRateLimiter(
            $directory,
            ['enabled' => true, 'requests' => 100000, 'windowSeconds' => 60],
            static fn (): int => 1000
        );
        for ($iteration = 0; $iteration < $iterations; $iteration++) $limiter->consume($identity);
        exit(0);
    }

    if ($mode === 'login-rate') {
        $directory = (string)($argv[2] ?? '');
        $iterations = (int)($argv[3] ?? 0);
        $limiter = new LoginRateLimiter(
            $directory,
            ['enabled' => true, 'maximumAttempts' => 100000, 'windowSeconds' => 60, 'lockoutSeconds' => 30],
            static fn (): int => 2000
        );
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $limiter->recordFailure('192.0.2.25', 'Concurrent.User');
        }
        exit(0);
    }

    if ($mode === 'admin-update') {
        $path = (string)($argv[2] ?? '');
        $iterations = (int)($argv[3] ?? 0);
        $repository = new AdminConfigurationRepository($path);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $repository->update(function (array &$configuration): void {
                $current = $configuration['runtime']['query']['timeoutSeconds'];
                $configuration['runtime']['query']['timeoutSeconds'] = $current === 45 ? 46 : 45;
            });
        }
        exit(0);
    }

    if ($mode === 'admin-read') {
        $path = (string)($argv[2] ?? '');
        $iterations = (int)($argv[3] ?? 0);
        $repository = new AdminConfigurationRepository($path);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $timeout = $repository->load()['runtime']['query']['timeoutSeconds'];
            if (!in_array($timeout, [45, 46], true)) {
                throw new RuntimeException('Concurrent reader observed invalid runtime configuration.');
            }
        }
        exit(0);
    }

    if ($mode === 'process') {
        $configurationPath = (string)($argv[2] ?? '');
        $statePath = (string)($argv[3] ?? '');
        $root = (string)($argv[4] ?? '');
        $operation = (string)($argv[5] ?? '');
        $resultPath = (string)($argv[6] ?? '');
        $manager = new ApiProcessManager(
            new AdminConfigurationRepository($configurationPath),
            null,
            null,
            $statePath,
            $root
        );
        if (!in_array($operation, ['start', 'restart'], true)) {
            throw new InvalidArgumentException('Unsupported process operation.');
        }
        JsonFileStore::save($resultPath, $manager->{$operation}());
        exit(0);
    }

    if ($mode === 'application-runtime') {
        $path = (string)($argv[2] ?? '');
        $service = (string)($argv[3] ?? '');
        $iterations = (int)($argv[4] ?? 0);
        $manager = new ApplicationRuntimeManager($path);
        $operations = ['start', 'stop', 'restart'];
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $manager->control($service, $operations[$iteration % count($operations)]);
        }
        exit(0);
    }

    throw new InvalidArgumentException('Unsupported concurrency worker mode.');
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
