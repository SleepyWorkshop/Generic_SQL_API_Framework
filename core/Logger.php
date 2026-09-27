<?php

require_once __DIR__ . '/RequestId.php';
require_once __DIR__ . '/OperationalLogger.php';

class Logger
{
    private string $logDirectory;
    private ?string $logFile;
    private string $auditDirectory;
    private bool $dedicatedAuditDirectory;

    public function __construct(?string $logDirectory = null)
    {
        $this->dedicatedAuditDirectory = $logDirectory === null;
        $configured = getenv('GENERIC_LOG_DIR');
        $this->logDirectory = $logDirectory ?? (
            is_string($configured) && trim($configured) !== ''
                ? trim($configured) : __DIR__ . "/../logs"
        );
        $this->auditDirectory = $this->dedicatedAuditDirectory
            ? $this->logDirectory . '/audit' : $this->logDirectory;

        if (!is_dir($this->logDirectory)) {
            $this->attempt(fn () => mkdir($this->logDirectory, 0700, true));
        }
        if (is_dir($this->logDirectory)) {
            // POSIX permissions are enforced here; on Windows the equivalent
            // protection remains the deployer's NTFS ACL.
            $this->attempt(fn () => chmod($this->logDirectory, 0700));
        }
        if ($this->dedicatedAuditDirectory && !is_dir($this->auditDirectory)) {
            $this->attempt(fn () => mkdir($this->auditDirectory, 0700, true));
        }
        if (is_dir($this->auditDirectory)) $this->attempt(fn () => chmod($this->auditDirectory, 0700));

        $this->logFile = $this->dedicatedAuditDirectory ? null
            : $this->logDirectory . "/" . date("Y-m-d") . ".log";
    }

    public function write(string $message): void
    {
        if ($this->logFile === null) {
            (new OperationalLogger())->info(
                OperationalLogger::currentSubsystem(),
                'Application diagnostic',
                ['message' => $message]
            );
            return;
        }
        $this->writeTo($this->logFile, $message);
    }

