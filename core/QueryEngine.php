<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/QueryTimeoutException.php';
require_once __DIR__ . '/OperationalLogger.php';
require_once __DIR__ . '/../app/Security/SecurityConfiguration.php';

class QueryEngine
{
    protected ?Database $db = null;
    protected $connection = null;
    protected Logger $logger;
    protected int $queryTimeoutSeconds;
    protected ?bool $queryTimeoutSupported = null;

    public function __construct(?Database $database = null, ?Logger $logger = null, ?int $queryTimeoutSeconds = null, bool $connect = true)
    {
        $this->logger = $logger ?? new Logger();
        $this->queryTimeoutSeconds = $queryTimeoutSeconds ?? SecurityConfiguration::queryTimeoutSeconds();
        if ($connect) {
            $started = microtime(true);
            try {
                $this->db = $database ?? new Database();
                $this->connection = $this->db->getConnection();
                (new OperationalLogger())->info('database', 'Database connection successful', [
                    'duration_ms' => round($this->elapsed($started), 2),
                ]);
            } catch (Throwable $exception) {
                (new OperationalLogger())->error('database', 'Database connection failed', [
                    'error_code' => $this->errorCategory($exception),
                    'duration_ms' => round($this->elapsed($started), 2),
                ]);
                $this->logger->audit('database.connection', 'failure', 'ERROR', [
                    'component' => 'database',
                    'errorCategory' => $this->errorCategory($exception),
                    'reason' => 'connection_failed',
                ]);
                throw $exception;
            }
        }
    }

    public function __destruct() { $this->close(); }

    public function getQuery($file)
    {
        if (!file_exists($file)) throw new Exception('SQL File Not Found : ' . $file);
        return file_get_contents($file);
    }

