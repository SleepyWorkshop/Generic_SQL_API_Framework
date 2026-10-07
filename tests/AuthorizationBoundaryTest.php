<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Middleware/LocalAdminMiddleware.php';

/*
 * HTTP regression coverage for the v2.1.3 authorization findings AAPI-01 and
 * AAPI-02 (docs/security/Security-Verification.md): the public
 * auth.frontendUsers.* path manages only frontend identities, and backend
 * identities (System Administrators, Data Operators, backend-only accounts)
 * are managed only through the loopback Admin API.
 */

function boundaryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function boundaryRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') boundaryRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function boundaryStartServer(string $root, string $boundary, array $environment): array
{
    $port = null;
    foreach (range(19100 + random_int(0, 40) * 10, 19899) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    boundaryAssert($port !== null, 'Unable to reserve a test port.');
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
    boundaryAssert(is_resource($server), "Unable to start the {$boundary} test server.");
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
function boundaryPost(int $port, string $path, array $json, ?string &$cookie = null, ?string $csrf = null): array
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
    boundaryAssert($body !== false && $responseHeaders !== [], "Test server did not answer POST {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $status);
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match) === 1) $cookie = $match[1];
    }
    $decoded = json_decode((string)$body, true);
    return ['status' => (int)$status[1], 'json' => is_array($decoded) ? $decoded : null];
}

