<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/FrontendUserAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';
require_once __DIR__ . '/../app/Requests/FrontendUserRequestValidator.php';
require_once __DIR__ . '/../app/Requests/ApiKeyRequestValidator.php';

function authorizationAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function authorizationFails(callable $operation, string $code): void {
    try { $operation(); }
    catch (ApiRequestException $exception) { authorizationAssert($exception->getErrorCode() === $code, "Expected {$code}, got {$exception->getErrorCode()}."); return; }
    throw new RuntimeException("Expected {$code}.");
}
function authorizationRemove(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $path=$directory.DIRECTORY_SEPARATOR.$name; is_dir($path) ? authorizationRemove($path) : @unlink($path); }
    @rmdir($directory);
}
function authorizationPrincipal(?string $backendRole, bool $frontendAccess=false, ?string $frontendRole=null, bool $enabled=true): Principal {
    return new Principal(null, 'test-user', 'test', $backendRole, $frontendAccess, $frontendRole, $enabled);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-sql-authorization-' . bin2hex(random_bytes(8));
$oldRuntime = getenv('GENERIC_RUNTIME_CONFIG_DIR');
try {
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory);
    RuntimeConfiguration::ensure();
    $legacyAuthorizationPath = $directory . '/legacy-authorization.json';
    $legacyAuthorization = RuntimeConfiguration::authorizationDefaults();
    $legacyAuthorization['version'] = 2;
    unset($legacyAuthorization['roles'][RoleModel::API_ADMINISTRATOR]);
    $legacyAuthorization['roles'][RoleModel::DATA_OPERATOR]['writeResources'] = ['legacy-custom-scope'];
    JsonFileStore::save($legacyAuthorizationPath, $legacyAuthorization);
    $migratedAuthorization = (new AuthorizationRepository($legacyAuthorizationPath))->load();
    authorizationAssert($migratedAuthorization['version'] === 3
        && $migratedAuthorization['roles'][RoleModel::API_ADMINISTRATOR]['writeResources'] === ['legacy-custom-scope'],
        'Authorization migration discarded customized API resource scopes.');

    $legacyApiKeyPath = $directory . '/legacy-api-keys.json';
    JsonFileStore::save($legacyApiKeyPath, ['version' => 2, 'keys' => [[
        'id' => str_repeat('a', 16), 'name' => 'Legacy administrator key',
        'ownerUserId' => str_repeat('b', 32), 'roles' => [RoleModel::SYSTEM_ADMINISTRATOR],
        'secretHash' => password_hash('legacy-secret', PASSWORD_DEFAULT),
        'fingerprint' => str_repeat('c', 12), 'enabled' => true, 'revokedAt' => null,
        'createdAt' => gmdate(DATE_ATOM), 'lastUsedAt' => null,
    ]]]);
    $migratedKeys = (new ApiKeyRepository($legacyApiKeyPath))->load();
    authorizationAssert($migratedKeys['version'] === 3
        && $migratedKeys['keys'][0]['roles'] === [RoleModel::API_ADMINISTRATOR],
        'Legacy System Administrator API key was not safely downgraded during migration.');

    $users = new UserManagementService();
    $admin = $users->createUser('System.Admin', 'system-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $reader = $users->createUser('Reader', 'read-only-password', RoleModel::READ_ONLY, false, null);
    $operator = $users->createUser('Operator', 'data-operator-password', RoleModel::DATA_OPERATOR, false, null);
    $storedSystemAdmin = (new AuthRepository())->findUser('System.Admin');
    $systemPrincipal = new Principal($storedSystemAdmin['id'], $storedSystemAdmin['username'], 'session', $storedSystemAdmin['backendRole'], $storedSystemAdmin['frontendAccess'], $storedSystemAdmin['frontendRole'], true);
    $applicationAdmin = $users->createFrontendUser($systemPrincipal, 'Application Admin', 'Application.Admin', '+15551234567', null, 'application-admin-password', RoleModel::APPLICATION_ADMINISTRATOR);
    authorizationAssert($admin['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR && $reader['backendRole'] === RoleModel::READ_ONLY, 'Users did not receive the requested authorization domains.');
    authorizationAssert(!array_key_exists('passwordHash', $applicationAdmin) && $applicationAdmin['backendRole'] === null && $applicationAdmin['backendProtected'] === false, 'Frontend user response exposed credential details or malformed role state.');
    $frontendValidator = new FrontendUserRequestValidator();
    authorizationFails(fn () => $frontendValidator->validate([
        'action'=>'auth.frontendUsers.assignRole', 'username'=>'Application.Admin',
        'frontendRole'=>RoleModel::APPLICATION_ADMINISTRATOR, 'backendRole'=>RoleModel::SYSTEM_ADMINISTRATOR,
    ]), 'INVALID_FRONTEND_USER_REQUEST');
    $users->updateFrontendProfile($systemPrincipal, 'Application.Admin', 'Application Owner', 'Application.Owner', '+15551234567', 'owner@example.test');
    $users->changeFrontendUserPassword($systemPrincipal, 'Application.Owner', 'replacement-application-password');
    $users->setFrontendUserEnabled($systemPrincipal, 'Application.Owner', false);
    $users->setFrontendUserEnabled($systemPrincipal, 'Application.Owner', true);
    $users->assignFrontendAccess($systemPrincipal, 'Application.Owner', true, null);
    authorizationAssert((new AuthRepository())->findUser('Application.Owner')['backendRole'] === null, 'Frontend management granted backend access.');
    $storedApplicationAdmin = (new AuthRepository())->findUser('Application.Owner');
    $applicationPrincipal = new Principal($storedApplicationAdmin['id'], $storedApplicationAdmin['username'], 'session', null, true, RoleModel::APPLICATION_ADMINISTRATOR, true);
    foreach ([
        fn () => $users->updateFrontendProfile($applicationPrincipal, 'System.Admin', 'Unsafe Admin', 'Unsafe.Admin', '+15551234567', null),
        fn () => $users->changeFrontendUserPassword($applicationPrincipal, 'System.Admin', 'unsafe-password-change'),
        fn () => $users->setFrontendUserEnabled($applicationPrincipal, 'System.Admin', false),
        fn () => $users->deleteFrontendUser($applicationPrincipal, 'System.Admin'),
    ] as $operation) authorizationFails($operation, 'AUTHORIZATION_DENIED');
    $users->deleteFrontendUser($systemPrincipal, 'Application.Owner');
    authorizationAssert((new AuthRepository())->findUser('Application.Owner') === null, 'Frontend-only identity was not deleted.');

    $middleware = new AuthorizationMiddleware();
    PrincipalContext::set(authorizationPrincipal(RoleModel::READ_ONLY));
    $middleware->handle(['action'=>'select']); $middleware->handle(['action'=>'sql','resource'=>'reports/sales']);
    authorizationFails(fn () => $middleware->handle(['action'=>'insert','resource'=>'customers']), 'RESOURCE_ACCESS_DENIED');
    authorizationFails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');
    PrincipalContext::set(authorizationPrincipal(RoleModel::DATA_OPERATOR));
    $middleware->handle(['action'=>'select']); $middleware->handle(['action'=>'upsert','resource'=>'customers']);
    authorizationFails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(authorizationPrincipal(RoleModel::SYSTEM_ADMINISTRATOR));
    foreach ([['action'=>'select'],['action'=>'delete','resource'=>'customers'],['action'=>'admin.status']] as $request) $middleware->handle($request);

    PrincipalContext::set(authorizationPrincipal(null, true, RoleModel::APPLICATION_ADMINISTRATOR));
    foreach ([['action'=>'select'],['action'=>'union'],['action'=>'metadata.tables'],['action'=>'sql','resource'=>'reports/sales']] as $request) $middleware->handle($request);
    authorizationFails(fn () => $middleware->handle(['action'=>'insert','resource'=>'customers']), 'RESOURCE_ACCESS_DENIED');
    authorizationFails(fn () => $middleware->handle(['action'=>'admin.status']), 'AUTHORIZATION_DENIED');
    authorizationFails(fn () => $middleware->handle([
        'action'=>'auth.users.assignAuthorization',
        'username'=>'Reader',
        'backendRole'=>RoleModel::SYSTEM_ADMINISTRATOR,
    ]), 'AUTHORIZATION_DENIED');
    (new FrontendUserAuthorizationMiddleware(['auth.frontendUsers.list']))->handle(['action'=>'auth.frontendUsers.list']);
    authorizationFails(fn () => (new AuthorizationService())->authorize(PrincipalContext::current(), 'admin.manage'), 'AUTHORIZATION_DENIED');

    PrincipalContext::set(authorizationPrincipal(null, true));
    $middleware->handle(['action'=>'select']);
    authorizationFails(fn () => (new FrontendUserAuthorizationMiddleware(['auth.frontendUsers.list']))->handle(['action'=>'auth.frontendUsers.list']), 'AUTHORIZATION_DENIED');
    PrincipalContext::set(authorizationPrincipal(RoleModel::READ_ONLY, false, null, false));
    authorizationFails(fn () => $middleware->handle(['action'=>'select']), 'AUTHORIZATION_DENIED');

    $before = (new AuthRepository())->findUser('System.Admin')['authVersion'];
    $users->assignAuthorization('System.Admin', RoleModel::SYSTEM_ADMINISTRATOR, false, null, str_repeat('f', 32));
    authorizationAssert((new AuthRepository())->findUser('System.Admin')['authVersion'] > $before, 'Authorization change did not invalidate sessions.');
    authorizationFails(fn () => $users->assignAuthorization('System.Admin', RoleModel::READ_ONLY, false, null, str_repeat('f', 32)), 'LAST_ENABLED_ADMIN');
    authorizationFails(fn () => $users->assignAuthorization(
        'System.Admin', RoleModel::READ_ONLY, true, null, $storedSystemAdmin['id']
    ), 'AUTHORIZATION_DENIED');
    authorizationFails(fn () => $users->setEnabled('System.Admin', false), 'LAST_ENABLED_ADMIN');
    authorizationFails(fn () => $users->deleteUser('System.Admin', 'Other.Admin'), 'LAST_ENABLED_ADMIN');

    $keys = new ApiKeyService();
    $keyValidator = new ApiKeyRequestValidator();
    foreach (RoleModel::apiKeyRoles() as $allowedRole) {
        $validatedKeyRequest = $keyValidator->validate([
            'action' => 'auth.apiKeys.create', 'name' => 'Validation key',
            'ownerUsername' => 'Operator', 'roles' => [$allowedRole],
        ]);
        authorizationAssert($validatedKeyRequest['roles'] === [$allowedRole], "API key role {$allowedRole} was rejected.");
    }
    authorizationFails(fn () => $keyValidator->validate([
        'action' => 'auth.apiKeys.create', 'name' => 'Escalated key',
        'ownerUsername' => 'Operator', 'roles' => [RoleModel::SYSTEM_ADMINISTRATOR],
    ]), 'INVALID_API_KEY_REQUEST');
    $readKey = $keys->create('Read integration', 'Reader', [RoleModel::READ_ONLY]);
    $created = $keys->create('Build integration', 'Operator', [RoleModel::DATA_OPERATOR]);
    $adminKey = $keys->create('API administration', 'Operator', [RoleModel::API_ADMINISTRATOR]);
    authorizationAssert($readKey['roles'] === [RoleModel::READ_ONLY]
        && $created['roles'] === [RoleModel::DATA_OPERATOR]
        && $adminKey['roles'] === [RoleModel::API_ADMINISTRATOR], 'Supported API key roles were not accepted.');
    authorizationAssert(str_starts_with($created['apiKey'], 'gsk_') && strlen($created['fingerprint']) === 12, 'API key was not generated safely.');
    authorizationAssert(!str_contains(json_encode((new ApiKeyRepository())->load(), JSON_THROW_ON_ERROR), $created['apiKey']), 'Plaintext API key reached storage.');
    authorizationAssert($keys->authenticate($created['apiKey'])['owner']['username'] === 'Operator', 'API key owner was not resolved.');
    authorizationFails(fn () => $keys->create('Escalated', 'Operator', [RoleModel::SYSTEM_ADMINISTRATOR]), 'INVALID_API_KEY_REQUEST');
    authorizationFails(fn () => $keys->create('Invalid', 'Operator', [RoleModel::APPLICATION_ADMINISTRATOR]), 'INVALID_API_KEY_REQUEST');
    authorizationFails(fn () => $keys->create('Multiple', 'Operator', [RoleModel::READ_ONLY, RoleModel::DATA_OPERATOR]), 'INVALID_API_KEY_REQUEST');
    $keys->setEnabled($created['id'], false); authorizationAssert($keys->authenticate($created['apiKey']) === null, 'Disabled API key authenticated.');
    $keys->setEnabled($created['id'], true); $keys->revoke($created['id']); authorizationAssert($keys->authenticate($created['apiKey']) === null, 'Revoked API key authenticated.');

    $apiAdministrator = new Principal(null, 'api-admin', 'api_key', RoleModel::API_ADMINISTRATOR, false, null, true);
    $authorization = new AuthorizationService();
    $authorization->authorize($apiAdministrator, 'data.read');
    $authorization->authorize($apiAdministrator, 'data.write', 'customers', 'write');
    authorizationFails(fn () => $authorization->authorize($apiAdministrator, 'admin.manage'), 'AUTHORIZATION_DENIED');
    authorizationFails(fn () => $authorization->authorize($apiAdministrator, 'frontend.users.manage'), 'AUTHORIZATION_DENIED');

    $adminJavaScript = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.js');
    foreach (['serviceControls("api", health.api)', 'serviceControls("sqlParser", health.sqlParser)', 'data-database-runtime', 'auth.users.assignAuthorization', '"system-administrator": "Super Admin"', '"application-administrator": "Admin"', '"api-administrator": "Admin"', 'role.apiKeyAssignable === true'] as $marker) authorizationAssert(str_contains($adminJavaScript, $marker), "Admin UI is missing {$marker}.");
    authorizationAssert(!str_contains($adminJavaScript, 'admin.features.save'), 'Removed global feature controls remain in the Admin Console.');
    foreach (['start-linux.sh','start-windows.bat'] as $launcher) {
        $source=(string)file_get_contents(__DIR__.'/../'.$launcher);
        authorizationAssert(str_contains($source, 'api-runtime-control.php') && str_contains($source, 'sqlparser-runtime-control.php') && str_contains($source, 'database-runtime-control.php'), "{$launcher} does not establish the fixed development runtime.");
        authorizationAssert(!preg_match('/systemctl|iisreset|appcmd|net\s+(?:start|stop)|sc\s+(?:start|stop)|nginx\s+-s/i', $source), "{$launcher} controls production infrastructure.");
    }
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    foreach (['auth.users.assignAuthorization','auth.frontendUsers.create','auth.frontendUsers.assignRole','auth.apiKeys.create','admin.database.connect'] as $action) authorizationFails(fn () => (new CsrfProtectionMiddleware())->handle(['action'=>$action]), 'CSRF_VALIDATION_FAILED');

    echo "Authorization and API key tests passed.\n";
} finally {
    PrincipalContext::clear();
    $oldRuntime === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntime);
    authorizationRemove($directory);
}
