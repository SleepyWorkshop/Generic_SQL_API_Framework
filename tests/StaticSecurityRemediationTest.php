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
require_once __DIR__ . '/../app/Resources/RoutineResourceRegistry.php';
require_once __DIR__ . '/../app/Resources/QuerySourcePolicy.php';
require_once __DIR__ . '/../app/Security/DatabaseTransportSecurity.php';
require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';
require_once __DIR__ . '/../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/support/PermissiveQuerySourcePolicy.php';

/*
 * Regression coverage for the v2.1.2 static security remediation
 * (docs/security/Static-Security-Analysis-Inventory.md).
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
    public function __construct(private array $columns = []) {}
    public function tableExists($table) { return true; }
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

    // SSA-01: routines are deny-by-default registry entries.
    $routineEntry = fn (string $type, string $name, string $access, int $parameters, array $roles): array => [
        'type' => $type, 'schema' => 'dbo', 'name' => $name, 'access' => $access, 'parameters' => $parameters, 'roles' => $roles,
    ];
    $routines = new RoutineResourceRegistry([
        'dbo.ReadReport' => $routineEntry('procedure', 'ReadReport', 'read', 1, ['read-only', 'data-operator', 'application-administrator']),
        'dbo.WriteReport' => $routineEntry('procedure', 'WriteReport', 'write', 0, ['read-only', 'data-operator']),
        'reports.daily' => $routineEntry('procedure', 'DailyReport', 'read', 0, ['read-only']),
        'dbo.Score' => $routineEntry('function', 'Score', 'read', 1, ['read-only']),
        'dbo.Rows' => $routineEntry('tableFunction', 'Rows', 'read', 1, ['read-only']),
    ]);
    foreach (['sp_executesql', 'sys.sp_rename', 'master.dbo.xp_cmdshell', 'otherdb.dbo.ReadReport',
        'dbo.ReadReport;DROP', 'DBO.READREPORT', 'ReadReport', '', null, ['dbo.ReadReport']] as $unregistered) {
        remediationRejects(fn () => $routines->resolve($unregistered, 'procedure'), 'INVALID_ROUTINE', 'An unregistered routine resolved.');
    }
    remediationRejects(fn () => $routines->resolve('dbo.Score', 'procedure'), 'INVALID_ROUTINE', 'A function was callable as a procedure.');
    remediationRejects(fn () => $routines->resolve('dbo.ReadReport', 'tableFunction'), 'INVALID_ROUTINE', 'A procedure was callable as a table function.');
    remediationRejects(fn () => (new RoutineResourceRegistry([]))->resolve('dbo.ReadReport', 'procedure'), 'INVALID_ROUTINE',
        'The default registry is not deny-by-default.');
    remediationAssert((require $root . '/config/routine-resources.php') === [], 'The shipped routine registry is not empty.');
    foreach ([
        ['dbo.Bad' => ['schema' => 'dbo.x'] + $routineEntry('procedure', 'Bad', 'read', 0, ['read-only'])],
        ['dbo.Bad' => $routineEntry('procedure', 'Bad]; DROP', 'read', 0, ['read-only'])],
        ['dbo.Bad' => $routineEntry('procedure', 'Bad', 'read', 0, [])],
        ['dbo.Bad' => $routineEntry('procedure', 'Bad', 'read', 0, ['unknown-role'])],
        ['dbo.Bad' => $routineEntry('procedure', 'Bad', 'execute', 0, ['read-only'])],
        ['dbo.Bad' => $routineEntry('procedure', 'Bad', 'read', -1, ['read-only'])],
        ['dbo.Bad' => $routineEntry('procedure', 'Bad', 'read', 0, ['read-only']) + ['database' => 'master']],
    ] as $invalidRegistry) {
        $failure = remediationFailure(fn () => (new RoutineResourceRegistry($invalidRegistry))->resolve('dbo.Bad', 'procedure'),
            'An invalid routine registry entry was accepted.');
        remediationAssert($failure instanceof RuntimeException && !$failure instanceof ApiRequestException, 'Invalid routine entry failed unsafely.');
    }

    $routineEngine = new RemediationRecordingEngine();
    $routineRepository = new QueryRepository($routineEngine, new RemediationMetadata(), routines: $routines, sourcePolicy: new PermissiveQuerySourcePolicy());
    $hostile = "x'; DROP TABLE Orders; --";
    $routineRepository->procedure(['procedure' => 'dbo.ReadReport', 'params' => [$hostile]]);
    $execution = end($routineEngine->executions);
    remediationAssert($execution['sql'] === 'EXEC [dbo].[ReadReport] ?' && $execution['params'] === [$hostile],
        'Registered procedure SQL is not built from the registry with bound arguments.');
    $routineRepository->procedure(['procedure' => 'reports.daily', 'params' => []]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'EXEC [dbo].[DailyReport]', 'The client routine ID became a SQL identifier.');
    $routineRepository->function(['function' => 'dbo.Score', 'params' => [1]]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'SELECT [dbo].[Score](?) AS Result', 'Registered function SQL is wrong.');
    $routineRepository->tableFunction(['function' => 'dbo.Rows', 'params' => [1]]);
    remediationAssert(end($routineEngine->executions)['sql'] === 'SELECT * FROM [dbo].[Rows](?)', 'Registered table function SQL is wrong.');
    $executionCount = count($routineEngine->executions);
    remediationRejects(fn () => $routineRepository->procedure(['procedure' => 'dbo.ReadReport', 'params' => []]),
        'INVALID_ROUTINE_PARAMETERS', 'A wrong routine parameter count was accepted.');
    remediationRejects(fn () => $routineRepository->procedure(['procedure' => 'sp_executesql', 'params' => ['SELECT 1']]),
        'INVALID_ROUTINE', 'The repository executed an unregistered routine.');
    remediationAssert(count($routineEngine->executions) === $executionCount, 'A rejected routine reached the database.');

    $routineAuthorization = new AuthorizationMiddleware(new AuthorizationService(), $routines);
    $routineRequest = fn (string $action, $id): array => ['action' => $action, 'source' => [$action === 'procedure' ? 'procedure' : 'function' => $id]];
    PrincipalContext::set(remediationPrincipal('read-only'));
    $routineAuthorization->handle($routineRequest('procedure', 'dbo.ReadReport'));
    $routineAuthorization->handle($routineRequest('function', 'dbo.Score'));
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('procedure', 'dbo.WriteReport')),
        'AUTHORIZATION_DENIED', 'Read Only executed a write routine.');
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('procedure', 'sp_executesql')),
        'INVALID_ROUTINE', 'An unregistered routine passed authorization.');
    PrincipalContext::set(remediationPrincipal('data-operator'));
    $routineAuthorization->handle($routineRequest('procedure', 'dbo.WriteReport'));
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('tableFunction', 'dbo.Rows')),
        'RESOURCE_ACCESS_DENIED', 'A role not listed by the routine entry was authorized.');
    PrincipalContext::set(remediationPrincipal('read-only', false, null, 'api_key'));
    $routineAuthorization->handle($routineRequest('procedure', 'dbo.ReadReport'));
    PrincipalContext::set(remediationPrincipal(null, true, 'application-administrator'));
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('procedure', 'dbo.ReadReport')),
        'AUTHORIZATION_DENIED', 'frontend.read authorized a routine.');
    PrincipalContext::set(remediationPrincipal(null, true));
    remediationRejects(fn () => $routineAuthorization->handle($routineRequest('function', 'dbo.Score')),
        'AUTHORIZATION_DENIED', 'Frontend access authorized a routine.');

    // SSA-03: query sources are deny-by-default registry entries.
    $sources = new QuerySourceRegistry([
        'Orders' => [],
        'Lines' => ['roles' => ['frontend-access']],
        'Customers' => ['roles' => ['data-operator']],
    ]);
    $policy = new QuerySourcePolicy($sources);
    foreach ([
        ['dbo.Orders' => []], ['Orders' => ['roles' => ['unknown']]], ['Orders' => ['roles' => []]],
        ['Orders' => ['schema' => 'dbo']], [['Orders']], ['Orders' => [], 'ORDERS' => []],
    ] as $invalidSources) {
        $failure = remediationFailure(fn () => new QuerySourceRegistry($invalidSources), 'An invalid query source entry was accepted.');
        remediationAssert($failure instanceof RuntimeException, 'Invalid query source entry failed unsafely.');
    }
    $shippedSources = new QuerySourceRegistry();
    foreach (['CustomerTable', 'ItemMasterTable', 'BillDetTable', 'BillMastTable', 'billmasttable', 'PurMastTable', 'CategoryTable'] as $required) {
        remediationAssert($shippedSources->find($required) !== null, "Shipped query sources omit {$required}.");
    }
    foreach (['sys.objects', 'INFORMATION_SCHEMA.TABLES', 'master.dbo.Users', 'Inventory'] as $unregistered) {
        remediationAssert($shippedSources->find($unregistered) === null, "Shipped query sources include {$unregistered}.");
    }
    PrincipalContext::clear();
    remediationAssert($policy->allows('Orders') && $policy->allows('orders') && !$policy->allows('Secret')
        && !$policy->allows('dbo.Orders') && !$policy->allows('sys.objects') && !$policy->allows('Customers'),
        'Query source policy decisions are wrong without a principal.');
    PrincipalContext::set(remediationPrincipal('read-only'));
    remediationAssert(!$policy->allows('Customers') && !$policy->allows('Lines'), 'A role-restricted source was allowed.');
    PrincipalContext::set(remediationPrincipal('data-operator'));
    remediationAssert($policy->allows('Customers'), 'A listed role was denied.');
    PrincipalContext::set(remediationPrincipal(null, true));
    remediationAssert($policy->allows('Lines') && !$policy->allows('Customers'), 'frontend-access sources were not applied.');

    PrincipalContext::set(remediationPrincipal('read-only'));
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    $queryEngine = new RemediationRecordingEngine();
    $queries = new QueryRepository($queryEngine, new RemediationMetadata(), routines: $routines, sourcePolicy: $policy);
    $run = function (array $request) use ($validator, $normalizer, $queries): array {
        $validator->validate($request);
        return $queries->select($normalizer->normalize($request));
    };
    $run(['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => ['Id']]);
    remediationAssert(str_contains(remediationSql(end($queryEngine->executions)['sql']), 'FROM Orders'), 'A registered source was not queried.');
    $denied = [
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
        'role-restricted' => ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Id']],
    ];
    foreach ($denied as $placement => $request) {
        $before = count($queryEngine->executions);
        remediationRejects(fn () => $run($request), 'RESOURCE_ACCESS_DENIED', "An unregistered {$placement} source was queried.");
        remediationAssert(count($queryEngine->executions) === $before, "A denied {$placement} source reached the database.");
    }
    // CTE names stay local, even when they shadow an unregistered table name.
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

    // Metadata listings respect the query-source and routine registries.
    $metadata = new MetadataService(new RemediationMetadata(), $policy, $routines);
    $tables = $metadata->getTables();
    remediationAssert(array_column($tables['data'], 'TABLE_NAME') === ['Orders'] && $tables['rowsReturned'] === 1,
        'Table metadata exposed unregistered sources.');
    remediationAssert(array_column($metadata->getViews()['data'], 'TABLE_NAME') === ['orders'], 'View metadata exposed unregistered sources.');
    remediationAssert(array_column($metadata->schema()['data'], 'TABLE_NAME') === ['Orders'], 'Schema metadata exposed unregistered sources.');
    remediationAssert(array_column($metadata->getProcedures()['data'], 'ROUTINE_NAME') === ['ReadReport'], 'Procedure metadata exposed unregistered routines.');
    remediationRejects(fn () => $metadata->getColumns('Secret'), 'RESOURCE_ACCESS_DENIED', 'Column metadata exposed an unregistered source.');
    remediationAssert($metadata->getColumns('Orders')['data'] !== [] && !$metadata->tableExists('Secret') && $metadata->tableExists('Orders'),
        'Metadata existence checks ignore the query-source policy.');

    // SSA-07: SQL Resource source filters resolve only against registered sources.
    $resourceDirectory = $directory . '/resources';
    mkdir($resourceDirectory, 0700, true);
    file_put_contents($resourceDirectory . '/joined.sql', 'SELECT O.Id AS Category, O.Total AS Sales FROM Orders AS O JOIN Secret AS S ON S.Id = O.Id');
    $resourceRegistry = new SqlResourceRegistry([], $resourceDirectory);
    $resourceEngine = new RemediationRecordingEngine();
    $resourceMetadata = new RemediationMetadata(['orders' => ['id', 'total', 'createdat'], 'secret' => ['id', 'password']]);
    $resources = new SqlRepository($resourceEngine, $resourceRegistry, null, $resourceMetadata, $policy);
    $resourceRequest = fn (array $mappings, string $field): array => [
        'resource' => 'joined',
        'execution' => ['columns' => ['Category', 'Sales'], 'filters' => $mappings],
        'filters' => [['field' => $field, 'operator' => 'LIKE', 'value' => 'a%']],
    ];
    remediationRejects(fn () => $resources->execute($resourceRequest(['Leak' => ['expression' => 'S.Password', 'placement' => 'source']], 'Leak')),
        'RESOURCE_ACCESS_DENIED', 'An explicit mapping reached an unregistered source.');
    remediationRejects(fn () => $resources->execute($resourceRequest(['Leak' => ['expression' => 'Password', 'placement' => 'source']], 'Leak')),
        'RESOURCE_ACCESS_DENIED', 'An unqualified mapping reached a statement with an unregistered source.');
    remediationRejects(fn () => $resources->execute($resourceRequest(['Leak' => ['expression' => 'COUNT(S.Password)', 'placement' => 'having']], 'Leak')),
        'RESOURCE_ACCESS_DENIED', 'A HAVING mapping reached an unregistered source.');
    remediationRejects(fn () => $resources->execute($resourceRequest(['Password' => ['placement' => 'source']], 'Password')),
        'INVALID_SQL_RUNTIME_FIELD', 'Source resolution matched an unregistered source.');
    $resources->execute($resourceRequest(['CreatedAt' => ['placement' => 'source']], 'CreatedAt'));
    remediationAssert(str_contains(remediationSql(end($resourceEngine->executions)['sql']), 'O.CreatedAt'), 'Source resolution against a registered source failed.');
    $resources->execute($resourceRequest(['Created' => ['expression' => 'O.CreatedAt', 'placement' => 'source']], 'Created'));
    remediationAssert(str_contains(remediationSql(end($resourceEngine->executions)['sql']), '(O.CreatedAt) LIKE ?'), 'An explicit registered mapping failed.');

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

    // SSA-06 (accepted limitation): frontend access is not narrowed by SQL
    // Resource scopes, while backend roles are.
    $authorizationRepository = new AuthorizationRepository();
    $authorization = $authorizationRepository->load();
    $authorization['roles']['read-only']['sqlResources'] = ['reports/allowed'];
    $authorization['roles']['application-administrator']['sqlResources'] = ['reports/allowed'];
    $authorizationRepository->save($authorization);
    $sqlAuthorization = new AuthorizationMiddleware(new AuthorizationService());
    PrincipalContext::set(remediationPrincipal('read-only'));
    $sqlAuthorization->handle(['action' => 'sql', 'resource' => 'reports/allowed']);
    remediationRejects(fn () => $sqlAuthorization->handle(['action' => 'sql', 'resource' => 'reports/other']),
        'RESOURCE_ACCESS_DENIED', 'Backend SQL Resource scopes are not enforced.');
    foreach ([remediationPrincipal(null, true), remediationPrincipal(null, true, 'application-administrator')] as $frontendPrincipal) {
        PrincipalContext::set($frontendPrincipal);
        $sqlAuthorization->handle(['action' => 'sql', 'resource' => 'reports/other']);
    }

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
