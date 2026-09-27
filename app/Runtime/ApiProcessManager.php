<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/PortSelector.php';
require_once __DIR__ . '/RuntimeDetector.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';

class ApiProcessManager
{
    private AdminConfigurationRepository $configuration;
    private PortSelector $ports;
    private RuntimeDetector $runtime;
    private string $statePath;
    private string $root;
    private string $service;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?PortSelector $ports = null,
        ?RuntimeDetector $runtime = null,
        ?string $statePath = null,
        ?string $root = null,
        string $service = 'api'
    ) {
        if (!in_array($service, ['api', 'sqlparser'], true)) {
            throw new InvalidArgumentException('Unsupported managed service.');
        }
        $this->service = $service;
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
        $this->ports = $ports ?? new PortSelector();
        $this->runtime = $runtime ?? new RuntimeDetector();
        $this->root = $root ?? dirname(__DIR__, 2);
        $this->statePath = $statePath
            ?? $this->root . '/runtime/' . $service . '/' . $service . '-process.json';
    }

    public function status(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            if ($state === null) return $this->stopped();
            if (!$this->processExists($state['pid']) || !$this->belongsToService($state)) {
                $this->clearState();
                return [...$this->stopped(), 'staleStateRecovered' => true];
            }
            $health = $this->health($state['port']);
            if ($health === null) {
                return [
                    'running' => true,
                    'service' => $this->service,
                    'healthy' => false,
                    'status' => 'unresponsive',
                    'port' => $state['port'],
                    'pid' => $state['pid'],
                    'startedAt' => $state['startedAt'],
                    'uptimeSeconds' => max(0, time() - strtotime($state['startedAt'])),
                    'version' => null,
                ];
            }
            return [
                'running' => true,
                'service' => $this->service,
                'healthy' => true,
                'status' => 'running',
                'port' => $state['port'],
                'pid' => $state['pid'],
                'startedAt' => $state['startedAt'],
                'uptimeSeconds' => max(0, time() - strtotime($state['startedAt'])),
                'version' => $health['version'] ?? null,
            ];
        });
    }

    public function start(): array
    {
        return $this->withLock(fn (): array => $this->startLocked());
    }

    private function startLocked(): array
    {
            $existing = $this->readState();
            if ($existing !== null && $this->processExists($existing['pid']) && $this->belongsToService($existing)) {
                $health = $this->health($existing['port']);
                if ($health !== null) {
                    return [
                        'running' => true,
                        'service' => $this->service,
                        'healthy' => true,
                        'status' => 'running',
                        'port' => $existing['port'],
                        'pid' => $existing['pid'],
                        'startedAt' => $existing['startedAt'],
                        'uptimeSeconds' => max(0, time() - strtotime($existing['startedAt'])),
                        'alreadyRunning' => true,
                        'version' => $health['version'] ?? null,
                    ];
                }
                return [
                    'running' => true,
                    'service' => $this->service,
                    'healthy' => false,
                    'status' => 'unresponsive',
                    'port' => $existing['port'],
                    'pid' => $existing['pid'],
                    'startedAt' => $existing['startedAt'],
                    'uptimeSeconds' => max(0, time() - strtotime($existing['startedAt'])),
                    'alreadyRunning' => true,
                    'version' => null,
                ];
            }
            if ($existing !== null) $this->clearState();

            $server = $this->configuration->load()['server'];
            $minimumKey = $this->service === 'api' ? 'apiPortMinimum' : 'parserPortMinimum';
            $maximumKey = $this->service === 'api' ? 'apiPortMaximum' : 'parserPortMaximum';
            $port = $this->ports->firstAvailable(
                $server['bindAddress'],
                $server[$minimumKey],
                $server[$maximumKey],
                [$server['adminPort']]
            );
            $startedAt = gmdate(DATE_ATOM);
            $command = $this->command($server['bindAddress'], $port);
            $directory = dirname($this->statePath);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException($this->label() . ' runtime directory is unavailable.');
            }
            $log = $directory . '/' . $this->service . '-server.log';
            $environmentPrefix = $this->service === 'api' ? 'GENERIC_API' : 'GENERIC_SQLPARSER';
            $environment = array_merge(is_array(getenv()) ? getenv() : [], [
                $environmentPrefix . '_PORT' => (string)$port,
                $environmentPrefix . '_STARTED_AT' => $startedAt,
                'GENERIC_ADMIN_ENABLED' => '0',
            ]);
            $process = @proc_open($command, [
                0 => ['file', $this->nullDevice(), 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, $this->root, $environment, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException($this->label() . ' process could not be started.');
            $processStatus = proc_get_status($process);
            $pid = (int)($processStatus['pid'] ?? 0);
            if ($pid < 1) {
                @proc_terminate($process);
                @proc_close($process);
                throw new RuntimeException($this->label() . ' process identifier is unavailable.');
            }
            $state = ['version' => 2, 'service' => $this->service, 'pid' => $pid, 'port' => $port, 'startedAt' => $startedAt];
            try {
                JsonFileStore::save($this->statePath, $state);
            } catch (Throwable $exception) {
                @proc_terminate($process);
                unset($process);
                if (!$this->terminateAndWait($pid)) {
                    throw new RuntimeException($this->label() . ' process cleanup failed after state storage failed.', 0, $exception);
                }
                $this->clearState();
                throw $exception;
            }
            unset($process);

            $health = null;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                usleep(100000);
                $health = $this->health($port);
                if ($health !== null) break;
                if (!$this->processExists($pid)) break;
            }
            if ($health === null) {
                if (!$this->terminateAndWait($pid)) {
                    throw new RuntimeException($this->label() . ' process failed to become healthy and could not be stopped.');
                }
                $this->clearState();
                throw new RuntimeException($this->label() . ' process failed to become healthy.');
            }
            return [
                'running' => true,
                'service' => $this->service,
                'healthy' => true,
                'status' => 'running',
                'port' => $port,
                'pid' => $pid,
                'startedAt' => $startedAt,
                'uptimeSeconds' => 0,
                'version' => $health['version'] ?? null,
            ];
    }

    public function stop(): array
    {
        return $this->withLock(fn (): array => $this->stopLocked());
    }

    private function stopLocked(): array
    {
            $state = $this->readState();
            if ($state === null) return [...$this->stopped(), 'alreadyStopped' => true];
            if ($this->processExists($state['pid']) && $this->belongsToService($state)) {
                if (!$this->terminateAndWait($state['pid'])) {
                    throw new RuntimeException($this->label() . ' process could not be stopped.');
                }
            }
            $this->clearState();
            return $this->stopped();
    }

    public function restart(): array
    {
        return $this->withLock(function (): array {
            $this->stopLocked();
            return $this->startLocked();
        });
    }

    private function command(string $address, int $port): array
    {
        $command = [];
        if (PHP_OS_FAMILY !== 'Windows' && is_executable('/usr/bin/setsid')) {
            $command[] = '/usr/bin/setsid';
        }
        $command[] = $this->runtime->runtimeBinary();
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') array_push($command, '-c', $ini);
        $documentRoot = $this->service === 'api' ? '/api' : '/sqlparser';
        array_push(
            $command,
            '-S', $address . ':' . $port,
            '-t', $this->root . $documentRoot,
            $this->root . $documentRoot . '/router.php'
        );
        return $command;
    }

    private function health(int $port): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 0.25, 'ignore_errors' => true]]);
        $json = @file_get_contents("http://127.0.0.1:{$port}/health", false, $context);
        if (!is_string($json)) return null;
        try {
            $health = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }
        return is_array($health) && ($health['status'] ?? null) === 'healthy'
            && ($health['port'] ?? null) === $port ? $health : null;
    }

    private function readState(): ?array
    {
        if (!is_file($this->statePath)) return null;
        try {
            $state = JsonFileStore::load($this->statePath);
        } catch (Throwable $exception) {
            $this->clearState();
            return null;
        }
        if (array_keys($state) !== ['version', 'service', 'pid', 'port', 'startedAt']
            || ($state['version'] ?? null) !== 2
            || ($state['service'] ?? null) !== $this->service
            || !is_int($state['pid'] ?? null) || $state['pid'] < 1
            || !is_int($state['port'] ?? null) || $state['port'] < 1 || $state['port'] > 65535
            || !is_string($state['startedAt'] ?? null) || strtotime($state['startedAt']) === false) {
            $this->clearState();
            return null;
        }
        return $state;
    }

    private function belongsToService(array $state): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $command = 'powershell.exe -NoProfile -NonInteractive -Command '
                . escapeshellarg("Get-CimInstance Win32_Process -Filter 'ProcessId = {$state['pid']}' | Select-Object -ExpandProperty CommandLine");
            exec($command, $output, $code);
            if ($code !== 0) return false;
            $commandLine = str_replace('\\', '/', implode("\n", $output));
        } else {
            $commandLine = @file_get_contents('/proc/' . $state['pid'] . '/cmdline');
        }
        $directory = $this->service === 'api' ? 'api' : 'sqlparser';
        $router = str_replace('\\', '/', $this->root . '/' . $directory . '/router.php');
        return is_string($commandLine)
            && str_contains(str_replace('\\', '/', $commandLine), $router)
            && str_contains($commandLine, '127.0.0.1:' . $state['port']);
    }

    private function processExists(int $pid): bool
    {
        if ($pid < 1) return false;
        if (PHP_OS_FAMILY === 'Windows') {
            exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output, $code);
            return $code === 0 && str_contains(implode("\n", $output), (string)$pid);
        }
        if (PHP_OS_FAMILY === 'Linux') {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if (is_string($stat)
                && preg_match('/^\d+ \(.*\) ([A-Z]) /', $stat, $matches) === 1
                && $matches[1] === 'Z') {
                return false;
            }
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
    }

    private function terminate(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /PID ' . $pid . ' /T', $output, $code);
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
            return;
        }
        $kill = is_executable('/bin/kill') ? '/bin/kill' : 'kill';
        exec(escapeshellarg($kill) . ' -TERM ' . (int)$pid . ' 2>/dev/null', $output, $code);
    }

    private function forceTerminate(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /PID ' . $pid . ' /T', $output, $code);
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
            return;
        }
        $kill = is_executable('/bin/kill') ? '/bin/kill' : 'kill';
        exec(escapeshellarg($kill) . ' -KILL ' . (int)$pid . ' 2>/dev/null', $output, $code);
    }

    private function terminateAndWait(int $pid): bool
    {
        if (!$this->processExists($pid)) return true;
        $this->terminate($pid);
        for ($attempt = 0; $attempt < 30 && $this->processExists($pid); $attempt++) {
            usleep(100000);
        }
        if (!$this->processExists($pid)) return true;
        $this->forceTerminate($pid);
        for ($attempt = 0; $attempt < 20 && $this->processExists($pid); $attempt++) {
            usleep(100000);
        }
        return !$this->processExists($pid);
    }

    private function withLock(callable $operation)
    {
        $directory = dirname($this->statePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException($this->label() . ' runtime directory is unavailable.');
        }
        $lock = @fopen($this->statePath . '.lock', 'c');
        if ($lock === false) throw new RuntimeException($this->label() . ' process lock is unavailable.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException($this->label() . ' process lock could not be acquired.');
            return $operation();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function clearState(): void
    {
        if (is_file($this->statePath)) @unlink($this->statePath);
    }

    private function stopped(): array
    {
        return [
            'running' => false,
            'service' => $this->service,
            'healthy' => false,
            'status' => 'stopped',
            'port' => null,
            'pid' => null,
            'startedAt' => null,
            'uptimeSeconds' => null,
            'version' => null,
        ];
    }

    private function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    private function label(): string
    {
        return $this->service === 'api' ? 'API' : 'SQL Parser';
    }
}
