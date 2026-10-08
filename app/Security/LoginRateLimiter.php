<?php

require_once __DIR__ . '/SecurityStorage.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/SecurityConfiguration.php';

final class LoginRateLimiter
{
    private string $directory;
    private bool $enabled;
    private int $maximumAttempts;
    private int $windowSeconds;
    private int $lockoutSeconds;
    private $clock;

    public function __construct(?string $directory = null, ?array $options = null, ?callable $clock = null)
    {
        $options ??= SecurityConfiguration::loginRateLimitOptions();
        $this->directory = $directory ?? SecurityStorage::directory('login-rate-limit');
        $this->enabled = ($options['enabled'] ?? true) === true;
        $this->maximumAttempts = max(2, (int)($options['maximumAttempts'] ?? 5));
        $this->windowSeconds = max(60, (int)($options['windowSeconds'] ?? 900));
        $this->lockoutSeconds = max(30, (int)($options['lockoutSeconds'] ?? 300));
        $this->clock = $clock ?? fn (): int => time();
    }

    public function assertAllowed(string $sourceIp, string $username): void
    {
        if (!$this->enabled) return;
        $record = $this->read($this->key($sourceIp, $username));
        $now = ($this->clock)();
        if (($record['blockedUntil'] ?? 0) > $now) {
            $this->rateLimited((int)$record['blockedUntil'] - $now);
        }
    }

    public function recordFailure(string $sourceIp, string $username): bool
    {
        return $this->recordFailureStatus($sourceIp, $username)['locked'];
    }

    public function recordFailureStatus(string $sourceIp, string $username): array
    {
        if (!$this->enabled) return ['locked' => false, 'attemptsRemaining' => null, 'retryAfterSeconds' => null];
        $key = $this->key($sourceIp, $username);
        return $this->withLock($key, function (array $record): array {
            $now = ($this->clock)();
            $attempts = array_values(array_filter(
                is_array($record['attempts'] ?? null) ? $record['attempts'] : [],
                fn ($timestamp): bool => is_int($timestamp) && $timestamp >= $now - $this->windowSeconds
            ));
            $attempts[] = $now;
            $blocked = count($attempts) >= $this->maximumAttempts;
            $retryAfter = $blocked ? $this->lockoutSeconds : null;
            return [[
                'attempts' => $attempts,
                'blockedUntil' => $blocked ? $now + $this->lockoutSeconds : 0,
            ], [
                'locked' => $blocked,
                'attemptsRemaining' => max(0, $this->maximumAttempts - count($attempts)),
                'retryAfterSeconds' => $retryAfter,
            ]];
        });
    }

    public function reset(string $sourceIp, string $username): void
    {
        if (!$this->enabled) return;
        $key = $this->key($sourceIp, $username);
        $this->withStateLock($key, function () use ($key): void {
            $path = $this->path($key);
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('Login protection state could not be reset.');
            }
        });
    }

    public function throwRateLimited(?int $retryAfterSeconds = null): never
    {
        $this->rateLimited($retryAfterSeconds ?? $this->lockoutSeconds);
    }

    private function key(string $sourceIp, string $username): string
    {
        return hash('sha256', strtolower(trim($sourceIp)) . "\0" . strtolower(trim($username)));
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function read(string $key): array
    {
        $path = $this->path($key);
        if (!is_file($path)) return [];
        try {
            return JsonFileStore::load($path);
        } catch (Throwable $exception) {
            return [];
        }
    }

    private function withLock(string $key, callable $operation): mixed
    {
        return $this->withStateLock($key, function () use ($key, $operation): mixed {
            [$record, $result] = $operation($this->read($key));
            try {
                JsonFileStore::save($this->path($key), $record);
            } catch (Throwable $exception) {
                throw new RuntimeException('Login protection state could not be stored.');
            }
            return $result;
        });
    }

    private function withStateLock(string $key, callable $operation)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Login protection storage is unavailable.');
        }
        $lock = @fopen($this->path($key) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Login protection lock is unavailable.');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rateLimited(int $retryAfterSeconds): never
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);
        throw new ApiRequestException(
            'Too many unsuccessful login attempts.',
            'LOGIN_RATE_LIMITED',
            [[
                'path' => 'authentication',
                'message' => 'Please try again later.',
                'locked' => true,
                'retryAfterSeconds' => $retryAfterSeconds,
            ]],
            429
        );
    }
}
