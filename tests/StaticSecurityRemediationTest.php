<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../app/Middleware/LocalAdminMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Services/MetadataService.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/SqlRepository.php';
require_once __DIR__ . '/../app/Repositories/Query/SqlExpressionBuilder.php';
require_once __DIR__ . '/../app/Resources/RoutineResolver.php';
require_once __DIR__ . '/../app/Security/DatabaseTransportSecurity.php';
require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';
require_once __DIR__ . '/../database/drivers/SqlServerDriver.php';

/*
 * Regression coverage for the v2.1.2 static security remediation
 * (docs/security/Security-Verification.md).
 */

function remediationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function remediationFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

function remediationRejects(callable $operation, string $code, string $message): void
{
    $failure = remediationFailure($operation, $message);
    remediationAssert(
        $failure instanceof ApiRequestException && $failure->getErrorCode() === $code,
        $message . ' Unexpected failure: ' . get_class($failure) . ' '
            . ($failure instanceof ApiRequestException ? $failure->getErrorCode() : $failure->getMessage())
    );
}

function remediationSql(string $sql): string
{
    return trim((string)preg_replace('/\s+/', ' ', $sql));
}

function remediationRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') remediationRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function remediationStartServer(string $root, string $boundary, array $environment): array
{
    $port = null;
    foreach (range(19100 + random_int(0, 40) * 10, 19899) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    remediationAssert($port !== null, 'Unable to reserve a test port.');
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-S', "127.0.0.1:{$port}", '-t', "{$root}/{$boundary}", "{$root}/{$boundary}/router.php");
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
        $root,
        array_merge(is_array(getenv()) ? getenv() : [], $environment)
    );
    remediationAssert(is_resource($server), "Unable to start the {$boundary} test server.");
    $deadline = microtime(true) + 10;
    while (!($probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2)) && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (!is_resource($probe)) {
        proc_terminate($server);
        proc_close($server);
        throw new RuntimeException("The {$boundary} test server did not start.");
    }
    fclose($probe);
    return [$server, $port];
}

/** POST JSON to a test server, keeping the session cookie in $cookie. */
function remediationPost(int $port, string $path, array $json, ?string &$cookie = null, ?string $csrf = null): array
{
    $headers = "Content-Type: application/json\r\n";
    if ($cookie !== null) $headers .= "Cookie: {$cookie}\r\n";
    if ($csrf !== null) $headers .= "X-CSRF-Token: {$csrf}\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers, 'content' => json_encode($json, JSON_THROW_ON_ERROR),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    remediationAssert($body !== false && $responseHeaders !== [], "Test server did not answer POST {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $status);
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match) === 1) $cookie = $match[1];
    }
    $decoded = json_decode((string)$body, true);
    return ['status' => (int)$status[1], 'json' => is_array($decoded) ? $decoded : null];
}

function remediationPrincipal(?string $backendRole, bool $frontendAccess = false, ?string $frontendRole = null, string $type = 'session'): Principal
{
    return new Principal(bin2hex(random_bytes(16)), 'remediation.user', $type, $backendRole, $frontendAccess, $frontendRole, true);
}

class RemediationRecordingEngine extends QueryEngine
{
    public array $executions = [];

    public function __construct() {}

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->executions[] = compact('sql', 'params', 'context');
        if (str_contains($sql, 'compatibility_level')) return ['data' => [['CompatibilityLevel' => 150]]];
        if (str_contains($sql, 'COUNT(*) AS TotalRows')) return ['data' => [['TotalRows' => 1]]];
        return ['executionTime' => 0.1, 'rowsReturned' => 1, 'data' => [['Value' => 1]]];
    }

    public function executePreparedQuery($sql, array $params = [], array $context = [])
    {
        return $this->executePrepared($sql, $params, $context);
    }
}

/** Every table and column exists; source authorization is the only gate. */
class RemediationMetadata extends MetadataRepository
{
    public int $routineLookups = 0;