/** Log in over HTTP and return [cookie, current CSRF token]. */
function boundaryLogin(int $port, string $path, string $username, string $password): array
{
    $cookie = null;
    $token = boundaryPost($port, $path, ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    $login = boundaryPost($port, $path, ['action' => 'auth.login', 'username' => $username, 'password' => $password], $cookie, $token);
    boundaryAssert($login['status'] === 200, "Login failed for {$username} ({$login['status']}).");
    $token = boundaryPost($port, $path, ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    boundaryAssert(is_string($token), "No CSRF token after login for {$username}.");
    return [$cookie, $token];
}

function boundaryExpect(array $response, int $status, ?string $code, string $message): void
{
    boundaryAssert(
        $response['status'] === $status && ($code === null || ($response['json']['error']['code'] ?? null) === $code),
        $message . " Got {$response['status']} " . (string)($response['json']['error']['code'] ?? '')
    );
}

/** Public frontend-user requests that would take over or alter $username. */
function boundaryFrontendMutations(string $username): array
{
    return [
        'password reset' => ['action' => 'auth.frontendUsers.changePassword', 'username' => $username, 'newPassword' => 'taken-over-password', 'passwordConfirmation' => 'taken-over-password'],
        'rename' => ['action' => 'auth.frontendUsers.update', 'username' => $username, 'name' => 'Taken Over', 'newUsername' => 'Taken.Over', 'mobile' => '+15551234567', 'email' => null],
        'disable' => ['action' => 'auth.frontendUsers.disable', 'username' => $username],
        'delete' => ['action' => 'auth.frontendUsers.delete', 'username' => $username],
        'role change' => ['action' => 'auth.frontendUsers.assignRole', 'username' => $username, 'frontendAccess' => true, 'frontendRole' => null],
    ];
}

$root = dirname(__DIR__);
$configurationDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-authorization-boundary-' . bin2hex(random_bytes(8));
$logDirectory = $configurationDirectory . DIRECTORY_SEPARATOR . 'logs';
$servers = [];

try {
    mkdir($configurationDirectory, 0700, true);
    mkdir($logDirectory, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    putenv('GENERIC_LOG_DIR=' . $logDirectory);
    RuntimeConfiguration::ensure();
    $repository = new AuthRepository(RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE));
    $users = new UserManagementService($repository);
    $accounts = [
        'Boundary.Admin' => ['boundary-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR],
        'Second.Admin' => ['second-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, false, null],
        'Frontend.Admin' => ['frontend-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR],
        'Frontend.Reader' => ['frontend-reader-password', RoleModel::READ_ONLY, true, null],
        'Backend.Operator' => ['backend-operator-password', RoleModel::DATA_OPERATOR, false, null],
    ];
    foreach ($accounts as $username => [$password, $backendRole, $frontendAccess, $frontendRole]) {
        $users->createUser($username, $password, $backendRole, $frontendAccess, $frontendRole);
    }
    $unchanged = function (string $username, string $password) use ($repository): void {
        $stored = $repository->findUser($username);
        boundaryAssert($stored !== null && $stored['username'] === $username && $stored['enabled'] === true
            && $stored['authVersion'] === 1 && password_verify($password, $stored['passwordHash']),
            "Rejected public request changed {$username}.");
    };

    $environment = [
        'GENERIC_APP_ENV' => 'development',
        'GENERIC_RUNTIME_CONFIG_DIR' => $configurationDirectory,
        'GENERIC_LOG_DIR' => $logDirectory,
        'GENERIC_ADMIN_ENABLED' => '0',
    ];
    [$servers['api'], $apiPort] = boundaryStartServer($root, 'api', $environment);
    [$servers['adminDisabled'], $disabledAdminPort] = boundaryStartServer($root, 'admin', $environment);
    [$servers['admin'], $adminPort] = boundaryStartServer($root, 'admin', ['GENERIC_ADMIN_ENABLED' => '1'] + $environment);

    // AAPI-01: an Application Administrator cannot take over or alter a
    // backend-only Data Operator through the public API.
    [$frontendAdminCookie, $frontendAdminToken] = boundaryLogin($apiPort, '/', 'Frontend.Admin', 'frontend-admin-password');
    foreach (boundaryFrontendMutations('Backend.Operator') as $name => $request) {
        boundaryExpect(boundaryPost($apiPort, '/', $request, $frontendAdminCookie, $frontendAdminToken), 403, 'AUTHORIZATION_DENIED',
            "Application Administrator {$name} reached a backend Data Operator.");
    }
    $unchanged('Backend.Operator', 'backend-operator-password');
    $failedTakeover = null;
    $failedToken = boundaryPost($apiPort, '/', ['action' => 'auth.csrf'], $failedTakeover)['json']['data'][0]['csrfToken'] ?? null;
    boundaryExpect(boundaryPost($apiPort, '/', ['action' => 'auth.login', 'username' => 'Backend.Operator', 'password' => 'taken-over-password'], $failedTakeover, $failedToken),
        401, 'INVALID_CREDENTIALS', 'A rejected password reset still allowed a takeover login.');
    boundaryLogin($apiPort, '/', 'Backend.Operator', 'backend-operator-password');

    // Legitimate frontend user management and frontend login are preserved.
    boundaryExpect(boundaryPost($apiPort, '/', ['action' => 'auth.frontendUsers.changePassword', 'username' => 'Frontend.Reader',
        'newPassword' => 'replacement-reader-password', 'passwordConfirmation' => 'replacement-reader-password'], $frontendAdminCookie, $frontendAdminToken),
        200, null, 'Application Administrator can no longer manage a frontend user.');
    boundaryLogin($apiPort, '/', 'Frontend.Reader', 'replacement-reader-password');
    boundaryExpect(boundaryPost($apiPort, '/', ['action' => 'auth.frontendUsers.disable', 'username' => 'Frontend.Reader'], $frontendAdminCookie),
        403, 'CSRF_VALIDATION_FAILED', 'Public frontend-user mutation accepted a missing CSRF token.');

    // AAPI-02: System Administrator accounts cannot be managed through the
    // public API, even by a System Administrator session.
    [$adminPublicCookie, $adminPublicToken] = boundaryLogin($apiPort, '/', 'Boundary.Admin', 'boundary-admin-password');
    foreach (['Second.Admin', 'Boundary.Admin', 'Backend.Operator'] as $target) {
        foreach (boundaryFrontendMutations($target) as $name => $request) {
            boundaryExpect(boundaryPost($apiPort, '/', $request, $adminPublicCookie, $adminPublicToken), 403, 'AUTHORIZATION_DENIED',
                "Public API {$name} reached backend identity {$target}.");
        }
    }
    $unchanged('Second.Admin', 'second-admin-password');
    $unchanged('Boundary.Admin', 'boundary-admin-password');
    $unchanged('Backend.Operator', 'backend-operator-password');
    boundaryExpect(boundaryPost($apiPort, '/', ['action' => 'auth.frontendUsers.disable', 'username' => 'Frontend.Reader'], $adminPublicCookie, $adminPublicToken),
        200, null, 'System Administrator can no longer manage a frontend user through the public API.');
    boundaryExpect(boundaryPost($apiPort, '/', ['action' => 'auth.frontendUsers.enable', 'username' => 'Frontend.Reader'], $adminPublicCookie, $adminPublicToken),
        200, null, 'System Administrator can no longer re-enable a frontend user through the public API.');

    // The Admin API boundary still refuses these operations without the flag,
    // without a System Administrator, or without CSRF.
    [$disabledCookie, $disabledToken] = boundaryLogin($disabledAdminPort, '/api.php', 'Boundary.Admin', 'boundary-admin-password');
    boundaryExpect(boundaryPost($disabledAdminPort, '/api.php', ['action' => 'auth.users.changePassword', 'username' => 'Second.Admin',
        'newPassword' => 'admin-reset-password', 'passwordConfirmation' => 'admin-reset-password'], $disabledCookie, $disabledToken),
        404, 'NOT_FOUND', 'Admin user management ran without GENERIC_ADMIN_ENABLED=1.');
    foreach (['auth.users.changePassword', 'auth.users.update', 'auth.users.disable', 'auth.users.delete'] as $action) {
        putenv('GENERIC_ADMIN_ENABLED=1');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        try { (new LocalAdminMiddleware())->handle(['action' => $action]); throw new RuntimeException("Remote {$action} reached the Admin API."); }
        catch (ApiRequestException $exception) { boundaryAssert($exception->getStatusCode() === 404, "Remote {$action} returned the wrong status."); }
    }
    putenv('GENERIC_ADMIN_ENABLED');
    [$frontendAdminAdminCookie, $frontendAdminAdminToken] = boundaryLogin($adminPort, '/api.php', 'Frontend.Admin', 'frontend-admin-password');
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.changePassword', 'username' => 'Backend.Operator',
        'newPassword' => 'taken-over-password', 'passwordConfirmation' => 'taken-over-password'], $frontendAdminAdminCookie, $frontendAdminAdminToken),
        403, 'AUTHORIZATION_DENIED', 'Application Administrator reached Admin user management.');
    [$adminCookie, $adminToken] = boundaryLogin($adminPort, '/api.php', 'Boundary.Admin', 'boundary-admin-password');
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.disable', 'username' => 'Second.Admin'], $adminCookie),
        403, 'CSRF_VALIDATION_FAILED', 'Admin user management accepted a missing CSRF token.');
    $unchanged('Second.Admin', 'second-admin-password');
    $unchanged('Backend.Operator', 'backend-operator-password');

    // The loopback Admin API remains the working path for backend identities.
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.changePassword', 'username' => 'Second.Admin',
        'newPassword' => 'admin-reset-password', 'passwordConfirmation' => 'admin-reset-password'], $adminCookie, $adminToken),
        200, null, 'Admin API could not reset a System Administrator password.');
    boundaryAssert(password_verify('admin-reset-password', $repository->findUser('Second.Admin')['passwordHash']), 'Admin API password reset was not stored.');
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.update', 'username' => 'Second.Admin', 'name' => 'Renamed Admin',
        'newUsername' => 'Renamed.Admin', 'mobile' => '+15551234567', 'email' => null], $adminCookie, $adminToken),
        200, null, 'Admin API could not rename a System Administrator.');
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.disable', 'username' => 'Renamed.Admin'], $adminCookie, $adminToken),
        200, null, 'Admin API could not disable a System Administrator.');
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.delete', 'username' => 'Renamed.Admin'], $adminCookie, $adminToken),
        200, null, 'Admin API could not delete a System Administrator.');
    boundaryAssert($repository->findUser('Renamed.Admin') === null, 'Admin API deletion was not stored.');
    boundaryExpect(boundaryPost($adminPort, '/api.php', ['action' => 'auth.users.changePassword', 'username' => 'Backend.Operator',
        'newPassword' => 'operator-reset-password', 'passwordConfirmation' => 'operator-reset-password'], $adminCookie, $adminToken),
        200, null, 'Admin API could not reset a backend Data Operator password.');
    boundaryLogin($apiPort, '/', 'Backend.Operator', 'operator-reset-password');

    echo "Authorization boundary tests passed.\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    putenv('GENERIC_ADMIN_ENABLED');
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    putenv('GENERIC_LOG_DIR');
    boundaryRemove($configurationDirectory);
}
