<?php

require_once __DIR__ . '/../app/Security/CsrfTokenService.php';
require_once __DIR__ . '/../app/Security/LoginRateLimiter.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../core/Response.php';

function securityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function securityFailure(callable $operation, string $code, int $status): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        securityAssert($exception->getErrorCode() === $code, "Unexpected security error code: {$exception->getErrorCode()}.");
        securityAssert($exception->getStatusCode() === $status, "Unexpected security status for {$code}.");
        return $exception;
    }
    throw new RuntimeException("Expected security failure {$code}.");
}

function removeSecurityDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($directory);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-reporting-security-test-' . bin2hex(random_bytes(8));
$sessionPath = $root . DIRECTORY_SEPARATOR . 'sessions';
$ratePath = $root . DIRECTORY_SEPARATOR . 'rate-limit';
$authPath = $root . DIRECTORY_SEPARATOR . 'auth.json';
$sessionName = 'generic_reporting_security_' . bin2hex(random_bytes(4));
$previousRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$previousAppEnvironment = getenv('GENERIC_APP_ENV');
$previousAllowedOrigins = getenv('GENERIC_API_ALLOWED_ORIGINS');

try {
    mkdir($root, 0700, true);
    mkdir($sessionPath, 0700, true);
    ini_set('session.save_path', $sessionPath);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    putenv('GENERIC_APP_ENV=production');
    putenv('GENERIC_API_ALLOWED_ORIGINS=*');
    securityAssert(SecurityConfiguration::sessionOptions()['secure'] === true, 'Production did not require Secure cookies.');
    securityAssert(SecurityConfiguration::allowedOrigins() === [], 'Unsafe CORS environment override did not fail closed.');

    $sessionOptions = [
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
        'idleTimeout' => 600,
        'absoluteTimeout' => 3600,
    ];
    ini_set('session.cookie_domain', '.unsafe.example.test');
    $urlSessionId = str_repeat('a', 32);
    $_GET[$sessionName] = $urlSessionId;
    $session = new AuthSessionService($sessionName, $sessionOptions);
    securityAssert($session->resume() === false, 'A URL-provided session identifier was accepted without a cookie.');
    securityAssert(session_status() !== PHP_SESSION_ACTIVE, 'A URL-provided session identifier started a session.');
    unset($_GET[$sessionName]);
    $csrf = new CsrfTokenService($session);
    $firstToken = $csrf->token();
    $anonymousSessionId = session_id();
    $cookie = session_get_cookie_params();
    securityAssert($cookie['lifetime'] === 0, 'Session cookie became persistent.');
    securityAssert($cookie['path'] === '/', 'Session cookie path is not the shared application root.');
    securityAssert($cookie['domain'] === '', 'Session cookie domain was broadened beyond the request host.');
    securityAssert($cookie['secure'] === true, 'Production session cookie was not Secure.');
    securityAssert($cookie['httponly'] === true, 'Session cookie was not HttpOnly.');
    securityAssert(($cookie['samesite'] ?? null) === 'Lax', 'Session cookie SameSite policy is incorrect.');
    securityAssert(ini_get('session.use_cookies') === '1', 'Session cookie transport is disabled.');
    securityAssert(ini_get('session.use_only_cookies') === '1', 'Sessions may use non-cookie identifiers.');
    securityAssert(ini_get('session.use_strict_mode') === '1', 'Strict session mode is not enabled.');
    securityAssert(ini_get('session.use_trans_sid') === '0', 'Transparent URL session identifiers are enabled.');
    securityAssert(ini_get('session.cookie_lifetime') === '0', 'PHP session cookie lifetime is persistent.');
    securityAssert(ini_get('session.gc_maxlifetime') === '3600', 'Session storage lifetime does not match the absolute timeout.');

    $session->establish('Security.Admin', str_repeat('a', 32), 1);
    securityAssert(session_id() !== $anonymousSessionId, 'Login did not regenerate the session identifier.');
    $authenticatedSessionId = session_id();
    $csrf->validate($firstToken);
    $authenticatedToken = $csrf->rotate();
    securityAssert($authenticatedToken !== $firstToken, 'Authentication did not rotate the CSRF token.');
    securityFailure(fn () => $csrf->validate($firstToken), 'CSRF_VALIDATION_FAILED', 403);
    $csrf->validate($authenticatedToken);
    session_write_close();
    $_COOKIE[$sessionName] = $anonymousSessionId;
    session_id($anonymousSessionId);
    $replayedLoginSession = new AuthSessionService($sessionName, $sessionOptions);
    securityAssert($replayedLoginSession->resume(), 'Old login session replay did not reach safe anonymous handling.');
    securityAssert(!$replayedLoginSession->isAuthenticated(), 'The pre-login session remained authenticated after regeneration.');
    securityAssert(session_id() !== $anonymousSessionId, 'Strict mode accepted the deleted pre-login session identifier.');
    $replayedLoginSession->destroy();
    $_COOKIE[$sessionName] = $authenticatedSessionId;
    session_id($authenticatedSessionId);
    securityAssert($session->resume() && $session->isAuthenticated(), 'The regenerated authenticated session could not resume.');
    $csrf->validate($authenticatedToken);
    securityAssert(!str_contains(json_encode([
        'authenticated' => true,
        'user' => ['username' => 'Security.Admin', 'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR],
    ], JSON_THROW_ON_ERROR), session_id()), 'Authentication response exposed the session identifier.');

    $middleware = new CsrfProtectionMiddleware($csrf);
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $missing = securityFailure(
        fn () => $middleware->handle(['action' => 'auth.users.create']),
        'CSRF_VALIDATION_FAILED',
        403
    );
    securityFailure(
        fn () => $middleware->handle(['action' => 'auth.users.update']),
        'CSRF_VALIDATION_FAILED',
        403
    );
    foreach (['admin.server.save', 'admin.runtime.save', 'admin.console.restart', 'admin.api.start', 'admin.api.stop', 'admin.api.restart', 'admin.sqlParser.start', 'admin.sqlParser.stop', 'admin.sqlParser.restart', 'admin.database.connect', 'admin.database.disconnect', 'admin.database.restart'] as $action) {
        securityFailure(fn () => $middleware->handle(['action' => $action]), 'CSRF_VALIDATION_FAILED', 403);
    }
    securityAssert($missing->getDetails() === [], 'CSRF failure exposed internal details.');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('0', 64);
    securityFailure(fn () => $middleware->handle(['action' => 'auth.logout']), 'CSRF_VALIDATION_FAILED', 403);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $authenticatedToken;
    $middleware->handle(['action' => 'auth.logout']);
    $middleware->handle(['action' => 'select']);

    $logoutSessionId = session_id();
    $session->destroy();
    securityAssert(!is_file($sessionPath . DIRECTORY_SEPARATOR . 'sess_' . $logoutSessionId), 'Logout left the server-side session file reusable.');
    $_COOKIE[$sessionName] = $logoutSessionId;
    session_id($logoutSessionId);
    $replayedLogoutSession = new AuthSessionService($sessionName, $sessionOptions);
    securityAssert($replayedLogoutSession->resume(), 'Logged-out session replay did not reach safe anonymous handling.');
    securityAssert(!$replayedLogoutSession->isAuthenticated(), 'The logged-out session remained authenticated.');
    securityAssert(session_id() !== $logoutSessionId, 'Strict mode accepted the destroyed logout session identifier.');
    $replayedLogoutSession->destroy();
    session_id('');
    unset($_COOKIE[$sessionName], $_SERVER['HTTP_X_CSRF_TOKEN']);
    $nextSession = new AuthSessionService($sessionName, $sessionOptions);
    $nextCsrf = new CsrfTokenService($nextSession);
    $secondToken = $nextCsrf->token();
    securityAssert($secondToken !== $authenticatedToken, 'Logout did not invalidate the prior CSRF token.');
    securityFailure(fn () => $nextCsrf->validate($authenticatedToken), 'CSRF_VALIDATION_FAILED', 403);

    $nextSession->establish('Security.Admin', str_repeat('a', 32), 1);
    $_SESSION['generic_reporting_session_meta']['lastActivity'] = time() - 601;
    securityAssert($nextSession->resume() === false, 'Idle session timeout was not enforced.');
    securityAssert(session_status() !== PHP_SESSION_ACTIVE, 'Expired session was not destroyed.');
    unset($_COOKIE[$sessionName]);
    session_id('');

    $absoluteSession = new AuthSessionService($sessionName, $sessionOptions);
    $absoluteSession->establish('Security.Admin', str_repeat('a', 32), 1);
    $_SESSION['generic_reporting_session_meta']['createdAt'] = time() - 3601;
    $_SESSION['generic_reporting_session_meta']['lastActivity'] = time();
    securityAssert($absoluteSession->resume() === false, 'Absolute session timeout was not enforced.');
    securityAssert(session_status() !== PHP_SESSION_ACTIVE, 'Absolute-expired session was not destroyed.');
    unset($_COOKIE[$sessionName]);
    session_id('');

    $hasher = new PasswordHasher();
    $repository = new AuthRepository($authPath);
    $legacyHash = password_hash('legacy-password-123', PASSWORD_BCRYPT, ['cost' => 4]);
    $repository->save(['version' => 1, 'users' => [
        [
            'username' => 'Rate.User',
            'passwordHash' => $hasher->hash('correct-password-123'),
            'enabled' => true,
            'isAdmin' => false,
        ],
        [
            'username' => 'Legacy.User',
            'passwordHash' => $legacyHash,
            'enabled' => true,
            'isAdmin' => false,
        ],
    ]]);
    $rateOptions = ['enabled' => true, 'maximumAttempts' => 3, 'windowSeconds' => 600, 'lockoutSeconds' => 60];
    $limiter = new LoginRateLimiter($ratePath, $rateOptions);
    $rateSession = new AuthSessionService($sessionName, $sessionOptions);
    $auth = new AuthService($repository, $hasher, $rateSession, $limiter);

    $auth->login('Legacy.User', 'legacy-password-123');
    $rehash = $repository->findUser('Legacy.User')['passwordHash'];
    securityAssert($rehash !== $legacyHash && !$hasher->needsRehash($rehash), 'Successful login did not rehash the password.');
    $rateSession->destroy();
    unset($_COOKIE[$sessionName]);

    securityFailure(fn () => $auth->login('Rate.User', 'wrong-1'), 'INVALID_CREDENTIALS', 401);
    $auth->login('Rate.User', 'correct-password-123');
    $rateSession->destroy();
    unset($_COOKIE[$sessionName]);
    securityFailure(fn () => $auth->login('Rate.User', 'wrong-2'), 'INVALID_CREDENTIALS', 401);
    securityFailure(fn () => $auth->login('Rate.User', 'wrong-3'), 'INVALID_CREDENTIALS', 401);
    $knownLimited = securityFailure(fn () => $auth->login('Rate.User', 'wrong-4'), 'LOGIN_RATE_LIMITED', 429);

    foreach (['wrong-1', 'wrong-2'] as $password) {
        securityFailure(fn () => $auth->login('Unknown.User', $password), 'INVALID_CREDENTIALS', 401);
    }
    $unknownLimited = securityFailure(
        fn () => $auth->login('Unknown.User', 'wrong-3'),
        'LOGIN_RATE_LIMITED',
        429
    );
    securityAssert(
        $knownLimited->getMessage() === $unknownLimited->getMessage()
            && $knownLimited->getDetails() === $unknownLimited->getDetails(),
        'Rate limiting revealed whether the username exists.'
    );

    $errorPayload = json_encode(Response::errorPayload(
        'The security token is invalid or expired. Refresh the page and try again.',
        'CSRF_VALIDATION_FAILED'
    ), JSON_THROW_ON_ERROR);
    foreach (['passwordHash', 'sessionId', 'GENERIC_SQL_API_ENCRYPTION_KEY', __DIR__, 'trace'] as $forbidden) {
        securityAssert(!str_contains($errorPayload, $forbidden), "Security response exposed {$forbidden}.");
    }

    $nginxTemplate = (string)file_get_contents(
        __DIR__ . '/../deployment/nginx/generic-reporting.windows.example.conf'
    );
    foreach (['Frontend/Generic-Reporting-Framework/dist', 'Backend/api/index.php', 'location ^~ /api/',
        '\\.env', '\\.git', 'config|app|core|tests|logs|storage|backups?',
        '\\.(?:json|lock|sql|bak|backup|old|tmp|ini|log|php)', 'Strict-Transport-Security'] as $requiredRule) {
        securityAssert(str_contains($nginxTemplate, $requiredRule), "Nginx template is missing {$requiredRule}.");
    }
    securityAssert(
        !str_contains($nginxTemplate, 'GENERIC_SQL_API_ENCRYPTION_KEY'),
        'Nginx public configuration contains a backend encryption secret setting.'
    );

    echo "Security hardening tests passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    unset($_COOKIE[$sessionName], $_SERVER['HTTP_X_CSRF_TOKEN']);
    if ($previousRemoteAddress === null) unset($_SERVER['REMOTE_ADDR']);
    else $_SERVER['REMOTE_ADDR'] = $previousRemoteAddress;
    $previousAppEnvironment === false
        ? putenv('GENERIC_APP_ENV')
        : putenv('GENERIC_APP_ENV=' . $previousAppEnvironment);
    $previousAllowedOrigins === false
        ? putenv('GENERIC_API_ALLOWED_ORIGINS')
        : putenv('GENERIC_API_ALLOWED_ORIGINS=' . $previousAllowedOrigins);
    removeSecurityDirectory($root);
}