    /** @param array|null $tables Catalog table names; null means every name exists. */
    public function __construct(private array $columns = [], private ?array $tables = null) {}
    public function tableExists($table)
    {
        return $this->tables === null || in_array(strtolower((string)$table), $this->tables, true);
    }
    public function getRoutine(string $schema, string $name): ?array
    {
        $this->routineLookups++;
        return [
            'dbo.readreport' => ['type' => 'procedure', 'parameters' => 1],
            'dbo.writereport' => ['type' => 'procedure', 'parameters' => 0],
            'reports.daily' => ['type' => 'procedure', 'parameters' => 0],
            'dbo.score' => ['type' => 'function', 'parameters' => 1],
            'dbo.rows' => ['type' => 'tableFunction', 'parameters' => 1],
        ][strtolower($schema . '.' . $name)] ?? null;
    }
    public function columnExists($table, $column)
    {
        return $this->columns === [] || in_array(strtolower($column), $this->columns[strtolower((string)$table)] ?? [], true);
    }
    public function getColumnDataType($table, $column) { return 'varchar'; }
    public function getColumns($table) { return ['data' => [['COLUMN_NAME' => 'Id'], ['COLUMN_NAME' => 'Name']]]; }
    public function getTables() { return ['executionTime' => 0.1, 'rowsReturned' => 3, 'data' => [['TABLE_NAME' => 'Orders'], ['TABLE_NAME' => 'Secret'], ['TABLE_NAME' => 'Lines']]]; }
    public function getViews() { return ['executionTime' => 0.1, 'rowsReturned' => 2, 'data' => [['TABLE_NAME' => 'SecretView'], ['TABLE_NAME' => 'orders']]]; }
    public function getProcedures() { return ['executionTime' => 0.1, 'rowsReturned' => 3, 'data' => [['ROUTINE_NAME' => 'ReadReport'], ['ROUTINE_NAME' => 'sp_secret'], ['ROUTINE_NAME' => 'Other']]]; }
    public function schema()
    {
        return ['executionTime' => 0.1, 'rowsReturned' => 3, 'data' => [
            ['TABLE_NAME' => 'Orders', 'COLUMN_NAME' => 'Id'],
            ['TABLE_NAME' => 'Secret', 'COLUMN_NAME' => 'Password'],
            ['TABLE_NAME' => 'Lines', 'COLUMN_NAME' => 'Id'],
        ]];
    }
}

/** Drives the real fetch loop of QueryEngine with in-memory rows. */
class RemediationFetchEngine extends QueryEngine
{
    private array $pending = [];

    public function __construct(private int $rowCount)
    {
        $this->logger = new Logger(sys_get_temp_dir() . '/generic-remediation-logger-' . getmypid());
    }

    protected function prepareStatement(string $sql) { $this->pending = array_fill(0, $this->rowCount, ['Value' => 1]); return 'statement'; }
    protected function configureStatementTimeout($statement): bool { return true; }
    protected function executeStatement($statement, array $params): bool { return true; }
    protected function fetchRow($statement) { return array_shift($this->pending) ?? false; }
    protected function nextResult($statement): bool { return false; }
    protected function freeStatement($statement): void {}
}

/** Records driver attempts instead of opening ODBC connections. */
class RemediationDriver extends SqlServerDriver
{
    public array $attempts = [];

    public function __construct(array $configuration, bool $production, private string $error)
    {
        parent::__construct($configuration, $production);
    }

    protected function openConnection(string $dsn, string $authentication, string $username, string $password)
    {
        preg_match('/^Driver=\{([^}]+)\}/', $dsn, $match);
        $this->attempts[] = $match[1];
        return false;
    }

