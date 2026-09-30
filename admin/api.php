<?php

putenv('GENERIC_ADMIN_ENABLED=1');

define('API_REQUEST_STARTED', microtime(true));
define('API_REQUEST_ID', bin2hex(random_bytes(8)));
ob_start();

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';
ExceptionHandler::register('admin');
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AdminAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/LocalAdminMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Middleware/LoggingMiddleware.php';
require_once __DIR__ . '/../app/Middleware/ApiRateLimitMiddleware.php';
require_once __DIR__ . '/../app/Requests/SetupRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/SetupController.php';
require_once __DIR__ . '/../app/Requests/AuthRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Requests/UserManagementRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/UserManagementController.php';
require_once __DIR__ . '/../app/Requests/ApiKeyRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/ApiKeyController.php';
require_once __DIR__ . '/../app/Controllers/RoleController.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/AdminController.php';
require_once __DIR__ . '/../app/Http/RequestBodyReader.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (strtolower((string)(getenv('GENERIC_APP_ENV') ?: 'development')) !== 'production') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405, 'METHOD_NOT_ALLOWED');
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    Response::error('Content-Type must be application/json.', 415, 'UNSUPPORTED_MEDIA_TYPE');
}
// Backup ZIP uploads are base64 encoded in the authenticated Admin request.
// The validator and recovery service apply the lower decoded-archive limits.
$request = json_decode(RequestBodyReader::read(null, null, 30 * 1024 * 1024), true);
if (json_last_error() !== JSON_ERROR_NONE) Response::error('Invalid JSON request.', 400, 'INVALID_JSON');
if (!is_array($request) || array_is_list($request)) {
    Response::error('Invalid request.', 400, 'INVALID_REQUEST', [['path' => '', 'message' => 'Request body must be a JSON object.']]);
}
Response::setRequestContext(['action' => is_string($request['action'] ?? null) ? $request['action'] : null]);
(new LoggingMiddleware())->handle($request);

$setupActions = ['setup.status', 'setup.createAdmin'];
$authActions = ['auth.csrf', 'auth.login', 'auth.session', 'auth.logout'];
$userActions = [
    'auth.users.list', 'auth.users.create', 'auth.users.update', 'auth.users.enable',
    'auth.users.disable', 'auth.users.delete', 'auth.users.changePassword',
    'auth.users.assignAuthorization',
];
$apiKeyActions = ['auth.apiKeys.list','auth.apiKeys.create','auth.apiKeys.enable','auth.apiKeys.disable','auth.apiKeys.revoke'];
$roleActions = ['auth.roles.list'];
$adminActions = [
    'admin.status', 'admin.health', 'admin.system.info',
    'admin.console.restart',
    'admin.api.start', 'admin.api.stop', 'admin.api.restart',
    'admin.sqlParser.start', 'admin.sqlParser.stop', 'admin.sqlParser.restart',
    'admin.database.get', 'admin.database.connect', 'admin.database.disconnect', 'admin.database.restart', 'admin.database.test', 'admin.database.save',
    'admin.settings.get', 'admin.server.save',
    'admin.cors.save', 'admin.authentication.save',
    'admin.runtime.save',
    'admin.backup.history', 'admin.backup.create', 'admin.backup.download',
    'admin.backup.preview', 'admin.backup.restore', 'admin.backup.schedule',
    'admin.backup.schedule.save',
    'admin.operational.event',
];
$allActions = array_merge($setupActions, $authActions, $userActions, $apiKeyActions, $roleActions, $adminActions);
if (!in_array($request['action'] ?? null, $allActions, true)) {
    Response::error('Not found.', 404, 'NOT_FOUND');
}

(new LocalAdminMiddleware())->handle($request);
(new AuthenticationMiddleware(true, array_merge($setupActions, $authActions)))->handle($request);
(new ApiRateLimitMiddleware())->handle($request);
(new AdminAuthorizationMiddleware(array_merge($userActions, $apiKeyActions, $roleActions, $adminActions)))->handle($request);
(new CsrfProtectionMiddleware())->handle($request);
if (in_array($request['action'], $setupActions, true)) {
    $validated = (new SetupRequestValidator())->validate($request);
    Response::setRequestContext(['action' => $validated['action']]);
    $controller = new SetupController();
    if ($validated['action'] === 'setup.status') $controller->status($validated);
    $controller->createAdmin($validated);
}
if (in_array($request['action'], $authActions, true)) {
    $validated = (new AuthRequestValidator())->validate($request);
    Response::setRequestContext(['action' => $validated['action']]);
    $controller = new AuthController();
    if ($validated['action'] === 'auth.csrf') $controller->csrf($validated);
    if ($validated['action'] === 'auth.login') $controller->login($validated);
    if ($validated['action'] === 'auth.session') $controller->session($validated);
    $controller->logout($validated);
}
if (in_array($request['action'], $userActions, true)) {
    $validated = (new UserManagementRequestValidator())->validate($request);
    Response::setRequestContext(['action' => $validated['action']]);
    $controller = new UserManagementController();
    if ($validated['action'] === 'auth.users.list') $controller->listUsers($validated);
    if ($validated['action'] === 'auth.users.create') $controller->createUser($validated);
    if ($validated['action'] === 'auth.users.update') $controller->updateUser($validated);
    if ($validated['action'] === 'auth.users.enable') $controller->enableUser($validated);
    if ($validated['action'] === 'auth.users.disable') $controller->disableUser($validated);
    if ($validated['action'] === 'auth.users.delete') $controller->deleteUser($validated);
    if ($validated['action'] === 'auth.users.assignAuthorization') $controller->assignAuthorization($validated);
    $controller->changePassword($validated);
}
if (in_array($request['action'], $apiKeyActions, true)) {
    $validated=(new ApiKeyRequestValidator())->validate($request); Response::setRequestContext(['action'=>$validated['action']]); (new ApiKeyController())->dispatch($validated);
}
if (in_array($request['action'], $roleActions, true)) {
    if(array_keys($request)!==['action'])Response::error('Invalid role request.',400,'INVALID_ROLE_REQUEST'); Response::setRequestContext(['action'=>$request['action']]); (new RoleController())->list($request);
}
$validated = (new AdminRequestValidator())->validate($request);
Response::setRequestContext(['action' => $validated['action']]);
(new AdminController())->dispatch($validated);