    public function execute($sql, array $context = [])
    {
        return $this->runStatement((string)$sql, [], true, false, $context);
    }

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        return $this->runStatement((string)$sql, $params, true, false, $context);
    }

    public function executePreparedQuery($sql, array $params = [], array $context = [])
    {
        return $this->runStatement((string)$sql, $params, true, true, $context);
    }

    private function runStatement(string $sql, array $params, bool $prepared, bool $consumeAllResults, array $context): array
    {
        $totalStarted = microtime(true);
        $statement = null;
        $phase = $prepared ? 'prepare' : 'execute';
        try {
            if ($prepared) {
                $statement = $this->prepareStatement($sql);
                if (!$statement) throw new RuntimeException($this->lastError());
                $timeoutConfigured = $this->configureStatementTimeout($statement);
                $phase = 'execute';
                if (!$timeoutConfigured && $params === []) {
                    $this->freeStatement($statement);
                    $statement = $this->executeDirect($sql);
                    $executed = $statement !== false;
                } elseif (!$timeoutConfigured) {
                    $this->freeStatement($statement);
                    $statement = $this->prepareStatement($sql);
                    $executed = $statement !== false && $this->executeStatement($statement, $params);
                } else {
                    $executed = $this->executeStatement($statement, $params);
                }
                if ($executed === false && $timeoutConfigured && $this->isUnsupportedStatementOption()) {
                    $this->markTimeoutUnsupported();
                    $this->freeStatement($statement);
                    $statement = $this->prepareStatement($sql);
                    $executed = $statement !== false && $this->executeStatement($statement, $params);
                }
                if ($executed === false) throw new RuntimeException($this->lastError());
            } else {
                $statement = $this->executeDirect($sql);
                if (!$statement) throw new RuntimeException($this->lastError());
            }

            $phase = 'fetch';
            $rows = [];
            do {
                while ($row = $this->fetchRow($statement)) $rows[] = $row;
            } while ($consumeAllResults && $this->nextResult($statement));
            $result = [
                'executionTime' => round($this->elapsed($totalStarted), 2),
                'rowsReturned' => count($rows),
                'data' => $rows,
            ];
            (new OperationalLogger())->info('database', 'Database query execution successful', [
                'duration_ms' => $result['executionTime'],
                'rows_returned' => $result['rowsReturned'],
                'resource' => is_string($context['resource'] ?? null) ? $context['resource'] : null,
            ]);
            return $result;
        } catch (Throwable $exception) {
            $converted = $this->isTimeout($exception)
                ? new QueryTimeoutException("Database query exceeded the configured {$this->queryTimeoutSeconds}-second timeout.", 0, $exception)
                : $exception;
            $this->logger->audit(
                $converted instanceof QueryTimeoutException ? 'database.query_timeout' : 'database.query_failure',
                'failure',
                $converted instanceof QueryTimeoutException ? 'WARNING' : 'ERROR',
                [
                    'component' => 'database',
                    'action' => is_string($context['queryPhase'] ?? null) ? $context['queryPhase'] : $phase,
                    'resource' => is_string($context['resource'] ?? null) ? $context['resource'] : null,
                    'errorCategory' => $this->errorCategory($converted),
                    'durationMs' => round($this->elapsed($totalStarted), 2),
                ]
            );
            (new OperationalLogger())->error('database', $converted instanceof QueryTimeoutException
                ? 'Database query timeout' : 'Database query execution failed', [
                'error_code' => $this->errorCategory($converted),
                'query_phase' => is_string($context['queryPhase'] ?? null) ? $context['queryPhase'] : $phase,
                'sql_state' => $this->safeSqlState(),
                'duration_ms' => round($this->elapsed($totalStarted), 2),
                'resource' => is_string($context['resource'] ?? null) ? $context['resource'] : null,
            ]);
            throw $converted;
        } finally {
            if ($statement) $this->freeStatement($statement);
        }
    }

    protected function prepareStatement(string $sql) { return @odbc_prepare($this->connection, $sql); }

    protected function configureStatementTimeout($statement): bool
    {
        if ($this->queryTimeoutSeconds <= 0) return true;
        if ($this->queryTimeoutSupported === false) return false;
        // ODBC statement option 0 is SQL_QUERY_TIMEOUT; type 2 selects statement options.
        if (!$this->applyStatementTimeout($statement, $this->queryTimeoutSeconds)) {
            $this->markTimeoutUnsupported();
            return false;
        }
        $this->queryTimeoutSupported = true;
        return true;
    }

    protected function applyStatementTimeout($statement, int $seconds): bool
    {
        return odbc_setoption($statement, 2, 0, $seconds);
    }

    protected function executeStatement($statement, array $params): bool { return @odbc_execute($statement, $params); }
    protected function executeDirect(string $sql) { return @odbc_exec($this->connection, $sql); }
    protected function fetchRow($statement) { return odbc_fetch_array($statement); }
    protected function nextResult($statement): bool { return @odbc_next_result($statement); }
    protected function freeStatement($statement): void { @odbc_free_result($statement); }
    protected function lastError(): string { return (string)odbc_errormsg($this->connection); }

    protected function lastSqlState(): string
    {
        return function_exists('odbc_error') ? (string)@odbc_error($this->connection) : '';
    }

    private function safeSqlState(): ?string
    {
        $state = strtoupper(trim($this->lastSqlState()));
        return preg_match('/^[A-Z0-9]{5}$/', $state) === 1 ? $state : null;
    }

    private function isUnsupportedStatementOption(): bool
    {
        return strtoupper(trim($this->lastSqlState())) === 'IM001'
            || str_contains(strtolower($this->lastError()), 'does not support this function');
    }

    private function markTimeoutUnsupported(): void
    {
        $this->queryTimeoutSupported = false;
        (new OperationalLogger())->warning('database', 'Database query timeout unsupported', [
            'error_code' => 'DRIVER_CAPABILITY',
        ]);
    }

    private function isTimeout(Throwable $exception): bool
    {
        return $exception instanceof QueryTimeoutException
            || preg_match('/(?:HYT00|HYT01|timeout|timed out|time limit)/i', $exception->getMessage()) === 1;
    }

    private function sqlState(Throwable $exception): ?string
    {
        $state = strtoupper(trim($this->lastSqlState()));
        if (preg_match('/^[A-Z0-9]{5}$/', $state) === 1) return $state;
        return preg_match('/\b([A-Z0-9]{5})\b/', strtoupper($exception->getMessage()), $matches) === 1
            ? $matches[1] : null;
    }

    private function errorCategory(Throwable $exception): string
    {
        if ($this->isTimeout($exception)) return 'timeout';
        $state = $this->sqlState($exception);
        if ($state === null) return 'driver';
        return match (substr($state, 0, 2)) {
            '08' => 'connection',
            '22' => 'data',
            '23' => 'constraint',
            '28' => 'authentication',
            '42' => 'sql',
            default => 'driver',
        };
    }

    private function elapsed(float $started): float { return (microtime(true) - $started) * 1000; }

    public function executeFile($file)
    {
        return $this->execute($this->getQuery($file), ['queryPhase' => 'metadata']);
    }

    public function close(): void
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
            $this->connection = null;
        }
    }
}