    protected function connectionError(): string
    {
        return $this->error;
    }
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/generic-static-remediation-' . bin2hex(random_bytes(8));
$configurationDirectory = $directory . '/config';
$logDirectory = $directory . '/logs';
$oldConfigurationDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');
$oldLogDirectory = getenv('GENERIC_LOG_DIR');
$oldAdminEnabled = getenv('GENERIC_ADMIN_ENABLED');
$oldMaxRows = getenv('GENERIC_MAX_RESULT_ROWS');
$oldRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$servers = [];

try {
    mkdir($configurationDirectory, 0700, true);
    mkdir($logDirectory, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    putenv('GENERIC_LOG_DIR=' . $logDirectory);
    RuntimeConfiguration::ensure();
    $authPath = RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE);

    // SSA-02 / SSA-04: the public API exposes neither first-run setup nor
    // backend identity, API-key, or role administration.
    $environment = [
        'GENERIC_APP_ENV' => 'development',
        'GENERIC_RUNTIME_CONFIG_DIR' => $configurationDirectory,
        'GENERIC_LOG_DIR' => $logDirectory,
        'GENERIC_ADMIN_ENABLED' => '0',
    ];
    [$servers['api'], $apiPort] = remediationStartServer($root, 'api', $environment);
    [$servers['adminDisabled'], $disabledAdminPort] = remediationStartServer($root, 'admin', $environment);
    [$servers['admin'], $adminPort] = remediationStartServer($root, 'admin', ['GENERIC_ADMIN_ENABLED' => '1'] + $environment);

    $setup = [
        'action' => 'setup.createAdmin', 'name' => 'Remediation Admin', 'username' => 'Remediation.Admin',
        'mobile' => '+15550100100', 'email' => 'admin@example.invalid',
        'password' => 'remediation-password-1', 'passwordConfirmation' => 'remediation-password-1',
    ];
    $status = remediationPost($apiPort, '/', ['action' => 'setup.status']);
    remediationAssert($status['status'] === 200 && ($status['json']['data'][0]['initialized'] ?? null) === false,
        'setup.status is no longer public.');
    foreach ([
        $setup,
        ['action' => 'auth.users.list'],
        ['action' => 'auth.users.create', 'username' => 'Intruder', 'password' => 'intruder-password-1'],
        ['action' => 'auth.users.assignAuthorization', 'username' => 'Intruder'],
        ['action' => 'auth.apiKeys.list'],
        ['action' => 'auth.apiKeys.create', 'name' => 'intruder'],
        ['action' => 'auth.roles.list'],
    ] as $request) {
        $response = remediationPost($apiPort, '/', $request);
        remediationAssert($response['status'] === 404 && ($response['json']['error']['code'] ?? null) === 'NOT_FOUND',
            "Public API still dispatches {$request['action']}.");
    }
    remediationAssert((new AuthRepository($authPath))->load()['users'] === [], 'Public setup created an administrator.');
    $frontendUsers = remediationPost($apiPort, '/', ['action' => 'auth.frontendUsers.list']);
    remediationAssert($frontendUsers['status'] === 401 && ($frontendUsers['json']['error']['code'] ?? null) === 'AUTHENTICATION_REQUIRED',
        'auth.frontendUsers.* is no longer routed by the public API.');

    // The Admin API refuses setup when the Admin boundary flag is not enabled.
    $disabledCookie = null;
    $disabledToken = remediationPost($disabledAdminPort, '/api.php', ['action' => 'auth.csrf'], $disabledCookie)['json']['data'][0]['csrfToken'] ?? null;
    remediationAssert(is_string($disabledToken), 'Admin session actions are unavailable without the Admin flag.');
    $disabledSetup = remediationPost($disabledAdminPort, '/api.php', $setup, $disabledCookie, $disabledToken);
    remediationAssert($disabledSetup['status'] === 404, 'Admin setup succeeded without GENERIC_ADMIN_ENABLED=1.');
    remediationAssert((new AuthRepository($authPath))->load()['users'] === [], 'Disabled Admin boundary created an administrator.');

    // The enabled loopback Admin API creates the first administrator exactly once.
    $cookie = null;
    $token = remediationPost($adminPort, '/api.php', ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    $created = remediationPost($adminPort, '/api.php', $setup, $cookie, $token);
    remediationAssert($created['status'] === 201, 'Loopback Admin setup failed.');
    $again = remediationPost($adminPort, '/api.php', ['username' => 'Second.Admin'] + $setup, $cookie, $token);
    remediationAssert($again['status'] === 409 && ($again['json']['error']['code'] ?? null) === 'INSTALLATION_ALREADY_INITIALIZED',
        'Setup remained available after initialization.');
    remediationAssert(count((new AuthRepository($authPath))->load()['users']) === 1, 'Setup created more than one administrator.');

    // Loopback and flag enforcement covers every non-session Admin API action.
    foreach (['setup.createAdmin', 'auth.users.list', 'auth.users.create', 'auth.apiKeys.create', 'auth.roles.list', 'admin.status'] as $action) {
        putenv('GENERIC_ADMIN_ENABLED=1');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        remediationRejects(fn () => (new LocalAdminMiddleware())->handle(['action' => $action]), 'NOT_FOUND', "Remote {$action} reached the Admin API.");
        putenv('GENERIC_ADMIN_ENABLED=0');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        remediationRejects(fn () => (new LocalAdminMiddleware())->handle(['action' => $action]), 'NOT_FOUND', "{$action} ran without the Admin flag.");
        putenv('GENERIC_ADMIN_ENABLED=1');
        (new LocalAdminMiddleware())->handle(['action' => $action]);
    }
    putenv('GENERIC_ADMIN_ENABLED=0');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    foreach (['setup.status', 'auth.csrf', 'auth.login', 'auth.session', 'auth.logout'] as $action) {
        (new LocalAdminMiddleware())->handle(['action' => $action]);
    }

    // SSA-01 (v2.1.0 generic authorization): routines are not registered. A routine name resolves only to
    // a user routine of the matching type in the configured database's catalog,
    // and argument counts follow its declared parameters.
    $routineMetadata = new RemediationMetadata();
    $routines = new RoutineResolver($routineMetadata);
    foreach (['sp_executesql', 'dbo.sp_executesql', 'xp_cmdshell', 'sys.sp_rename', 'SYS.objects', 'INFORMATION_SCHEMA.ROUTINES',
        'master.dbo.xp_cmdshell', 'otherdb.dbo.ReadReport', 'dbo.ReadReport;DROP', '[dbo].[ReadReport]', '', null, ['dbo.ReadReport']] as $unsafe) {
        $lookups = $routineMetadata->routineLookups;
        remediationRejects(fn () => $routines->resolve($unsafe, 'procedure', []), 'INVALID_ROUTINE', 'An unsafe routine name resolved.');
        remediationAssert($routineMetadata->routineLookups === $lookups, 'An unsafe routine name reached the catalog lookup.');
    }
    remediationRejects(fn () => $routines->resolve('dbo.Missing', 'procedure', []), 'INVALID_ROUTINE', 'A routine absent from the catalog resolved.');
    remediationRejects(fn () => $routines->resolve('dbo.Score', 'procedure', [1]), 'INVALID_ROUTINE', 'A function was callable as a procedure.');
    remediationRejects(fn () => $routines->resolve('dbo.ReadReport', 'tableFunction', [1]), 'INVALID_ROUTINE', 'A procedure was callable as a table function.');
    remediationRejects(fn () => $routines->resolve('dbo.Rows', 'function', [1]), 'INVALID_ROUTINE', 'A table function was callable as a scalar function.');
    remediationAssert(!is_file($root . '/config/routine-resources.php'), 'A routine registry is shipped again.');

    $routineEngine = new RemediationRecordingEngine();
    $routineRepository = new QueryRepository($routineEngine, $routineMetadata);
    $hostile = "x'; DROP TABLE Orders; --";
    $routineRepository->procedure(['procedure' => 'ReadReport', 'params' => [$hostile]]);
    $execution = end($routineEngine->executions);
    remediationAssert($execution['sql'] === 'EXEC [dbo].[ReadReport] ?' && $execution['params'] === [$hostile],
        'Procedure SQL is not built from validated identifiers with bound arguments.');
    $routineRepository->procedure(['procedure' => 'reports.Daily', 'params' => []]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'EXEC [reports].[Daily]', 'A schema-qualified procedure was not quoted.');
    $routineRepository->procedure(['procedure' => 'dbo.ReadReport', 'params' => []]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'EXEC [dbo].[ReadReport]', 'Procedure parameter defaults were not allowed.');
    $routineRepository->function(['function' => 'dbo.Score', 'params' => [1]]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'SELECT [dbo].[Score](?) AS Result', 'Function SQL is wrong.');
    $routineRepository->tableFunction(['function' => 'dbo.Rows', 'params' => [1]]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'SELECT * FROM [dbo].[Rows](?)', 'Table function SQL is wrong.');
    $executionCount = count($routineEngine->executions);
    remediationRejects(fn () => $routineRepository->procedure(['procedure' => 'dbo.ReadReport', 'params' => [1, 2]]),
        'INVALID_ROUTINE_PARAMETERS', 'Too many procedure arguments were accepted.');
    remediationRejects(fn () => $routineRepository->function(['function' => 'dbo.Score', 'params' => []]),
        'INVALID_ROUTINE_PARAMETERS', 'A function was called without its declared arguments.');
    remediationRejects(fn () => $routineRepository->procedure(['procedure' => 'sp_executesql', 'params' => ['SELECT 1']]),
        'INVALID_ROUTINE', 'The repository executed a system procedure.');
    remediationAssert(count($routineEngine->executions) === $executionCount, 'A rejected routine reached the database.');

    // Routine authorization is permission-only and identical for sessions and
    // API keys: functions need routine.execute; procedures, which can change
    // data, also need data.write.
    $routineAuthorization = new AuthorizationMiddleware(new AuthorizationService());
    $routineRequest = fn (string $action, $id): array => ['action' => $action, 'source' => [$action === 'procedure' ? 'procedure' : 'function' => $id]];
    foreach (['session', 'api_key'] as $type) {
        PrincipalContext::set(remediationPrincipal('read-only', false, null, $type));
        $routineAuthorization->handle($routineRequest('function', 'dbo.Score'));
        $routineAuthorization->handle($routineRequest('tableFunction', 'dbo.Rows'));
        remediationRejects(fn () => $routineAuthorization->handle($routineRequest('procedure', 'dbo.ReadReport')),
            'AUTHORIZATION_DENIED', "A read-only {$type} principal executed a stored procedure.");
        PrincipalContext::set(remediationPrincipal('data-operator', false, null, $type));
        $routineAuthorization->handle($routineRequest('procedure', 'dbo.WriteReport'));
    }
    PrincipalContext::set(remediationPrincipal(null, true, 'application-administrator'));
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('procedure', 'dbo.ReadReport')),
        'AUTHORIZATION_DENIED', 'frontend.read authorized a routine.');
    PrincipalContext::set(remediationPrincipal(null, true));
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('function', 'dbo.Score')),
        'AUTHORIZATION_DENIED', 'Frontend access authorized a routine.');

    // SSA-03 (v2.1.0 generic authorization): JSON Query Mode has no table registry. Any table or view the
    // configured database's catalog confirms is readable with data.read, in
    // every query position; names the catalog does not confirm (system objects,
    // other databases, unknown names) never reach SQL execution.
    remediationAssert(!is_file($root . '/config/query-sources.php'), 'A query-source registry is shipped again.');
    PrincipalContext::set(remediationPrincipal('read-only'));
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $queryEngine = new RemediationRecordingEngine();
    $queries = new QueryRepository($queryEngine, new RemediationMetadata([], ['orders', 'secret', 'lines']));
    $run = function (array $request) use ($validator, $normalizer, $queries): array {
        $validator->validate($request);
        return $queries->select($normalizer->normalize($request));
    };
    $generic = [
        'source' => ['action' => 'select', 'source' => ['table' => 'Secret'], 'fields' => ['Id']],
        'join' => ['action' => 'select', 'source' => ['table' => 'Orders', 'alias' => 'O'], 'fields' => ['O.Id'],
            'joins' => [['type' => 'INNER', 'source' => ['table' => 'Secret', 'alias' => 'S'], 'on' => ['left' => 'O.Id', 'right' => 'S.Id']]]],
        'subquery' => ['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => ['Id'],
            'filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Secret'], 'fields' => ['Id']]]]],
        'union' => ['action' => 'union', 'queries' => [
            ['source' => ['table' => 'Orders'], 'fields' => ['Id']],
            ['source' => ['table' => 'Secret'], 'fields' => ['Id']],
        ]],
        'cte' => ['action' => 'select', 'with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Secret'], 'fields' => ['Id']]],
            'source' => ['table' => 'Recent'], 'fields' => ['Id']],
        'filter-sort-page' => ['action' => 'select', 'source' => ['table' => 'Lines'], 'fields' => ['Id', 'Name'],
            'filters' => [['field' => 'Name', 'operator' => '=', 'value' => 'branch-10']],
            'sort' => [['field' => 'Name', 'direction' => 'DESC']], 'pagination' => ['page' => 2, 'pageSize' => 10]],
    ];
    foreach ($generic as $placement => $request) {
        $before = count($queryEngine->executions);
        $run($request);
        remediationAssert(count($queryEngine->executions) > $before, "A catalog-confirmed {$placement} source required a registration.");
    }
    $pagedSql = remediationSql(end($queryEngine->executions)['sql']);
    remediationAssert(str_contains($pagedSql, 'FROM Lines') && str_contains($pagedSql, 'Name = ?') && !str_contains($pagedSql, 'branch-10'),
        'Generic filtering, sorting, or pagination was not applied with bound values.');
    foreach (['sys.objects', 'master.dbo.Users', 'Missing'] as $unknown) {
        $before = count($queryEngine->executions);
        $failure = remediationFailure(fn () => $run(['action' => 'select', 'source' => ['table' => $unknown], 'fields' => ['Id']]),
            "A table the catalog does not confirm ({$unknown}) was queried.");
        remediationAssert(count($queryEngine->executions) === $before, "An unconfirmed source ({$unknown}) reached the database.");
    }
    // CTE names stay local, even when they shadow a physical table name.
    $run(['action' => 'select', 'with' => ['name' => 'Secret', 'query' => ['source' => ['table' => 'Orders'], 'fields' => ['Id']]],
        'source' => ['table' => 'Secret'], 'fields' => ['Id']]);
    $cteSql = remediationSql(end($queryEngine->executions)['sql']);
    remediationAssert(str_contains($cteSql, 'WITH Secret') && str_contains($cteSql, 'FROM Orders'), 'A local CTE name was treated as a physical source.');
    foreach ([
        ['action' => 'select', 'with' => ['name' => 'dbo.Secret', 'query' => ['source' => ['table' => 'Orders'], 'fields' => ['Id']]],
            'source' => ['table' => 'dbo.Secret'], 'fields' => ['Id']],
        ['action' => 'select', 'source' => ['table' => 'Secret'], 'fields' => ['Id'], '_virtualTables' => ['Secret' => ['Id']]],
        ['action' => 'union', 'queries' => [['source' => ['table' => 'Secret'], 'fields' => ['Id'], '_virtualTables' => ['Secret' => ['Id']]],
            ['source' => ['table' => 'Orders'], 'fields' => ['Id']]]],
        ['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => ['Id'],
            'filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Secret'], 'fields' => ['Id'], '_virtualTables' => ['Secret' => ['Id']]]]]],
    ] as $spoof) {
        remediationRejects(fn () => $validator->validate($spoof), 'INVALID_REQUEST', 'A qualified CTE name or client virtual table was accepted.');
    }

    // Metadata listings come straight from the database catalog.
    $metadata = new MetadataService(new RemediationMetadata());
    remediationAssert(array_column($metadata->getTables()['data'], 'TABLE_NAME') === ['Orders', 'Secret', 'Lines'], 'Table metadata was filtered by a registry.');
    remediationAssert(array_column($metadata->getProcedures()['data'], 'ROUTINE_NAME') === ['ReadReport', 'sp_secret', 'Other'], 'Procedure metadata was filtered by a registry.');
    remediationAssert($metadata->getColumns('Secret')['data'] !== [] && $metadata->tableExists('Secret'), 'Column metadata required a registration.');

    // SSA-07 (v2.1.0 generic authorization): SQL Resource runtime mappings resolve against the authored
    // statement's top-level sources and the catalog. A qualifier that names no
    // top-level source (for example a derived table) cannot be placed.
    $resourceDirectory = $directory . '/resources';
    mkdir($resourceDirectory, 0700, true);
    file_put_contents($resourceDirectory . '/joined.sql', 'SELECT O.Id AS Category, O.Total AS Sales FROM Orders AS O JOIN Secret AS S ON S.Id = O.Id');
    $resourceRegistry = new SqlResourceRegistry([], $resourceDirectory);
    $resourceEngine = new RemediationRecordingEngine();
    $resourceMetadata = new RemediationMetadata(['orders' => ['id', 'total', 'createdat'], 'secret' => ['id', 'region']]);
    $resources = new SqlRepository($resourceEngine, $resourceRegistry, null, $resourceMetadata);
    $resourceRequest = fn (array $mappings, string $field): array => [
        'resource' => 'joined',
        'execution' => ['columns' => ['Category', 'Sales'], 'filters' => $mappings],
        'filters' => [['field' => $field, 'operator' => 'LIKE', 'value' => 'a%']],
    ];
    $resources->execute($resourceRequest(['Region' => ['expression' => 'S.Region', 'placement' => 'source']], 'Region'));
    remediationAssert(str_contains(remediationSql(end($resourceEngine->executions)['sql']), '(S.Region) LIKE ?'), 'A top-level source mapping required a registration.');
    $resources->execute($resourceRequest(['Region' => ['placement' => 'source']], 'Region'));
    remediationAssert(str_contains(remediationSql(end($resourceEngine->executions)['sql']), 'S.Region'), 'Catalog source resolution failed.');
    $resources->execute($resourceRequest(['CreatedAt' => ['placement' => 'source']], 'CreatedAt'));
    remediationAssert(str_contains(remediationSql(end($resourceEngine->executions)['sql']), 'O.CreatedAt'), 'Catalog source resolution failed.');
    remediationRejects(fn () => $resources->execute($resourceRequest(['Leak' => ['expression' => 'X.Region', 'placement' => 'source']], 'Leak')),
        'INVALID_SQL_RUNTIME_FILTER', 'A mapping to a qualifier outside the statement was placed.');
    remediationRejects(fn () => $resources->execute($resourceRequest(['Nope' => ['placement' => 'source']], 'Nope')),
        'INVALID_SQL_RUNTIME_FIELD', 'Source resolution matched a column the catalog does not have.');

    // SSA-11: unpaginated data reads are capped without silent truncation.
    putenv('GENERIC_MAX_RESULT_ROWS');
    remediationAssert(SecurityConfiguration::maxResultRows() === 10000, 'The default result row limit is not 10000.');
    foreach (['abc', '0', '-5', '1000001'] as $invalidLimit) {
        putenv('GENERIC_MAX_RESULT_ROWS=' . $invalidLimit);
        remediationAssert(SecurityConfiguration::maxResultRows() === 10000, "Invalid row limit {$invalidLimit} was accepted.");
    }
    putenv('GENERIC_MAX_RESULT_ROWS=5');
    remediationAssert(count((new RemediationFetchEngine(5))->executePrepared('SELECT 1', [], ['queryPhase' => 'data'])['data']) === 5,
        'A result at the row limit was rejected.');
    $tooLarge = remediationFailure(fn () => (new RemediationFetchEngine(6))->executePrepared('SELECT 1', [], ['queryPhase' => 'data']),
        'An oversized unpaginated result was accepted.');
    remediationAssert($tooLarge instanceof ApiRequestException && $tooLarge->getErrorCode() === 'RESULT_TOO_LARGE'
        && $tooLarge->getStatusCode() === 413, 'An oversized result was not rejected with 413 RESULT_TOO_LARGE.');
    remediationRejects(fn () => (new RemediationFetchEngine(6))->executePreparedQuery('EXEC x', [], ['queryPhase' => 'data']),
        'RESULT_TOO_LARGE', 'Oversized routine results were accepted.');
    remediationAssert(count((new RemediationFetchEngine(8))->executePrepared('SELECT 1', [], ['queryPhase' => 'data', 'page' => 1, 'pageSize' => 8])['data']) === 8,
        'Paginated reads were capped by the unpaginated row limit.');
    remediationAssert(count((new RemediationFetchEngine(8))->executePrepared('SELECT 1', [], ['queryPhase' => 'metadata'])['data']) === 8,
        'Metadata queries were capped.');
    remediationAssert(count((new RemediationFetchEngine(8))->executePrepared('SELECT 1', [], ['queryPhase' => 'write'])['data']) === 8,
        'Write OUTPUT rows were capped.');
    putenv('GENERIC_MAX_RESULT_ROWS');

    // SSA-09: production driver selection and transport warnings.
    remediationAssert(SqlServerDriver::autoDetectionDrivers(true) === ['ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server'],
        'Production automatic driver selection is not limited to ODBC 18/17.');
    remediationAssert(SqlServerDriver::autoDetectionDrivers(false) === SqlServerDriver::supportedDrivers(), 'Development driver detection changed.');
    $driverConfiguration = [
        'provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'sql.example.invalid', 'database' => 'App',
        'authentication' => 'sql', 'username' => 'app', 'password' => '',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false],
    ];
    $production = new RemediationDriver($driverConfiguration, true, '08001 [Microsoft] Login timeout expired');
    remediationFailure(fn () => $production->connect(), 'An unreachable production server connected.');
    remediationAssert($production->attempts === DatabaseTransportSecurity::PRODUCTION_AUTO_DRIVERS, 'Production fell back to legacy drivers.');
    $development = new RemediationDriver($driverConfiguration, false, '08001 [Microsoft] Login timeout expired');
    remediationFailure(fn () => $development->connect(), 'An unreachable development server connected.');
    remediationAssert($development->attempts === SqlServerDriver::supportedDrivers(), 'Development driver fallback changed.');
    foreach ([true, false] as $isProduction) {
        $tls = new RemediationDriver($driverConfiguration, $isProduction,
            '08001 [Microsoft][ODBC Driver 18 for SQL Server]SSL Provider: certificate verify failed');
        $tlsFailure = remediationFailure(fn () => $tls->connect(), 'A TLS failure connected.');
        remediationAssert($tls->attempts === [SqlServerDriver::autoDetectionDrivers($isProduction)[0]] && str_contains($tlsFailure->getMessage(), 'fallback was stopped'),
            'A TLS/certificate failure fell back to another driver.');
    }
    remediationAssert(DatabaseTransportSecurity::warnings($driverConfiguration) === [], 'Secure transport settings produced warnings.');
    $weakConfiguration = ['driver' => 'SQL Server', 'options' => ['encrypt' => false, 'trustServerCertificate' => true]] + $driverConfiguration;
    remediationAssert(DatabaseTransportSecurity::warnings($weakConfiguration)
        === ['encrypt_disabled', 'trust_server_certificate_enabled', 'legacy_driver_configured'], 'Weak transport settings were not reported.');
    $healthRoot = $directory . '/health-root';
    mkdir($healthRoot . '/database/config', 0700, true);
    file_put_contents($healthRoot . '/database/config/database.json', json_encode($weakConfiguration, JSON_THROW_ON_ERROR));
    $warningsFor = function (bool $isProduction) use ($healthRoot): array {
        $monitor = new ApplicationHealthMonitor(['root' => $healthRoot, 'production' => $isProduction]);
        $method = new ReflectionMethod(ApplicationHealthMonitor::class, 'withTransportWarnings');
        return $method->invoke($monitor, ['status' => 'healthy', 'category' => 'connected']);
    };
    $productionHealth = $warningsFor(true);
    remediationAssert($productionHealth['status'] === 'healthy'
        && $productionHealth['warnings'] === ['encrypt_disabled', 'trust_server_certificate_enabled', 'legacy_driver_configured'],
        'Production health did not warn about weakened transport security.');
    remediationAssert(!isset($warningsFor(false)['warnings']), 'Development health reported production transport warnings.');
    $validatorMethod = new ReflectionMethod(ProductionValidator::class, 'databaseTransport');
    $validation = $validatorMethod->invoke(new ProductionValidator($healthRoot));
    remediationAssert($validation['status'] === ProductionValidator::OPERATOR && in_array('trust_server_certificate_enabled', $validation['warnings'], true)
        && !str_contains(json_encode($validation), 'sql.example.invalid'), 'Production validation did not report transport warnings safely.');

    // SSA-10: inlined literals keep T-SQL quote doubling for edge cases.
    $expressions = new SqlExpressionBuilder();
    $literalCases = [
        "O'Brien" => "'O''Brien'",
        "x'; DROP TABLE Orders; --" => "'x''; DROP TABLE Orders; --'",
        "''" => "''''''",
        "a\0b" => "'a\0b'",
        "a\0'; DROP TABLE Orders; --" => "'a\0''; DROP TABLE Orders; --'",
        "\u{02BC}\u{2019}\u{FF07}\u{2032}" => "'\u{02BC}\u{2019}\u{FF07}\u{2032}'",
        "O\u{2019}Brien'" => "'O\u{2019}Brien'''",
    ];
    foreach ($literalCases as $value => $expected) {
        $literal = $expressions->buildValue($value);
        remediationAssert($literal === $expected, 'A string literal was not escaped by quote doubling.');
        remediationAssert(substr_count(str_replace("''", '', substr($literal, 1, -1)), "'") === 0,
            'A string literal contains an unescaped quote.');
    }
    PrincipalContext::set(remediationPrincipal('read-only'));
    $caseQuery = $queries->buildSelect($normalizer->normalize(['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => [[
        'case' => ['when' => [['condition' => ['field' => 'Id', 'operator' => '=', 'value' => "1' OR '1'='1"], 'then' => "\u{FF07}x'"]],
            'else' => "a\0'b"],
        'alias' => 'Label',
    ]]]));
    // Public CASE values use the recursive expression path and stay bound.
    remediationAssert($caseQuery['params'] === ["1' OR '1'='1", "\u{FF07}x'", "a\0'b"]
        && !str_contains($caseQuery['sql'], "OR '1'") && !str_contains($caseQuery['sql'], "\0"),
        'Public CASE values were inlined instead of bound.');

    // SQL Resources (v2.1.0 generic authorization): sql.execute or frontend access runs any discovered
    // resource; there are no per-role SQL Resource scopes. Callers without
    // either permission are denied.
    remediationAssert(!array_key_exists('sqlResources', (new AuthorizationRepository())->load()['roles']['read-only']),
        'Per-role SQL Resource scopes returned.');
    $sqlAuthorization = new AuthorizationMiddleware(new AuthorizationService());
    foreach ([remediationPrincipal('read-only'), remediationPrincipal('read-only', false, null, 'api_key'),
        remediationPrincipal(null, true), remediationPrincipal(null, true, 'application-administrator')] as $sqlPrincipal) {
        PrincipalContext::set($sqlPrincipal);
        foreach (['reports/allowed', 'reports/other', 'widgets/any'] as $resource) {
            $sqlAuthorization->handle(['action' => 'sql', 'resource' => $resource]);
        }
    }
    PrincipalContext::set(remediationPrincipal(null));
    remediationRejects(fn () => $sqlAuthorization->handle(['action' => 'sql', 'resource' => 'reports/allowed']),
        'AUTHORIZATION_DENIED', 'A principal without sql.execute or frontend access ran a SQL Resource.');

    echo "Static security remediation tests passed.\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    }
    PrincipalContext::clear();
    foreach ([
        'GENERIC_RUNTIME_CONFIG_DIR' => $oldConfigurationDirectory, 'GENERIC_LOG_DIR' => $oldLogDirectory,
        'GENERIC_ADMIN_ENABLED' => $oldAdminEnabled, 'GENERIC_MAX_RESULT_ROWS' => $oldMaxRows,
    ] as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
    if ($oldRemoteAddress === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldRemoteAddress;
    remediationRemove($directory);
    remediationRemove(sys_get_temp_dir() . '/generic-remediation-logger-' . getmypid());
}
