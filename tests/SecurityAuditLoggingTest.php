<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Middleware/ApiRateLimitMiddleware.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';
require_once __DIR__ . '/../app/Security/ApiRateLimiter.php';
require_once __DIR__ . '/../app/Security/LoginRateLimiter.php';
require_once __DIR__ . '/../core/Logger.php';

function auditAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function auditFailure(callable $operation, string $code): void
{
    try { $operation(); }
    catch (ApiRequestException $exception) {
        auditAssert($exception->getErrorCode() === $code, "Expected {$code}, got {$exception->getErrorCode()}.");
        return;
    }
    throw new RuntimeException("Expected {$code}.");
}

function auditRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

if (!defined('API_REQUEST_ID')) define('API_REQUEST_ID', 'audit-request-4-7');

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-security-audit-' . bin2hex(random_bytes(8));
$runtimeDirectory = $directory . '/runtime';
$logDirectory = $directory . '/logs';
$sessionDirectory = $directory . '/sessions';
$oldRuntime = getenv('GENERIC_RUNTIME_CONFIG_DIR');

try {
    mkdir($directory, 0700, true);
    mkdir($sessionDirectory, 0700, true);
    ini_set('session.save_path', $sessionDirectory);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $runtimeDirectory);
    RuntimeConfiguration::ensure();

    $logger = new Logger($logDirectory);
    $users = new UserManagementService(null, null, $logger);
    $administrator = $users->createUser(
        'Audit.Admin',
        'fake-audit-password-123',
        RoleModel::SYSTEM_ADMINISTRATOR,
        true,
        RoleModel::APPLICATION_ADMINISTRATOR
    );
    $operator = $users->createUser('Audit.Operator', 'fake-operator-password-123', RoleModel::DATA_OPERATOR, false, null);

    $session = new AuthSessionService('generic_audit_' . bin2hex(random_bytes(4)), null, $logger);
    $auth = new AuthService(
        new AuthRepository(),
        new PasswordHasher(),
        $session,
        new LoginRateLimiter($directory . '/login-rate', [
            'enabled' => true, 'maximumAttempts' => 10, 'windowSeconds' => 60, 'lockoutSeconds' => 30,
        ]),
        $logger
    );
    auditFailure(fn () => $auth->login('Audit.Operator', 'wrong-fake-password'), 'INVALID_CREDENTIALS');
    $session->start();
    $auth->login('Audit.Operator', 'fake-operator-password-123');

    $authorization = new AuthorizationService(null, $logger);
    $principal = new Principal($operator['username'] === 'Audit.Operator' ? (new AuthRepository())->findUser('Audit.Operator')['id'] : null, 'Audit.Operator', 'session', RoleModel::READ_ONLY, false, null, true);
    PrincipalContext::set($principal);
    auditFailure(fn () => $authorization->authorize($principal, 'admin.manage'), 'AUTHORIZATION_DENIED');

    $keys = new ApiKeyService(null, null, $authorization, $logger);
    $created = $keys->create('Audit integration', 'Audit.Operator', [RoleModel::DATA_OPERATOR]);
    $keys->setEnabled($created['id'], false);
    $keys->setEnabled($created['id'], true);
    $keys->revoke($created['id']);

    $limiter = new ApiRateLimiter($directory . '/api-rate', [
        'enabled' => true, 'requests' => 1, 'windowSeconds' => 60,
    ], static fn (): int => 1000);
    $rateMiddleware = new ApiRateLimitMiddleware($limiter, $logger);
    PrincipalContext::clear();
    $rateMiddleware->handle(['action' => 'select']);
    auditFailure(fn () => $rateMiddleware->handle(['action' => 'select']), 'RATE_LIMIT_EXCEEDED');

    PrincipalContext::set(new Principal(
        (new AuthRepository())->findUser('Audit.Admin')['id'],
        'Audit.Admin',
        'session',
        RoleModel::SYSTEM_ADMINISTRATOR,
        true,
        RoleModel::APPLICATION_ADMINISTRATOR,
        true
    ));
    $admin = new AdminService(null, $directory . '/database.json', null, null, null, null, null, null, $logger);
    $admin->saveCors(['allowedOrigins' => ['https://audit.example.test'], 'allowedMethods' => ['POST', 'OPTIONS'], 'credentialsEnabled' => false]);

    $logger->audit('security.redaction_test', 'failure', 'WARNING', [
        'component' => 'test',
        'reason' => 'password=fake-secret api_key=fake-key session_id=fake-session',
        'actorUsername' => "Audit.Admin\nforged-record",
    ]);

    $logFile = $logDirectory . '/' . date('Y-m-d') . '.log';
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    auditAssert(is_array($lines) && $lines !== [], 'Audit log was not created.');
    $records = [];
    foreach ($lines as $line) {
        auditAssert(substr_count($line, '{') <= 1, 'Untrusted audit data injected a second log record.');
        $record = json_decode($line, true);
        auditAssert(is_array($record), 'Audit log line is not valid structured JSON.');
        if (($record['recordType'] ?? null) === 'security_audit') $records[] = $record;
    }
    $events = array_column($records, 'event');
    foreach (['user.created', 'auth.login', 'authorization.denied', 'api_key.created', 'api_key.disabled',
        'api_key.enabled', 'api_key.revoked', 'rate_limit.api', 'configuration.changed', 'security.redaction_test'] as $event) {
        auditAssert(in_array($event, $events, true), "Missing audit event {$event}.");
    }
    auditAssert(count(array_filter($records, fn (array $record): bool => ($record['event'] ?? null) === 'auth.login' && ($record['outcome'] ?? null) === 'success')) === 1, 'Successful login audit event is missing.');
    auditAssert(count(array_filter($records, fn (array $record): bool => ($record['event'] ?? null) === 'auth.login' && ($record['outcome'] ?? null) === 'failure')) === 1, 'Failed login audit event is missing.');
    foreach ($records as $record) {
        auditAssert(($record['requestId'] ?? null) === API_REQUEST_ID, 'Audit correlation ID was lost.');
        auditAssert(isset($record['timestamp'], $record['event'], $record['outcome'], $record['severity'], $record['component']), 'Audit schema is incomplete.');
    }
    $contents = implode("\n", $lines);
    foreach (['fake-audit-password-123', 'fake-operator-password-123', 'wrong-fake-password', $created['apiKey'],
        'fake-secret', 'fake-key', 'fake-session', 'passwordHash', 'Authorization:', 'Cookie:'] as $secret) {
        auditAssert(!str_contains($contents, $secret), 'Audit log exposed secret material.');
    }
    auditAssert(str_contains($contents, '\\nforged-record'), 'Structured logging did not safely encode a newline in actor data.');
    if (PHP_OS_FAMILY !== 'Windows') {
        clearstatcache(true, $logDirectory);
        clearstatcache(true, $logFile);
        auditAssert((fileperms($logDirectory) & 0777) === 0700, 'Audit log directory is not owner-only.');
        auditAssert((fileperms($logFile) & 0777) === 0600, 'Audit log file is not owner-only.');
    }

    $concurrentDirectory = $directory . '/concurrent-logs';
    $workers = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $environment = getenv();
        $environment['LOGGER_TEST_SECURITY_AUDIT'] = '1';
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false) $command[] = '-n';
        $command[] = __DIR__ . '/LoggerConcurrentWriter.php';
        array_push($command, $concurrentDirectory, 'audit-worker-' . $worker, '20');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        auditAssert(is_resource($process), 'Unable to start concurrent audit writer.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        auditAssert(proc_close($process) === 0 && $stderr === '', 'Concurrent audit writer failed: ' . $stdout . $stderr);
    }
    $concurrentFiles = glob($concurrentDirectory . '/*.log');
    auditAssert(is_array($concurrentFiles) && count($concurrentFiles) === 1, 'Concurrent audit file was not created.');
    $concurrentLines = file($concurrentFiles[0], FILE_IGNORE_NEW_LINES);
    auditAssert(is_array($concurrentLines) && count($concurrentLines) === 80, 'Concurrent audit records were lost.');
    foreach ($concurrentLines as $line) {
        $record = json_decode($line, true);
        auditAssert(is_array($record) && ($record['recordType'] ?? null) === 'security_audit', 'Concurrent audit record was corrupted.');
    }

    $blockedPath = $directory . '/blocked';
    file_put_contents($blockedPath, 'not a directory');
    (new Logger($blockedPath . '/child'))->audit('logging.failure_test', 'failure', 'ERROR');

    echo "Security audit logging tests passed.\n";
} finally {
    PrincipalContext::clear();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    $oldRuntime === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntime);
    auditRemoveDirectory($directory);
}
