<?php

require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AdminAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Requests/UserManagementRequestValidator.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';

function userManagementAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function userManagementFailure(callable $operation, string $code, int $status): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        userManagementAssert($exception->getErrorCode() === $code, "Unexpected error code for {$code}.");
        userManagementAssert($exception->getStatusCode() === $status, "Unexpected status for {$code}.");
        return $exception;
    }
    throw new RuntimeException("Expected failure {$code}.");
}

function destroyUserManagementSession(string $sessionName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    unset($_COOKIE[$sessionName]);
}

function removeUserManagementFixture(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-reporting-users-test-' . bin2hex(random_bytes(8));
$mainDirectory = $root . DIRECTORY_SEPARATOR . 'main';
$concurrentDirectory = $root . DIRECTORY_SEPARATOR . 'concurrent';
$sessionPath = $root . DIRECTORY_SEPARATOR . 'sessions';
$logPath = $root . DIRECTORY_SEPARATOR . 'logs';
$sessionName = 'generic_reporting_users_' . bin2hex(random_bytes(4));
$adminPath = $root . DIRECTORY_SEPARATOR . 'admin.json';
$oldAdminPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$actions = [
    'auth.users.list', 'auth.users.create', 'auth.users.update', 'auth.users.enable',
    'auth.users.disable', 'auth.users.delete', 'auth.users.changePassword',
    'admin.console.restart',
    'admin.api.start', 'admin.api.stop', 'admin.api.restart',
    'admin.sqlParser.start', 'admin.sqlParser.stop', 'admin.sqlParser.restart',
    'admin.database.connect', 'admin.database.disconnect', 'admin.database.restart',
];
$publicActions = ['setup.status', 'setup.createAdmin', 'auth.csrf', 'auth.login', 'auth.session', 'auth.logout'];

try {
    mkdir($root, 0700, true);
    mkdir($mainDirectory, 0700, true);
    mkdir($concurrentDirectory, 0700, true);
    mkdir($sessionPath, 0700, true);
    mkdir($logPath, 0700, true);
    ini_set('session.save_path', $sessionPath);
    (new AdminConfigurationRepository($adminPath))->save(AdminConfigurationRepository::defaults());
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $adminPath);

    $hasher = new PasswordHasher();
    $authPath = $mainDirectory . DIRECTORY_SEPARATOR . 'auth.json';
    $repository = new AuthRepository($authPath);
    $repository->save(['version' => 1, 'users' => [
        [
            'username' => 'Admin',
            'passwordHash' => $hasher->hash('admin-password-123'),
            'enabled' => true,
            'isAdmin' => true,
        ],
        [
            'username' => 'Operator',
            'passwordHash' => $hasher->hash('operator-password-123'),
            'enabled' => true,
            'isAdmin' => false,
        ],
    ]]);
    $session = new AuthSessionService($sessionName);
    $authentication = new AuthenticationMiddleware(true, $publicActions, $session, $repository);
    $authorization = new AdminAuthorizationMiddleware($actions, $session);
    $service = new UserManagementService($repository, $hasher, new Logger($logPath));
    $authService = new AuthService($repository, $hasher, $session);

    foreach ($actions as $action) {
        userManagementFailure(fn () => $authentication->handle(['action' => $action]), 'AUTHENTICATION_REQUIRED', 401);
    }

    $operator = $repository->findUser('Operator');
    $session->establish('Operator', $operator['id'], $operator['authVersion']);
    foreach ($actions as $action) {
        $request = ['action' => $action, 'authenticated' => true];
        $authentication->handle($request);
        userManagementFailure(fn () => $authorization->handle($request), 'AUTHORIZATION_DENIED', 403);
    }
    destroyUserManagementSession($sessionName);

    $admin = $repository->findUser('Admin');
    $session->establish('Admin', $admin['id'], $admin['authVersion']);
    foreach ($actions as $action) {
        $authentication->handle(['action' => $action]);
        $authorization->handle(['action' => $action]);
    }

    $listed = $service->listUsers();
    userManagementAssert(count($listed) === 2, 'Admin could not list users.');
    userManagementAssert(
        !str_contains(json_encode($listed, JSON_THROW_ON_ERROR), 'password')
            && !str_contains(json_encode($listed, JSON_THROW_ON_ERROR), 'authVersion')
            && array_keys($listed[0]) === ['name', 'username', 'mobile', 'email', 'enabled', 'backendRole', 'frontendAccess', 'frontendRole', 'createdAt'],
        'User list exposed internal authentication material.'
    );

    $created = $service->createUser(
        'New.User', 'new-user-password-123', RoleModel::READ_ONLY, false, null, true,
        'New User', '+15550000010', null
    );
    userManagementAssert(
        $created['username'] === 'New.User'
            && $created['enabled'] === true
            && $created['backendRole'] === RoleModel::READ_ONLY
            && $created['name'] === 'New User'
            && $created['mobile'] === '+15550000010'
            && $created['email'] === null
            && strtotime($created['createdAt']) !== false
            && !str_contains(json_encode($created, JSON_THROW_ON_ERROR), 'password'),
        'Created user response was unsafe or malformed.'
    );
    $service->assignAuthorization(
        'New.User', RoleModel::DATA_OPERATOR, true, null, $admin['id']
    );
    userManagementAssert(
        $repository->findUser('New.User')['backendRole'] === RoleModel::DATA_OPERATOR,
        'Super Admin could not change another user authorization.'
    );
    userManagementFailure(
        fn () => $service->assignAuthorization(
            'Admin', RoleModel::READ_ONLY, true, null, $admin['id']
        ),
        'AUTHORIZATION_DENIED',
        403
    );
    $applicationAdmin = $service->createUser(
        'Application.Admin', 'application-admin-password', null, true,
        RoleModel::APPLICATION_ADMINISTRATOR, true
    );
    $storedApplicationAdmin = $repository->findUser($applicationAdmin['username']);
    userManagementFailure(
        fn () => $service->assignAuthorization(
            'Application.Admin', RoleModel::SYSTEM_ADMINISTRATOR, true,
            RoleModel::APPLICATION_ADMINISTRATOR, $storedApplicationAdmin['id']
        ),
        'AUTHORIZATION_DENIED',
        403
    );
    userManagementFailure(
        fn () => $service->createUser('new.user', 'another-password-123', RoleModel::READ_ONLY, false, null),
        'USER_ALREADY_EXISTS',
        409
    );
    $disabledCreated = $service->createUser('Initially.Disabled', 'disabled-password-123', RoleModel::READ_ONLY, false, null, false);
    userManagementAssert($disabledCreated['enabled'] === false, 'Initial disabled status was not stored.');
    userManagementFailure(
        fn () => $authService->login('Initially.Disabled', 'disabled-password-123'),
        'INVALID_CREDENTIALS',
        401
    );
    $stored = $repository->findUser('New.User');
    userManagementAssert(!array_key_exists('password', $stored), 'Plaintext password was stored.');
    userManagementAssert(
        $stored['passwordHash'] !== 'new-user-password-123'
            && $hasher->verify('new-user-password-123', $stored['passwordHash']),
        'Created password was not safely hashed.'
    );

    $createdUserId = $repository->findUser('New.User')['id'];
    $authService->login('New.User', 'new-user-password-123');
    $renamed = $service->updateUserProfile('New.User', 'Renamed User', 'Renamed.User', '+15550000011', 'renamed@example.test');
    userManagementAssert(
        $repository->findUser('Renamed.User')['id'] === $createdUserId,
        'Username update changed stable user identity.'
    );
    userManagementAssert(
        $renamed['name'] === 'Renamed User' && $renamed['mobile'] === '+15550000011'
            && $renamed['email'] === 'renamed@example.test',
        'User profile update did not persist safe profile fields.'
    );
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementFailure(fn () => $authService->login('New.User', 'new-user-password-123'), 'INVALID_CREDENTIALS', 401);
    userManagementFailure(fn () => $service->updateUsername('Renamed.User', 'Operator'), 'USER_ALREADY_EXISTS', 409);
    $authService->login('Renamed.User', 'new-user-password-123');
    $service->setEnabled('Renamed.User', false);
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementFailure(fn () => $authService->login('Renamed.User', 'new-user-password-123'), 'INVALID_CREDENTIALS', 401);
    $service->setEnabled('Renamed.User', true);
    $authService->login('Renamed.User', 'new-user-password-123');

    $service->changePassword('Renamed.User', 'changed-password-123');
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementFailure(fn () => $authService->login('Renamed.User', 'new-user-password-123'), 'INVALID_CREDENTIALS', 401);
    $authService->login('Renamed.User', 'changed-password-123');
    destroyUserManagementSession($sessionName);

    $service->deleteUser('Renamed.User', 'Admin');
    userManagementFailure(fn () => $authService->login('Renamed.User', 'changed-password-123'), 'INVALID_CREDENTIALS', 401);
    userManagementFailure(fn () => $service->setEnabled('Missing', true), 'USER_NOT_FOUND', 404);
    $service->deleteUser('Initially.Disabled', 'Admin');
    $userLog = implode('', array_map(
        static fn (string $file): string => (string)file_get_contents($file),
        glob($logPath . '/*.log') ?: []
    ));
    foreach (['new-user-password-123', 'changed-password-123', $stored['passwordHash']] as $secret) {
        userManagementAssert(!str_contains($userLog, $secret), 'User-management logs exposed credential material.');
    }

    userManagementFailure(fn () => $service->deleteUser('Admin', 'admin'), 'LAST_ENABLED_ADMIN', 403);
    userManagementFailure(fn () => $service->setEnabled('Admin', false), 'LAST_ENABLED_ADMIN', 403);
    userManagementFailure(fn () => $service->deleteUser('Admin', 'Other.Admin'), 'LAST_ENABLED_ADMIN', 403);

    $service->createUser('Second.Admin', 'second-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, false, null);
    $admin = $repository->findUser('Admin');
    $session->establish('Admin', $admin['id'], $admin['authVersion']);
    $service->setEnabled('Admin', false);
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementAssert(session_status() !== PHP_SESSION_ACTIVE, 'Disabled account session was not invalidated.');
    $service->setEnabled('Admin', true);
    $service->deleteUser('Second.Admin', 'Admin');
    userManagementAssert($repository->findUser('Second.Admin') === null, 'A non-last enabled administrator could not be deleted.');

    $service->createUser('Temporary.User', 'temporary-password', RoleModel::READ_ONLY, false, null);
    $authService->login('Temporary.User', 'temporary-password');
    $service->deleteUser('Temporary.User', 'Admin');
    userManagementFailure(fn () => $authentication->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    userManagementAssert(session_status() !== PHP_SESSION_ACTIVE, 'Deleted account session was not invalidated.');

    $validator = new UserManagementRequestValidator();
    $validatedCreate = $validator->validate([
        'action' => 'auth.users.create',
        'name' => 'Default Role',
        'username' => 'Default.Role',
        'mobile' => '+15550000001',
        'email' => null,
        'password' => 'valid-password-123',
        'passwordConfirmation' => 'valid-password-123',
        'role' => RoleModel::READ_ONLY,
    ]);
    userManagementAssert($validatedCreate['backendRole'] === RoleModel::READ_ONLY && $validatedCreate['frontendAccess'] === true && $validatedCreate['frontendRole'] === null, 'Create-user role preset was not validated safely.');
    userManagementAssert($validatedCreate['enabled'] === true, 'Create-user enabled status did not default safely.');
    $expectedPresets = [
        RoleModel::SYSTEM_ADMINISTRATOR => [RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR],
        RoleModel::APPLICATION_ADMINISTRATOR => [null, true, RoleModel::APPLICATION_ADMINISTRATOR],
        RoleModel::DATA_OPERATOR => [RoleModel::DATA_OPERATOR, true, null],
        RoleModel::READ_ONLY => [RoleModel::READ_ONLY, true, null],
    ];
    foreach ($expectedPresets as $role => [$backendRole, $frontendAccess, $frontendRole]) {
        $preset = $validator->validate(['action' => 'auth.users.assignAuthorization', 'username' => 'Operator', 'role' => $role]);
        userManagementAssert(
            $preset['backendRole'] === $backendRole && $preset['frontendAccess'] === $frontendAccess
                && $preset['frontendRole'] === $frontendRole,
            "Role preset {$role} was not deterministic."
        );
    }
    $validatedUpdate = $validator->validate([
        'action' => 'auth.users.update',
        'username' => 'Operator',
        'name' => 'Updated Operator',
        'newUsername' => 'Updated.Operator',
        'mobile' => '+15550000002',
        'email' => '',
    ]);
    userManagementAssert($validatedUpdate['newUsername'] === 'Updated.Operator', 'Username update was not validated.');
    userManagementAssert($validatedUpdate['name'] === 'Updated Operator' && $validatedUpdate['email'] === null, 'User profile was not validated.');
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.create',
        'name' => 'Unsafe User',
        'username' => 'Unsafe.User',
        'mobile' => '+15550000003',
        'password' => 'valid-password-123',
        'passwordConfirmation' => 'valid-password-123',
        'passwordHash' => 'client-hash',
    ]), 'INVALID_USER_REQUEST', 400);
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.assignAuthorization',
        'username' => 'Operator',
        'role' => RoleModel::READ_ONLY,
        'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR,
        'frontendAccess' => true,
        'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR,
    ]), 'INVALID_USER_REQUEST', 400);
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.create',
        'name' => 'Injected User',
        'username' => 'Injected.User',
        'mobile' => '+15550000004',
        'email' => null,
        'password' => 'valid-password-123',
        'passwordConfirmation' => 'valid-password-123',
        'role' => RoleModel::READ_ONLY,
        'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR,
    ]), 'INVALID_USER_REQUEST', 400);
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.changePassword',
        'username' => 'Operator',
        'newPassword' => 'valid-password-123',
        'passwordConfirmation' => 'different-password',
    ]), 'INVALID_USER_REQUEST', 400);
    userManagementFailure(fn () => $validator->validate([
        'action' => 'auth.users.changePassword',
        'username' => 'Operator',
        'newPassword' => 'short',
        'passwordConfirmation' => 'short',
    ]), 'INVALID_USER_REQUEST', 400);

    $concurrentPath = $concurrentDirectory . DIRECTORY_SEPARATOR . 'auth.json';
    $concurrentRepository = new AuthRepository($concurrentPath);
    $concurrentRepository->save(['version' => 1, 'users' => [[
        'username' => 'Admin',
        'passwordHash' => $hasher->hash('admin-password-123'),
        'enabled' => true,
        'isAdmin' => true,
    ]]]);
    $workers = [];
    foreach (['race-password-123', 'race-password-456'] as $password) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/UserCreationConcurrentWorker.php', $concurrentPath, 'Race.User', $password],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        userManagementAssert(is_resource($process), 'Unable to start concurrent user worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        userManagementAssert(stream_get_contents($pipes[1]) === '', 'Concurrent worker exposed output.');
        userManagementAssert(stream_get_contents($pipes[2]) === '', 'Concurrent worker exposed an error.');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $statuses[] = proc_close($process);
    }
    sort($statuses);
    userManagementAssert($statuses === [0, 2], 'Concurrent duplicate creation was not serialized.');
    $raceUsers = array_values(array_filter(
        $concurrentRepository->load()['users'],
        fn (array $user): bool => strcasecmp($user['username'], 'Race.User') === 0
    ));
    userManagementAssert(count($raceUsers) === 1, 'Concurrent creation stored duplicate usernames.');

    echo "Admin user management tests passed.\n";
} finally {
    destroyUserManagementSession($sessionName);
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath);
    foreach (glob($logPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($logPath);
    removeUserManagementFixture($mainDirectory);
    removeUserManagementFixture($concurrentDirectory);
    @unlink($adminPath);
    @unlink($adminPath . '.lock');
    @rmdir($root);
    $oldAdminPath === false
        ? putenv('GENERIC_ADMIN_CONFIG_PATH')
        : putenv('GENERIC_ADMIN_CONFIG_PATH=' . $oldAdminPath);
}
