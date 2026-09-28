<?php

require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Requests/AuthRequestValidator.php';

function authFlowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function authFlowFailure(callable $operation, string $code): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        authFlowAssert($exception->getErrorCode() === $code, 'Unexpected authentication error code.');
        return $exception;
    }
    throw new RuntimeException("Expected authentication failure {$code}.");
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-reporting-login-test-' . bin2hex(random_bytes(8));
$sessionPath = $directory . DIRECTORY_SEPARATOR . 'sessions';
$authPath = $directory . DIRECTORY_SEPARATOR . 'auth.json';
$sessionName = 'generic_reporting_login_' . bin2hex(random_bytes(4));

try {
    mkdir($directory, 0700, true);
    mkdir($sessionPath, 0700, true);
    ini_set('session.save_path', $sessionPath);

    $hasher = new PasswordHasher();
    $repository = new AuthRepository($authPath);
    $repository->save([
        'version' => 1,
        'users' => [
            [
                'username' => 'Administrator',
                'passwordHash' => $hasher->hash('correct-password-123'),
                'enabled' => true,
                'isAdmin' => true,
            ],
            [
                'username' => 'Disabled.User',
                'passwordHash' => $hasher->hash('disabled-password-123'),
                'enabled' => false,
                'isAdmin' => false,
            ],
        ],
    ]);

    $session = new AuthSessionService($sessionName);
    $service = new AuthService($repository, $hasher, $session);
    authFlowAssert(
        $service->session() === ['authenticated' => false, 'user' => null],
        'Missing session cookie did not return the anonymous snapshot.'
    );
    authFlowAssert(session_status() !== PHP_SESSION_ACTIVE, 'Session check created an anonymous session.');

    $wrong = authFlowFailure(
        fn () => $service->login('Administrator', 'wrong-password'),
        'INVALID_CREDENTIALS'
    );
    $unknown = authFlowFailure(
        fn () => $service->login('Nobody', 'wrong-password'),
        'INVALID_CREDENTIALS'
    );
    $disabled = authFlowFailure(
        fn () => $service->login('Disabled.User', 'disabled-password-123'),
        'INVALID_CREDENTIALS'
    );
    foreach ([$wrong, $unknown, $disabled] as $failure) {
        authFlowAssert($failure->getStatusCode() === 401, 'Invalid credentials did not return HTTP 401.');
        authFlowAssert(
            $failure->getMessage() === 'Invalid username or password.'
                && ($failure->getDetails() === []
                    || (($failure->getDetails()[0]['locked'] ?? null) === false
                        && is_int($failure->getDetails()[0]['attemptsRemaining'] ?? null))),
            'Credential failure disclosed account state.'
        );
    }
    authFlowAssert($wrong->getDetails() === $unknown->getDetails()
        && $unknown->getDetails() === $disabled->getDetails(), 'Credential feedback varies by account existence or state.');
    authFlowAssert(session_status() !== PHP_SESSION_ACTIVE, 'Rejected login created a session.');

    $session->start();
    $anonymousId = session_id();
    $snapshot = $service->login('administrator', 'correct-password-123');
    authFlowAssert(session_id() !== $anonymousId, 'Login did not regenerate the session identifier.');
    authFlowAssert($snapshot === [
        'authenticated' => true,
        'user' => [
            'username' => 'Administrator',
            'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR,
            'frontendAccess' => true,
            'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR,
        ],
    ], 'Login returned an unsafe or malformed identity snapshot.');
    authFlowAssert($service->session() === $snapshot, 'Authenticated session could not be restored.');
    authFlowAssert(
        array_keys($_SESSION) === ['generic_reporting_auth', 'generic_reporting_session_meta'],
        'Session stored data outside the identity and timeout boundary.'
    );
    $storedAdministrator = $repository->findUser('Administrator');
    authFlowAssert($_SESSION['generic_reporting_auth'] === [
        'authenticated' => true,
        'username' => 'Administrator',
        'userId' => $storedAdministrator['id'],
        'authVersion' => $storedAdministrator['authVersion'],
    ], 'Session identity contains unsafe or malformed data.');
    authFlowAssert(
        is_int($_SESSION['generic_reporting_session_meta']['createdAt'] ?? null)
            && is_int($_SESSION['generic_reporting_session_meta']['lastActivity'] ?? null),
        'Session timeout metadata was not stored server-side.'
    );

    $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
    foreach (['password', 'passwordHash', 'sessionId', 'installationId'] as $sensitiveField) {
        authFlowAssert(!str_contains($serialized, $sensitiveField), "Authentication response exposed {$sensitiveField}.");
    }

    $validator = new AuthRequestValidator();
    authFlowAssert(
        $validator->validate(['action' => 'auth.csrf']) === ['action' => 'auth.csrf'],
        'CSRF token action was not accepted safely.'
    );
    $validated = $validator->validate([
        'action' => 'auth.login',
        'username' => ' Administrator ',
        'password' => ' password-with-spaces ',
    ]);
    authFlowAssert($validated['username'] === 'Administrator', 'Login username was not normalized.');
    authFlowAssert(str_ends_with($validated['password'], ' '), 'Login password was silently trimmed.');
    foreach ([
        ['action' => 'auth.login', 'username' => "bad\nname", 'password' => 'password'],
        ['action' => 'auth.login', 'username' => 'Administrator', 'password' => ''],
        ['action' => 'auth.login', 'username' => 'Administrator', 'password' => 'password', 'isAdmin' => true],
        ['action' => 'auth.session', 'username' => 'Administrator'],
        ['action' => 'auth.logout', 'sessionId' => 'unsafe'],
    ] as $invalidRequest) {
        authFlowFailure(fn () => $validator->validate($invalidRequest), 'INVALID_AUTH_REQUEST');
    }

    authFlowAssert(
        $service->logout() === ['authenticated' => false, 'user' => null],
        'Logout returned an unsafe response.'
    );
    authFlowAssert(session_status() !== PHP_SESSION_ACTIVE, 'Logout did not destroy the session.');
    unset($_COOKIE[$sessionName]);
    authFlowAssert(
        $service->session() === ['authenticated' => false, 'user' => null],
        'Session remained authenticated after logout.'
    );
    authFlowAssert(
        $service->logout() === ['authenticated' => false, 'user' => null],
        'Repeated logout was not idempotent.'
    );

    echo "Authentication flow tests passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionPath);
    @unlink($authPath);
    @rmdir($directory);
}
