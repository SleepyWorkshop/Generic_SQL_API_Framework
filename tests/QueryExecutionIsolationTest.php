<?php

require_once __DIR__ . '/../core/QueryEngine.php';
require_once __DIR__ . '/../core/Response.php';

function executionAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

class FakeOdbcQueryEngine extends QueryEngine
{
    public array $executions = [];
    public array $freed = [];
    public array $configuredTimeouts = [];
    public ?string $failure = null;
    public bool $timeoutOptionSupported = true;
    public string $sqlState = '42000';
    private array $rowsBySql;

    public function __construct(array $rowsBySql, Logger $logger, int $timeout = 7)
    {
        $this->rowsBySql = $rowsBySql;
        parent::__construct(null, $logger, $timeout, false);
    }

    protected function prepareStatement(string $sql)
    {
        $statement = new stdClass();
        $statement->sql = $sql;
        $statement->position = 0;
        $statement->rows = $this->rowsBySql[$sql] ?? [];
        return $statement;
    }

    protected function applyStatementTimeout($statement, int $seconds): bool
    {
        $this->configuredTimeouts[] = $seconds;
        return $this->timeoutOptionSupported;
    }

    protected function executeStatement($statement, array $params): bool
    {
        $this->executions[] = ['sql' => $statement->sql, 'params' => $params];
        return $this->failure === null;
    }

    protected function executeDirect(string $sql)
    {
        $statement = new stdClass();
        $statement->sql = $sql;
        $statement->position = 0;
        $statement->rows = $this->rowsBySql[$sql] ?? [];
        $this->executions[] = ['sql' => $sql, 'params' => []];
        return $statement;
    }

    protected function fetchRow($statement)
    {
        if ($statement->position >= count($statement->rows)) return false;
        return $statement->rows[$statement->position++];
    }

    protected function freeStatement($statement): void
    {
        $this->freed[] = $statement->sql;
    }

    protected function lastError(): string
    {
        return $this->failure ?? '';
    }

    protected function lastSqlState(): string
    {
        return $this->failure === null ? '' : $this->sqlState;
    }
}

final class TimeoutAttributeRetryEngine extends FakeOdbcQueryEngine
{
    private int $attempts = 0;

    protected function executeStatement($statement, array $params): bool
    {
        $this->executions[] = ['sql' => $statement->sql, 'params' => $params];
        $this->attempts++;
        if ($this->attempts === 1) {
            $this->failure = '[unixODBC][Driver Manager]Driver does not support this function';
            $this->sqlState = 'IM001';
            return false;
        }
        $this->failure = null;
        return true;
    }
}

class FailingConnectionDatabase extends Database
{
    public function __construct() {}
    public function getConnection() { throw new RuntimeException('Login failed password=connection-secret (28000)'); }
}

$logDirectory = sys_get_temp_dir() . '/generic-dashboard-query-tests-' . bin2hex(random_bytes(5));
$operationalDirectory = $logDirectory . '/operational';
$oldOperationalDirectory = getenv('GENERIC_OPERATIONAL_LOG_DIR');
putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $operationalDirectory);
$logger = new Logger($logDirectory);
try {
    new QueryEngine(new FailingConnectionDatabase(), $logger);
    throw new RuntimeException('A failed database connection was accepted.');
} catch (RuntimeException $exception) {
    executionAssert(str_contains($exception->getMessage(), '28000'), 'Connection failure was not propagated safely.');
}
$first = new FakeOdbcQueryEngine(['SELECT first' => [['id' => 1]]], $logger);
$second = new FakeOdbcQueryEngine(['SELECT second' => [['id' => 2]]], $logger);

$firstResult = $first->executePrepared('SELECT first', ['alpha'], ['queryPhase' => 'data']);
$secondResult = $second->executePrepared('SELECT second', ['beta'], ['queryPhase' => 'data']);
executionAssert($firstResult['data'] === [['id' => 1]], 'The first engine returned another execution\'s rows.');
executionAssert($secondResult['data'] === [['id' => 2]], 'The second engine returned another execution\'s rows.');
executionAssert($first->executions[0]['params'] === ['alpha'], 'Parameters leaked into the first execution.');
executionAssert($second->executions[0]['params'] === ['beta'], 'Parameters leaked into the second execution.');
executionAssert($first->configuredTimeouts === [7] && $second->configuredTimeouts === [7], 'Statement timeout was not independently configured.');
executionAssert($first->freed === ['SELECT first'] && $second->freed === ['SELECT second'], 'Successful statements were not released.');

