<?php

require_once __DIR__ . '/RequestId.php';

/**
 * Human-readable, date-wise diagnostics. Security/audit records continue to be
 * written by Logger; this class deliberately has no audit responsibilities.
 */
final class OperationalLogger
{
    private const SUBSYSTEMS = ['api', 'admin', 'database', 'sqlparser'];
    private const LEVELS = ['INFO', 'WARNING', 'ERROR'];
    private string $baseDirectory;
    private $clock;

    public function __construct(?string $baseDirectory = null, ?callable $clock = null)
    {
        $configured = getenv('GENERIC_OPERATIONAL_LOG_DIR');
        $this->baseDirectory = rtrim($baseDirectory ?? (
            is_string($configured) && trim($configured) !== ''
                ? trim($configured) : dirname(__DIR__) . '/logs'
        ), '/\\');
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function info(string $subsystem, string $event, array $context = []): void
    {
        $this->log($subsystem, 'INFO', $event, $context);
    }

    public function warning(string $subsystem, string $event, array $context = []): void
    {
        $this->log($subsystem, 'WARNING', $event, $context);
    }

    public function error(string $subsystem, string $event, array $context = []): void
    {
        $this->log($subsystem, 'ERROR', $event, $context);
    }

    public function log(string $subsystem, string $level, string $event, array $context = []): void
    {
        if (!in_array($subsystem, self::SUBSYSTEMS, true)) return;
        $level = strtoupper($level);
        if (!in_array($level, self::LEVELS, true)) $level = 'INFO';

        try {
            $now = ($this->clock)();
            if (!$now instanceof DateTimeInterface) $now = new DateTimeImmutable('now');
            $directory = $this->baseDirectory . DIRECTORY_SEPARATOR . $subsystem;
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
            @chmod($this->baseDirectory, 0700);
            @chmod($directory, 0700);
            $path = $directory . DIRECTORY_SEPARATOR . $now->format('Y-m-d') . '.txt';
            $entry = '[' . $now->format('Y-m-d H:i:s') . '] [' . $level . '] ['
                . self::singleLine(RequestId::get()) . '] ' . self::singleLine($event) . PHP_EOL;
            foreach ($this->redact($context) as $key => $value) {
                $entry .= '    ' . self::singleLine((string)$key) . '=' . $this->formatValue($value) . PHP_EOL;
            }
            $entry .= PHP_EOL;

            $stream = @fopen($path, 'ab');
            if ($stream === false) return;
            @chmod($path, 0600);
            $locked = false;
            try {
                $locked = @flock($stream, LOCK_EX);
                $length = strlen($entry);
                if ($locked) {
                    $written = 0;
                    while ($written < $length) {
                        $bytes = @fwrite($stream, substr($entry, $written));
                        if ($bytes === false || $bytes === 0) return;
                        $written += $bytes;
                    }
                } else {
                    $written = @fwrite($stream, $entry);
                    if ($written !== $length) return;
                }
                @fflush($stream);
            } finally {
                if ($locked) @flock($stream, LOCK_UN);
                @fclose($stream);
            }
        } catch (Throwable $exception) {
            // Operational diagnostics must never break the application path.
        }
    }

    private function redact(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $name = (string)$key;
            if (preg_match('/(?:password|secret|authorization|cookie|csrf|session.?id|api.?key|encryption.?key|credential)/i', $name) === 1) {
                $safe[$name] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $safe[$name] = $this->redact($value);
            } elseif (is_null($value) || is_scalar($value)) {
                $safe[$name] = is_string($value) ? $this->redactText($value) : $value;
            } else {
                $safe[$name] = get_debug_type($value);
            }
        }
        return $safe;
    }

    private function redactText(string $value): string
    {
        $value = (string)preg_replace(
            '/(?i)\b(password|secret|authorization|cookie|csrf(?:_token)?|session(?:_id)?|x-api-key|api[_-]?key|encryption[_-]?key)\s*[=:]\s*[^\s,;}]+/',
            '$1=[REDACTED]',
            $value
        );
        foreach (['GENERIC_SQL_API_ENCRYPTION_KEY', 'GENERIC_BACKUP_SIGNING_KEY'] as $name) {
            $secret = getenv($name);
            if (is_string($secret) && $secret !== '') $value = str_replace($secret, '[REDACTED]', $value);
        }
        return self::singleLine($value);
    }

    private function formatValue($value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return 'null';
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return self::singleLine(is_string($encoded) ? $encoded : '[unavailable]');
        }
        return self::singleLine(substr((string)$value, 0, 512));
    }

    private static function singleLine(string $value): string
    {
        return trim((string)preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value));
    }
}