    private function writeTo(string $path, string $message): void
    {
        $entry = $this->redactSensitiveData($message) . PHP_EOL;

        $this->attempt(function () use ($entry, $path): void {
            $stream = fopen($path, 'ab');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the application log.');
            }
            @chmod($path, 0600);

            $locked = false;
            try {
                $locked = $this->acquireLock($stream);
                $length = strlen($entry);

                if ($locked) {
                    $written = 0;
                    while ($written < $length) {
                        $bytes = fwrite($stream, substr($entry, $written));
                        if ($bytes === false || $bytes === 0) {
                            throw new RuntimeException('Unable to complete the application log write.');
                        }
                        $written += $bytes;
                    }
                } else {
                    // A local file opened with append mode uses the platform's
                    // atomic append operation. Keep this to one write so entries
                    // cannot be split/interleaved when advisory locks are absent.
                    $written = fwrite($stream, $entry);
                    if ($written !== $length) {
                        throw new RuntimeException('Unable to atomically append the application log entry.');
                    }
                }

                if (!fflush($stream)) {
                    throw new RuntimeException('Unable to flush the application log.');
                }
            } finally {
                if ($locked) {
                    $this->releaseLock($stream);
                }
                fclose($stream);
            }
        });
    }

    private function redactSensitiveData(string $message): string
    {
        $message = (string)preg_replace(
            '/(?i)\b(authorization)\s*:\s*(?:basic|bearer)\s+[^\s,}]+/',
            '$1: [REDACTED]',
            $message
        );
        $message = (string)preg_replace(
            '/(?i)\b(cookie|set-cookie)\s*:\s*[^\r\n]+/',
            '$1: [REDACTED]',
            $message
        );
        $message = (string)preg_replace_callback(
            '/(?i)((?:["\']?)(?:password|pwd|uid|x-api-key|api[_-]?key|csrf[_-]?token|session[_-]?id|GENERIC_SQL_API_ENCRYPTION_KEY|GENERIC_BACKUP_SIGNING_KEY)(?:["\']?)\s*[=:]\s*)("(?:\\\\.|[^"\\\\])*"|[^;"\'\s,}]+)/',
            static fn (array $match): string => $match[1]
                . (str_starts_with($match[2], '"') ? '"[REDACTED]"' : '[REDACTED]'),
            $message
        );
        $environmentKey = getenv('GENERIC_SQL_API_ENCRYPTION_KEY');
        if (is_string($environmentKey) && $environmentKey !== '') {
            $message = str_replace($environmentKey, '[REDACTED]', $message);
        }
        $backupSigningKey = getenv('GENERIC_BACKUP_SIGNING_KEY');
        if (is_string($backupSigningKey) && $backupSigningKey !== '') {
            $message = str_replace($backupSigningKey, '[REDACTED]', $message);
        }
        return $message;
    }

    protected function acquireLock($stream): bool
    {
        return flock($stream, LOCK_EX);
    }

    protected function releaseLock($stream): void
    {
        flock($stream, LOCK_UN);
    }

    /**
     * Logging is diagnostic and must not turn an API response into a failure.
     * Capture filesystem warnings locally while preserving the caller's handler.
     */
    private function attempt(callable $operation): bool
    {
        set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line
        ): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $result = $operation();
            return $result !== false;
        } catch (Throwable $exception) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    public function timing(string $phase, float $elapsedMilliseconds, array $context = []): void
    {
        if ($this->logFile === null) {
            (new OperationalLogger())->info(
                OperationalLogger::currentSubsystem(),
                ucwords(str_replace('_', ' ', $phase)),
                ['duration_ms' => round($elapsedMilliseconds, 2), ...$context]
            );
            return;
        }
        $record = [
            'timestamp' => date(DATE_ATOM),
            'requestId' => RequestId::get(),
            'event' => 'timing',
            'phase' => $phase,
            'elapsedMs' => round($elapsedMilliseconds, 2),
        ] + $context;

        $this->write((string)json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    public function security(string $event, array $context = []): void
    {
        if (is_string($context['username'] ?? null)) {
            $context['actorUsername'] = $context['username'];
        }
        $outcome = is_string($context['outcome'] ?? null)
            ? $context['outcome']
            : (is_string($context['result'] ?? null) ? $context['result'] : 'success');
        unset($context['outcome'], $context['result'], $context['username']);
        $context['component'] ??= 'security';
        $this->audit($event, $outcome, 'INFO', $context);
    }

    public function audit(string $event, string $outcome, string $severity = 'INFO', array $context = []): void
    {
        $severity = strtoupper($severity);
        if (!in_array($severity, ['INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'], true)) {
            $severity = 'NOTICE';
        }
        $safeContext = $this->safeAuditContext($context);
        if (!isset($safeContext['actorType']) && class_exists('PrincipalContext', false)) {
            $principal = PrincipalContext::current();
            if ($principal !== null) {
                $safeContext += [
                    'actorType' => $principal->authenticationType === 'api_key' ? 'api_key' : 'user',
                    'actorId' => $principal->userId,
                    'actorUsername' => $principal->username,
                    'role' => $principal->backendRole ?? $principal->frontendRole,
                    'authenticationMethod' => $principal->authenticationType,
                ];
            }
        }
        $record = [
            'timestamp' => date(DATE_ATOM),
            'requestId' => RequestId::get(),
            'recordType' => 'security_audit',
            'event' => $event,
            'outcome' => $outcome,
            'severity' => $severity,
            'component' => $safeContext['component'] ?? 'backend',
        ] + $safeContext;
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encoded)) {
            if ($this->dedicatedAuditDirectory) {
                $this->writeTo($this->auditDirectory . '/' . date('Y-m-d') . '.jsonl', $encoded);
            } else {
                $this->write($encoded);
            }
        }
    }

    private function safeAuditContext(array $context): array
    {
        $allowed = [
            'component', 'actorType', 'actorId', 'actorUsername', 'role',
            'authenticationMethod', 'sourceIp', 'action', 'resource', 'targetType',
            'targetId', 'targetUsername', 'keyId', 'fingerprint', 'ownerId',
            'reason', 'errorCategory', 'configurationCategory', 'identityType',
            'identityHash', 'pid', 'port', 'durationMs',
            'recoveryPointId', 'operation', 'verification', 'authenticity',
            'trigger', 'createdBy', 'status',
            'errorCode', 'check',
        ];
        $safe = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $context)) continue;
            $value = $context[$field];
            if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $safe[$field] = is_string($value) && strlen($value) > 256
                    ? substr($value, 0, 256)
                    : $value;
            }
        }
        return $safe;
    }

    public function safeSql(string $sql): string
    {
        // Builders normally use placeholders, but redact any literal that an
        // approved SQL resource or expression may have embedded in the text.
        $sql = (string)preg_replace("/'(?:''|[^'])*'/", "'[REDACTED]'", $sql);
        $sql = (string)preg_replace('/(?<![A-Za-z0-9_])[-+]?\d+(?:\.\d+)?(?![A-Za-z0-9_])/', '#', $sql);
        $sql = trim((string)preg_replace('/\s+/', ' ', $sql));
        return strlen($sql) > 4000 ? substr($sql, 0, 4000) . ' ...' : $sql;
    }

    public function parameterMetadata(array $params): array
    {
        return [
            'count' => count($params),
            'types' => array_map(static fn ($value): string => get_debug_type($value), $params),
        ];
    }

    public function error(
        string $sql,
        array $params,
        string $error,
        float $executionTime = 0
    ): void {

        if ($this->logFile === null) {
            (new OperationalLogger())->error(
                OperationalLogger::currentSubsystem(),
                'Database operation failed',
                [
                    'duration_ms' => round($executionTime, 2),
                    'sql' => $this->safeSql($sql),
                    'parameters' => $this->parameterMetadata($params),
                    'error' => $error,
                ]
            );
            return;
        }

        $message =
            "========================================\n";

        $message .=
            "Date : "
            . date("Y-m-d H:i:s")
            . "\n";

        $message .=
            "Status : ERROR\n";

        $message .=
            "Execution Time : "
            . round($executionTime, 2)
            . " ms\n\n";

        $message .=
            "SQL:\n"
            . $this->safeSql($sql)
            . "\n\n";

        $message .=
            "Parameters:\n"
            . json_encode(
                $this->parameterMetadata($params),
                JSON_PRETTY_PRINT
            )
            . "\n\n";

        $message .=
            "Error:\n"
            . $error
            . "\n";

        $message .=
            "========================================\n";

        $this->write($message);
    }
}