$unsupportedTimeout = new FakeOdbcQueryEngine(['SELECT portable WHERE value = ?' => [['ok' => 1]]], $logger);
$unsupportedTimeout->timeoutOptionSupported = false;
$portableResult = $unsupportedTimeout->executePrepared('SELECT portable WHERE value = ?', ['safe']);
executionAssert($portableResult['data'] === [['ok' => 1]], 'Unsupported SQL_QUERY_TIMEOUT prevented valid query execution.');
executionAssert($unsupportedTimeout->configuredTimeouts === [7], 'Unsupported timeout capability was not probed exactly once.');

$retryTimeout = new TimeoutAttributeRetryEngine(['SELECT retry WHERE value = ?' => [['ok' => 2]]], $logger);
$retryResult = $retryTimeout->executePrepared('SELECT retry WHERE value = ?', ['safe']);
executionAssert($retryResult['data'] === [['ok' => 2]], 'IM001 timeout-option failure was not retried without the option.');
executionAssert(count($retryTimeout->executions) === 2, 'Unsupported timeout execution was not retried exactly once.');

$first->failure = '[Microsoft][ODBC Driver] Query timeout expired (HYT00)';
try {
    $first->executePrepared('SELECT first', ['secret-value'], ['queryPhase' => 'pagination_count']);
    throw new RuntimeException('A database timeout was accepted as success.');
} catch (QueryTimeoutException $exception) {
    executionAssert(str_contains($exception->getMessage(), '7-second'), 'Timeout message omitted the configured boundary.');
}
executionAssert(count($first->freed) === 2, 'A failed statement was not released.');

$first->failure = null;
$recovered = $first->executePrepared('SELECT first', ['fresh']);
executionAssert($recovered['rowsReturned'] === 1, 'A failed query corrupted the next execution.');
executionAssert($first->executions[2]['params'] === ['fresh'], 'Failed-query parameters leaked into the next execution.');

$first->failure = '[Microsoft][ODBC Driver][SQL Server] Syntax failure (42000) password=hunter2';
try {
    $first->executePrepared('SELECT first', ['private-filter'], ['queryPhase' => 'data']);
    throw new RuntimeException('A failed database query was accepted as success.');
} catch (RuntimeException $exception) {
    executionAssert(str_contains($exception->getMessage(), '42000'), 'Database diagnostic exception lost its SQLSTATE.');
}

$payload = Response::errorPayload('Query execution timed out.', 'QUERY_ERROR');
executionAssert($payload['success'] === false && $payload['error']['code'] === 'QUERY_ERROR', 'Timeout response broke the API error contract.');

$logFile = $operationalDirectory . '/database/' . date('Y-m-d') . '.txt';
$log = (string)file_get_contents($logFile);
executionAssert(str_contains($log, 'Database query execution successful'), 'Successful execution diagnostics were not written.');
executionAssert(str_contains($log, 'Database connection failed'), 'Connection failure was not identified.');
executionAssert(!str_contains($log, 'connection-secret'), 'A database connection secret was written to diagnostics.');
executionAssert(str_contains($log, 'Rows Returned:'), 'Fetch/result diagnostics were not written.');
executionAssert(str_contains($log, 'Query Phase: pagination_count'), 'Pagination count phase was not identified.');
executionAssert(str_contains($log, 'Sql State: 42000'), 'SQLSTATE was not written to safe server diagnostics.');
executionAssert(str_contains($log, 'Error Code: sql'), 'Database error category was not written to diagnostics.');
executionAssert(!str_contains($log, 'secret-value'), 'A sensitive parameter value was written to diagnostics.');
executionAssert(!str_contains($log, 'private-filter') && !str_contains($log, 'hunter2'), 'A sensitive diagnostic value was written to logs.');
$oldOperationalDirectory === false ? putenv('GENERIC_OPERATIONAL_LOG_DIR') : putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $oldOperationalDirectory);

echo "Query execution isolation tests passed.\n";
