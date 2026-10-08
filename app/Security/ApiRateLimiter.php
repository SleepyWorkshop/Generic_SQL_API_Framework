<?php

require_once __DIR__ . '/SecurityStorage.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/SecurityConfiguration.php';

final class ApiRateLimiter
{
    private string $directory;
    private array $options;
    private $clock;

    public function __construct(?string $directory = null, ?array $options = null, ?callable $clock = null)
    {
        $this->directory = $directory ?? SecurityStorage::directory('api-rate-limit');
        $this->options = $options ?? SecurityConfiguration::apiRateLimitOptions();
        $this->clock = $clock ?? fn (): int => time();
    }

    public function consume(string $identity): void
    {
        if (($this->options['enabled'] ?? true) !== true) return;
        $maximum = (int)$this->options['requests'];
        $window = (int)$this->options['windowSeconds'];
        $now = ($this->clock)();
        $key = hash('sha256', $identity);
        [$allowed, $retryAfter] = $this->withLock($key, function (array $record) use ($now, $maximum, $window): array {
            $startedAt = is_int($record['startedAt'] ?? null) ? $record['startedAt'] : $now;
            $count = is_int($record['count'] ?? null) ? $record['count'] : 0;
            if ($startedAt > $now || $now - $startedAt >= $window) {
                $startedAt = $now;
                $count = 0;
            }
            if ($count >= $maximum) {
                return [$record, [false, max(1, $window - ($now - $startedAt))]];
            }
            return [['startedAt' => $startedAt, 'count' => $count + 1], [true, 0]];
        });
        if ($allowed) return;
        if (!headers_sent()) header('Retry-After: ' . $retryAfter);
        throw new ApiRequestException('Too many requests.', 'RATE_LIMIT_EXCEEDED', [], 429);
    }

    private function withLock(string $key, callable $operation): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('API protection storage is unavailable.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('API protection lock is unavailable.');
        }
        @chmod($path . '.lock', 0600);
        try {
            $record = [];
            if (is_file($path)) {
                try {
                    $record = JsonFileStore::load($path);
                } catch (Throwable $exception) {
                    $record = [];
                }
            }
            [$next, $result] = $operation($record);
            try {
                JsonFileStore::save($path, $next);
            } catch (Throwable $exception) {
                throw new RuntimeException('API protection state could not be stored.');
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
