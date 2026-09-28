<?php

require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Middleware/LocalAdminMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Security/SecurityConfiguration.php';
require_once __DIR__ . '/../app/Security/ApiKeyAuthenticator.php';

function unifiedAdminAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function unifiedAdminFailure(callable $operation, string $message, ?string $errorCode = null): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($errorCode !== null) {
            unifiedAdminAssert(
                $exception instanceof ApiRequestException && $exception->getErrorCode() === $errorCode,
                $message . ' Wrong error was returned.'
            );
        }
        return;
    }
    throw new RuntimeException($message);
}

$temporaryDirectory = sys_get_temp_dir() . '/generic-sql-admin-' . bin2hex(random_bytes(8));
$adminPath = $temporaryDirectory . '/admin.json';
$databasePath = $temporaryDirectory . '/database.json';
$authPath = $temporaryDirectory . '/auth.json';
$sessionPath = $temporaryDirectory . '/sessions';
$oldAdminPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
$oldEncryptionKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
$oldApiKey = getenv(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE);
$oldAdminEnabled = getenv('GENERIC_ADMIN_ENABLED');
$oldOriginOverride = getenv('GENERIC_API_ALLOWED_ORIGINS');

try {
    mkdir($temporaryDirectory, 0700, true);
    mkdir($sessionPath, 0700, true);
    $repository = new AdminConfigurationRepository($adminPath);
    $repository->save(AdminConfigurationRepository::defaults());
    putenv('GENERIC_ADMIN_CONFIG_PATH=' . $adminPath);
    putenv('GENERIC_API_ALLOWED_ORIGINS');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));

    $validator = new AdminRequestValidator();
    $linuxValidator = new AdminRequestValidator(new DatabaseAuthenticationSupport('Linux'));
    $windowsValidator = new AdminRequestValidator(new DatabaseAuthenticationSupport('Windows'));
    unifiedAdminAssert(
        SqlServerDriver::cursorMode('Windows') !== SqlServerDriver::cursorMode('Linux'),
        'SQL Server cursor handling is not platform-aware.'
    );
    $corsRequest = $validator->validate([
        'action' => 'admin.cors.save',
        'cors' => [
            'allowedOrigins' => ['https://reports.example.test:8443'],
            'credentialsEnabled' => false,
            'allowedMethods' => ['POST', 'OPTIONS'],
        ],
    ]);
    unifiedAdminAssert(
        $corsRequest['cors']['allowedOrigins'] === ['https://reports.example.test:8443'],
        'Exact CORS origin was not preserved.'
    );
    unifiedAdminFailure(fn () => $validator->validate([
        'action' => 'admin.cors.save',
        'cors' => ['allowedOrigins' => ['*'], 'credentialsEnabled' => true, 'allowedMethods' => ['POST']],
    ]), 'Wildcard CORS origin was accepted.');
    unifiedAdminFailure(fn () => $validator->validate([
        'action' => 'admin.cors.save',
        'cors' => ['allowedOrigins' => ['https://example.test/path'], 'credentialsEnabled' => true, 'allowedMethods' => ['POST']],
    ]), 'CORS origin with a path was accepted.');

    $validDatabase = [
        'provider' => 'sqlserver',
        'driver' => 'auto',
        'server' => 'sql.example.test',
        'port' => '1433',
        'database' => 'Reporting',
        'authentication' => 'sql',
        'username' => 'reporting_user',
        'password' => 'first-secret',
        'encrypt' => true,
        'trustServerCertificate' => false,
    ];
    $normalizedDatabase = $validator->validate([
        'action' => 'admin.database.save',
        'database' => $validDatabase,
    ])['database'];
    unifiedAdminFailure(fn () => $linuxValidator->validate([
        'action' => 'admin.database.save',
        'database' => [...$validDatabase, 'authentication' => 'windows', 'username' => ''],
    ]), 'Windows database authentication was accepted on Linux.');
    $windowsDatabase = $windowsValidator->validate([
        'action' => 'admin.database.save',
        'database' => [...$validDatabase, 'authentication' => 'windows', 'username' => ''],
    ])['database'];
    unifiedAdminAssert($windowsDatabase['authentication'] === 'windows', 'Implemented Windows database authentication was rejected on Windows.');
    unifiedAdminFailure(fn () => $validator->validate([
        'action' => 'admin.database.save',
        'database' => [...$validDatabase, 'server' => 'server;UID=attacker'],
    ]), 'Connection-string injection was accepted.');

    $connectionTests = 0;
    $testedDatabase = null;
    $service = new AdminService(
        $repository,
        $databasePath,
        function (array $database) use (&$connectionTests, &$testedDatabase): void {
            $connectionTests++;
            $testedDatabase = $database;
        }
    );
    $service->testDatabase($normalizedDatabase);
    unifiedAdminAssert($connectionTests === 1 && $testedDatabase['password'] === 'first-secret', 'Connection test did not receive validated credentials.');
    $failingService = new AdminService($repository, $databasePath, function (): void {
        throw new RuntimeException('driver leaked password=do-not-return');
    });
    try {
        $failingService->testDatabase($normalizedDatabase);
        throw new RuntimeException('Failed connection test was accepted.');
    } catch (ApiRequestException $exception) {
        unifiedAdminAssert($exception->getMessage() === 'Database connection failed.', 'Connection failure exposed driver details.');
    }
    $service->saveDatabase($normalizedDatabase);
    $service->testCurrentDatabase();
    unifiedAdminAssert($connectionTests === 2, 'Current database test did not open a request-scoped connection.');
    $stored = json_decode((string)file_get_contents($databasePath), true, 512, JSON_THROW_ON_ERROR);
    unifiedAdminAssert(($stored['encrypted'] ?? false) === true, 'Database configuration was not encrypted.');
    unifiedAdminAssert(!str_contains((string)file_get_contents($databasePath), 'first-secret'), 'Plaintext password reached database storage.');
    unifiedAdminAssert(glob($databasePath . '.backup*') === [], 'A plaintext backup artifact was created.');
    $publicDatabase = $service->databaseConfiguration();
    unifiedAdminAssert(!array_key_exists('password', $publicDatabase), 'Database password was returned by the admin API.');
    unifiedAdminAssert(!array_key_exists('ciphertext', $publicDatabase), 'Encrypted credential material was returned by the admin API.');
    unifiedAdminAssert(
        $publicDatabase['availableAuthenticationModes'] === (PHP_OS_FAMILY === 'Windows' ? ['sql', 'windows'] : ['sql']),
        'Database authentication choices were not platform-aware.'
    );

    $updatedDatabase = $normalizedDatabase;
    $updatedDatabase['password'] = 'second-secret';
    $service->saveDatabase($updatedDatabase);
    unifiedAdminAssert(
        DatabaseConfigurationResolver::load($databasePath)['password'] === 'second-secret',
        'Database password update was not persisted.'
    );

    foreach (AdminConfigurationRepository::AUTHENTICATION_MODES as $mode) {
        $service->saveAuthentication($mode);
        unifiedAdminAssert((new AdminConfigurationRepository($adminPath))->load()['authentication']['mode'] === $mode, "Authentication mode {$mode} was not persisted.");
    }
    $service->saveCors($corsRequest['cors']);
    unifiedAdminAssert(SecurityConfiguration::allowedOrigins() === ['https://reports.example.test:8443'], 'Persisted CORS origins were not authoritative.');
    unifiedAdminAssert(SecurityConfiguration::corsCredentialsEnabled() === false, 'CORS credentials setting was not enforced.');

    putenv(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE . '=' . str_repeat('k', 32));
    $apiKeys = new ApiKeyAuthenticator();
    unifiedAdminAssert($apiKeys->authenticate(str_repeat('k', 32)), 'Configured API key was rejected.');
    unifiedAdminAssert(!$apiKeys->authenticate(str_repeat('x', 32)), 'Incorrect API key was accepted.');

    JsonFileStore::save($authPath, ['version' => 1, 'users' => []]);
    ini_set('session.save_path', $sessionPath);
    $session = new AuthSessionService('generic_admin_test_' . bin2hex(random_bytes(4)));
    $authentication = new AuthenticationMiddleware(true, [], $session, new AuthRepository($authPath), $apiKeys);
    $service->saveAuthentication('none');
    $authentication->handle(['action' => 'select']);
    (new CsrfProtectionMiddleware())->handle(['action' => 'insert']);
    $service->saveAuthentication('api_key');
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('k', 32);
    $authentication->handle(['action' => 'select']);
    unifiedAdminAssert(($_SERVER['GENERIC_AUTH_PROVIDER'] ?? null) === 'api_key', 'API-key provider was not recorded.');
    unifiedAdminAssert(session_status() !== PHP_SESSION_ACTIVE, 'API-key authentication created a browser session.');
    unset($_SERVER['GENERIC_AUTH_PROVIDER']);
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('x', 32);
    unifiedAdminFailure(fn () => $authentication->handle(['action' => 'select']), 'Invalid API key was accepted.', 'AUTHENTICATION_REQUIRED');
    $service->saveAuthentication('session+api_key');
    $_SERVER['HTTP_X_API_KEY'] = str_repeat('k', 32);
    $authentication->handle(['action' => 'select']);
    unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['GENERIC_AUTH_PROVIDER']);
    unifiedAdminFailure(fn () => $authentication->handle(['action' => 'select']), 'Session-or-key mode accepted no credential.', 'AUTHENTICATION_REQUIRED');
    $service->saveAuthentication('session');
    unifiedAdminFailure(fn () => $authentication->handle(['action' => 'select']), 'Session mode accepted no session.', 'AUTHENTICATION_REQUIRED');

    putenv('GENERIC_ADMIN_ENABLED=1');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    (new LocalAdminMiddleware())->handle(['action' => 'admin.status']);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    unifiedAdminFailure(
        fn () => (new LocalAdminMiddleware())->handle(['action' => 'admin.status']),
        'Remote admin request was accepted.',
        'NOT_FOUND'
    );
    putenv('GENERIC_ADMIN_ENABLED=0');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    unifiedAdminFailure(
        fn () => (new LocalAdminMiddleware())->handle(['action' => 'admin.status']),
        'Disabled admin API was accepted.',
        'NOT_FOUND'
    );

    $_SERVER['HTTP_X_API_KEY'] = str_repeat('k', 32);
    foreach (AdminConfigurationRepository::AUTHENTICATION_MODES as $mode) {
        $service->saveAuthentication($mode);
        unifiedAdminFailure(
            fn () => $authentication->handle(['action' => 'admin.status']),
            "Administrator endpoint accepted {$mode} normal-API authentication without an administrator session.",
            'AUTHENTICATION_REQUIRED'
        );
    }
    unset($_SERVER['HTTP_X_API_KEY']);

    $publicSettings = $service->settings();
    unifiedAdminAssert(!str_contains(json_encode($publicSettings, JSON_THROW_ON_ERROR), str_repeat('k', 32)), 'Configuration response exposed the API key secret.');

    $status = $service->status();
    $encodedStatus = json_encode($status, JSON_THROW_ON_ERROR);
    unifiedAdminAssert(!str_contains($encodedStatus, 'second-secret'), 'Status response exposed the database password.');
    unifiedAdminAssert(!str_contains($encodedStatus, getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)), 'Status response exposed the encryption key.');

    $windowsLauncher = (string)file_get_contents(__DIR__ . '/../start-windows.bat');
    $linuxLauncher = (string)file_get_contents(__DIR__ . '/../start-linux.sh');
    foreach ([$windowsLauncher, $linuxLauncher] as $launcher) {
        unifiedAdminAssert(str_contains($launcher, '127.0.0.1'), 'Launcher does not bind to loopback.');
        unifiedAdminAssert(!str_contains($launcher, '0.0.0.0'), 'Launcher exposes the admin console to the network.');
        unifiedAdminAssert(str_contains($launcher, 'find-available-port.php'), 'Launcher does not use safe port selection.');
        unifiedAdminAssert(str_contains($launcher, 'api-runtime-control.php') && str_contains($launcher, 'start'), 'Launcher does not automatically start the API.');
        unifiedAdminAssert(str_contains($launcher, 'sqlparser-runtime-control.php') && str_contains($launcher, 'start'), 'Launcher does not automatically start the SQL Parser.');
        unifiedAdminAssert(str_contains($launcher, 'database-runtime-control.php') && str_contains($launcher, 'connect'), 'Launcher does not automatically connect application database runtime access.');
        unifiedAdminAssert(str_contains($launcher, 'verify-development-runtime.php'), 'Launcher does not verify development runtime startup.');
        unifiedAdminAssert(str_contains($launcher, '/admin'), 'Launcher does not display the admin URL.');
    }
    $adminJavaScript = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.js');
    $adminHtml = (string)file_get_contents(__DIR__ . '/../admin/index.php');
    $adminCss = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.css');
    $compactAdminJavaScript = str_replace('"', "'", preg_replace('/\s+/', '', $adminJavaScript));
    $compactAdminCss = preg_replace('/\s+/', ' ', $adminCss);
    unifiedAdminAssert(str_contains($compactAdminJavaScript, 'availableAuthenticationModes.map'), 'Database authentication UI does not use backend-supported modes.');
    foreach ([
        'session' => 'Session',
        'api_key' => 'API Key',
        'session+api_key' => 'Session + API Key',
        'none' => 'None for normal API actions',
    ] as $mode => $label) {
        unifiedAdminAssert(
            str_contains($adminJavaScript, '<option value="' . $mode . '"') && str_contains($adminJavaScript, '>' . $label . '</option>'),
            "Admin Security UI does not expose the {$mode} authentication mode."
        );
    }
    unifiedAdminAssert(!str_contains($adminJavaScript, '(existing configuration)'), 'Supported authentication modes are still rendered as existing-only configuration.');
    unifiedAdminAssert(
        str_contains($adminJavaScript, 'item.controlMode === "application"')
            && str_contains($adminJavaScript, 'data-control-mode="application"')
            && str_contains($adminJavaScript, '>Enable</button>')
            && str_contains($adminJavaScript, '>Disable</button>')
            && str_contains($adminJavaScript, '>Reload</button>')
            && str_contains($adminJavaScript, '["Infrastructure", service.infrastructure?.status]')
            && str_contains($adminJavaScript, '["Application Runtime", service.applicationRuntime?.status]'),
        'Admin Console does not expose production application runtime controls and layered health.'
    );
    unifiedAdminAssert(str_contains($adminJavaScript, 'Welcome back')
        && str_contains($adminJavaScript, 'Sign in to continue to your workspace.'), 'Generic Admin login copy is missing.');
    preg_match('/function loginView\(\).*?content\.innerHTML = (`.*?`);/s', $adminJavaScript, $loginViewMatch);
    unifiedAdminAssert(isset($loginViewMatch[1])
        && str_contains($loginViewMatch[1], 'Generic SQL API')
        && !str_contains($loginViewMatch[1], 'brand-mark'), 'Admin login branding still renders the G logo or omits the application name.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'data-password-toggle'), 'Admin login password visibility control is missing.');
    unifiedAdminAssert(!str_contains($adminJavaScript, '2.0.0-dev')
        && str_contains($adminHtml, "require __DIR__ . '/../config/app.php'")
        && str_contains($adminHtml, 'data-app-version'), 'Admin UI does not use the centralized application version.');
    unifiedAdminAssert(!str_contains($adminHtml, '127.0.0.1 only')
        && !str_contains($adminHtml, 'local-pill'), 'Loopback-only UI warning remains visible.');
    unifiedAdminAssert(str_contains($adminHtml, 'id="sidebar-toggle"')
        && strpos($adminHtml, 'id="sidebar-toggle"') < strpos($adminHtml, '</aside>')
        && str_contains($adminHtml, 'id="sidebar-backdrop"')
        && str_contains($adminHtml, 'nav-icon')
        && str_contains($adminJavaScript, 'sidebarCollapsed')
        && str_contains($adminJavaScript, 'sidebarStorageKey'), 'Responsive sidebar controls or state are missing.');
    unifiedAdminAssert(str_contains($adminCss, 'position: sticky')
        && str_contains($adminCss, 'prefers-reduced-motion: reduce')
        && str_contains($adminCss, 'body.sidebar-collapsed')
        && str_contains($adminCss, 'body.sidebar-open .sidebar')
        && str_contains($adminCss, '@media (max-width: 640px)')
        && str_contains($adminCss, 'overflow-x: hidden'), 'Responsive sidebar layout or motion accessibility styles are incomplete.');
    unifiedAdminAssert(str_contains($adminHtml, '<span class="nav-label">Logout</span>')
        && strpos($adminHtml, 'id="logout"') < strpos($adminHtml, 'id="sidebar-version"'), 'Logout icon or version placement is incorrect.');
    unifiedAdminAssert(str_contains($compactAdminJavaScript, "'system-administrator':'SuperAdmin'")
        && str_contains($compactAdminJavaScript, "'application-administrator':'Admin'"), 'Admin role display labels are missing.');
    foreach (['Name *', 'Username *', 'Mobile Number *', 'Email (optional)', 'Password *', 'Confirm Password *'] as $setupField) {
        unifiedAdminAssert(str_contains($adminJavaScript, $setupField), "Admin setup/user forms are missing {$setupField}.");
    }
    unifiedAdminAssert(str_contains($compactAdminJavaScript, "action:'setup.createAdmin',name:")
        && str_contains($compactAdminJavaScript, "mobile:values.get('mobile')")
        && str_contains($compactAdminJavaScript, "email:values.get('email')||null"), 'Initial Super Admin profile is not submitted by the Admin Console.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'protectSoleSuperAdminAuthorization')
        && str_contains($compactAdminJavaScript, "preserved.value='system-administrator'")
        && str_contains($adminJavaScript, 'At least one enabled Super Admin must remain'), 'Sole Super Admin authorization controls are not protected.');
    unifiedAdminAssert(str_contains($adminJavaScript, '<span class="current-user">(you)</span>')
        && str_contains($adminJavaScript, 'You cannot change your own authorization'), 'Current-user identification or self-authorization UI protection is missing.');
    unifiedAdminAssert(
        preg_match('/async function healthView\s*\(/', $adminJavaScript) === 1
            && preg_match('/(^|[;{}]\s*)healthView\s*=/', $adminJavaScript) !== 1,
        'System Health view is not safely declared before Admin Console initialization.'
    );
    foreach (['setupView', 'loginView', 'entryView', 'infoView', 'configurationView', 'healthView', 'apiKeysView'] as $view) {
        unifiedAdminAssert(
            preg_match('/(?:async\s+)?function\s+' . preg_quote($view, '/') . '\s*\(/', $adminJavaScript) === 1,
            "Admin Console view {$view} is referenced without a function declaration."
        );
    }
    unifiedAdminAssert(!str_contains($adminHtml, '/admin/roles')
        && !str_contains($adminJavaScript, 'function rolesView'), 'Roles & Permissions UI was not removed.');
    unifiedAdminAssert(str_contains($compactAdminJavaScript, 'letusersView;') && str_contains($compactAdminJavaScript, 'usersView=asyncfunction()'), 'Users view binding is not declared.');
    unifiedAdminAssert(str_contains($adminHtml, 'id="user-dialog"')
        && str_contains($compactAdminJavaScript, "open('create'")
        && str_contains($compactAdminJavaScript, "mode==='edit'")
        && str_contains($compactAdminJavaScript, "mode==='password'"), 'User actions are not presented in modal dialogs.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'function userRolePreset')
        && str_contains($compactAdminJavaScript, "action:'auth.users.assignAuthorization',username:user.username,role:v.get('role')")
        && !str_contains($compactAdminJavaScript, "action:'auth.users.assignAuthorization',username:user.username,backendRole"), 'Backend Authorization modal does not use the unified role preset contract.');
    foreach (['>Super Admin</option>', '>Admin</option>', '>Data Operator</option>', '>Read Only</option>'] as $roleOption) {
        unifiedAdminAssert(str_contains($adminJavaScript, $roleOption), "Backend Authorization UI is missing {$roleOption}.");
    }
    unifiedAdminAssert(str_contains($compactAdminCss, '.user-dialog__surface { display: flex; flex-direction: column;')
        && str_contains($compactAdminCss, '.user-dialog__body { flex: 1 1 auto; min-height: 0; padding: 21px; overflow-y: auto;')
        && str_contains($compactAdminCss, '.user-dialog__form { display: flex; min-height: 0; flex: 1 1 auto; flex-direction: column; }')
        && str_contains($compactAdminCss, '.user-dialog__footer { flex: 0 0 auto;')
        && str_contains($adminJavaScript, 'class="dialog-actions user-dialog__footer"'), 'Backend dialogs do not keep their header/footer visible while the modal body scrolls.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'function openApiKeyDialog')
        && str_contains($adminJavaScript, 'data-api-key-form')
        && str_contains($adminJavaScript, 'openApiKeyDialog(users, roles, event.currentTarget)')
        && str_contains($adminJavaScript, 'classList.add("dialog-open")')
        && str_contains($adminCss, 'body.dialog-open')
        && !str_contains($adminJavaScript, 'id="key-editor"'), 'API key creation is not using the shared Admin dialog.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'Name is required.')
        && str_contains($adminJavaScript, 'data-dialog-error')
        && str_contains($adminJavaScript, 'Copy this API key now')
        && str_contains($adminJavaScript, 'It cannot be displayed again.'), 'API key modal validation or one-time reveal is missing.');
    unifiedAdminAssert(substr_count($adminJavaScript, 'document.body.classList.add("dialog-open")') >= 3
        && substr_count($adminJavaScript, 'document.body.classList.remove("dialog-open")') >= 3,
        'Admin dialogs do not consistently lock and restore background scrolling.');
    unifiedAdminAssert(str_contains($compactAdminCss, '.users-panel { width: 100%; max-width: 100%; overflow: hidden; }')
        && str_contains($compactAdminCss, '.table-wrap { width: 100%; min-width: 0; overflow-x: auto;')
        && str_contains($compactAdminCss, '.users-table-wrap { width: 100%; max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }')
        && str_contains($compactAdminCss, '.admin-users-scroll { --admin-users-visible-rows: 7;')
        && str_contains($compactAdminCss, '.users-table { width: 100%; min-width: 1040px; table-layout: fixed; }')
        && str_contains($adminCss, '.users-table th:nth-child(8)')
        && str_contains($compactAdminCss, '.users-table td { overflow-wrap: anywhere; word-break: break-word; }'), 'Backend Users table lacks controlled columns or safe long-text wrapping.');
    unifiedAdminAssert(str_contains($compactAdminCss, '.users-heading { display: flex; min-width: 0;')
        && str_contains($compactAdminCss, 'flex-wrap: wrap; gap: 15px; margin-bottom: 18px; } .users-heading .help { min-width: 0; flex: 1 1 auto; overflow-wrap: anywhere; }')
        && !str_contains($compactAdminCss, '.users-heading .help { min-width: 0; flex: 1 1 320px;')
        && str_contains($compactAdminCss, '.users-table .actions button { max-width: 100%; padding: 6px 8px; overflow-wrap: anywhere; white-space: normal; }'), 'Backend Users header or action controls do not wrap within the available content width.');
    unifiedAdminAssert(!str_contains($adminCss, '.users-table td::before')
        && !str_contains($compactAdminCss, '.users-table, .users-table tbody, .users-table tr, .users-table td { display: block;')
        && !str_contains($compactAdminCss, '.users-table .actions button { width: 100%; }'), 'Backend Users table still changes into the broken mobile label/value card layout.');
    foreach (['<th>Name</th>', '<th>Mobile Number</th>', '<th>Email</th>', '<th>Role</th>'] as $column) {
        unifiedAdminAssert(str_contains($adminJavaScript, $column), "Backend Users table is missing {$column}.");
    }
    unifiedAdminAssert(str_contains($adminJavaScript, 'SQL query timeout (seconds)'), 'Configured query timeout is missing from the Admin Console.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'admin.runtime.save'), 'Runtime configuration save is missing from the Admin Console.');
    foreach (['admin.backup.history', 'admin.backup.create', 'admin.backup.schedule', 'admin.backup.schedule.save', 'admin.backup.download', 'admin.backup.preview', 'admin.backup.restore'] as $backupAction) {
        unifiedAdminAssert(str_contains($adminJavaScript, $backupAction), "Admin Backup & Recovery UI is missing {$backupAction}.");
    }
    foreach (['Automatic Backups', 'Enable scheduled backups', '>Hourly<', '>Daily<', '>Weekly<', '"Manual"', '"Scheduled"', '"Legacy"'] as $scheduleUi) {
        unifiedAdminAssert(str_contains($adminJavaScript, $scheduleUi), "Admin Backup & Recovery UI is missing {$scheduleUi}.");
    }
    foreach (['Create Backup?', 'Backup Created', 'Disable Scheduled Backups?', 'Restore Preview',
        'Continue to Restore', 'Restore Configuration?', 'Restoring Configuration…', 'Restore Completed',
        'Restore Failed', 'Your session was invalidated'] as $dialogText) {
        unifiedAdminAssert(str_contains($adminJavaScript, $dialogText), "Backup workflow dialog is missing {$dialogText}.");
    }
    unifiedAdminAssert(str_contains($adminHtml, 'id="backup-dialog"')
        && str_contains($adminJavaScript, 'function backupDialogChoice(')
        && str_contains($adminJavaScript, 'function backupOperationDialog(')
        && str_contains($adminJavaScript, 'backupDialog.dataset.busy = "true"')
        && str_contains($adminJavaScript, 'event.key !== "Tab"')
        && str_contains($adminJavaScript, 'event.preventDefault()'),
        'Backup workflow dialog lacks reusable modal, duplicate-submit, focus-trap, or safe Escape handling.');
    foreach (['frontend.restore.confirm.opened', 'frontend.restore.confirmed', 'frontend.restore.request.started',
        'frontend.restore.request.failed', 'frontend.restore.completed'] as $event) {
        unifiedAdminAssert(str_contains($adminJavaScript, $event), "Admin restore flow is missing frontend operational event {$event}.");
    }
    unifiedAdminAssert(str_contains($adminHtml, 'data-route="backup-recovery"')
        && str_contains($adminHtml, 'Backup &amp; Recovery')
        && str_contains($adminJavaScript, 'type="file" accept=".zip,application/zip"')
        && str_contains($adminJavaScript, 'Restore Configuration')
        && str_contains($adminJavaScript, 'confirmed: true')
        && str_contains($adminJavaScript, 'URL.createObjectURL')
        && !str_contains($adminJavaScript, 'webkitdirectory'),
        'Admin Backup & Recovery does not use native ZIP selection/download with explicit confirmation.');
    foreach (['.backup-summary-grid', '.backup-schedule-grid', '.backup-table-wrap', '.recovery-point-id',
        '.recovery-points-scroll', '.backup-change-list', '.restore-file-row', 'dialog.backup-dialog'] as $backupStyle) {
        unifiedAdminAssert(str_contains($adminCss, $backupStyle), "Backup & Recovery CSS is missing {$backupStyle}.");
    }
    unifiedAdminAssert(str_contains($adminJavaScript, 'No Recovery Points')
        && str_contains($adminJavaScript, 'No verified application recovery points are currently available.')
        && str_contains($adminJavaScript, 'tabindex="0" aria-label="Recovery points"')
        && str_contains($adminCss, '--recovery-visible-rows: 5')
        && str_contains($adminCss, 'position: sticky'), 'Recovery points do not provide a bounded, accessible scrolling table or empty state.');
    unifiedAdminAssert(str_contains($adminCss, 'overscroll-behavior-y: auto')
        && !str_contains($compactAdminCss, '.recovery-points-scroll { --recovery-visible-rows: 5; --recovery-row-block-size: 3.75rem; max-height: calc(var(--recovery-row-block-size) * (var(--recovery-visible-rows) + 1)); overflow: auto; overscroll-behavior: contain;'),
        'Recovery Points prevents native page scroll handoff at its vertical boundaries.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'Forgot username or password?')
        && str_contains($adminJavaScript, 'Forgot Username or Password?')
        && str_contains($adminJavaScript, 'detail.message')
        && str_contains($adminJavaScript, 'retryAfterSeconds')
        && str_contains($adminJavaScript, 'LOGIN_RATE_LIMITED'), 'Admin login lacks safe attempt, lockout, or forgot-credentials feedback.');
    foreach (['No configuration changes detected.', '["added", "modified", "deleted"]', 'backupChangeList(changes)',
        'preview.configurationFiles', 'preview.filesChanging', 'preview.changes'] as $previewUi) {
        unifiedAdminAssert(str_contains($adminJavaScript, $previewUi), "Restore preview is missing {$previewUi} support.");
    }
    unifiedAdminAssert(strpos($adminJavaScript, 'doneValidating();') < strpos($adminJavaScript, 'if (!preview) return;'),
        'Restore preview validation state is not cleared before the preview dialog opens.');
    unifiedAdminAssert(!str_contains($adminJavaScript, '"encryption",\n      "backup",')
        && !str_contains((string)file_get_contents(__DIR__ . '/../app/Health/ApplicationHealthMonitor.php'), "'backup' => \$this->backupHealth()"),
        'Backup remains in the System Health response or UI aggregation.');
    unifiedAdminAssert(str_contains((string)file_get_contents(__DIR__ . '/../app/Health/ApplicationHealthMonitor.php'), 'public function restoreSafety(): array'),
        'Restore safety health validation was removed while separating Backup from System Health.');
    unifiedAdminAssert(!str_contains(strtolower($adminJavaScript), 'test saved configuration'), 'Removed saved database test remains in the Admin Console.');
    unifiedAdminAssert(!str_contains($adminJavaScript, 'Runtime access'), 'Database runtime lifecycle remains under Configuration.');
    unifiedAdminAssert(str_contains($adminJavaScript, 'data-database-runtime'), 'Database runtime lifecycle is missing from System Health.');
    unifiedAdminAssert(
        str_contains($adminJavaScript, 'Service Actions')
            && str_contains($adminJavaScript, 'data-admin-console-restart')
            && str_contains($adminJavaScript, 'admin.console.restart')
            && str_contains($adminJavaScript, 'Waiting for the service to become available.')
            && !str_contains($adminJavaScript, 'data-admin-console-stop')
            && !str_contains($adminJavaScript, 'data-admin-console-start'),
        'System Health does not expose the controlled restart-only Admin Console workflow.'
    );
    unifiedAdminAssert(
        substr_count($adminJavaScript, 'serviceControls("api", health.api)') === 1
            && substr_count($adminJavaScript, 'serviceControls("sqlParser", health.sqlParser)') === 1
            && substr_count($adminJavaScript, 'databaseControls(health.database)') === 1
            && str_contains($adminJavaScript, '<section class="service-actions"'),
        'Lifecycle controls were not moved into the separate Service Actions section.'
    );
    unifiedAdminAssert(str_contains($compactAdminJavaScript, "['Port',service.port]"), 'System Health does not display actual development managed-service ports.');
    unifiedAdminAssert(str_contains($compactAdminJavaScript, "['Server',health.database.server]") && str_contains($compactAdminJavaScript, "['Database',health.database.database]"), 'System Health omits safe database connection details.');
    unifiedAdminAssert(str_contains((string)file_get_contents(__DIR__ . '/../admin/api.php'), 'AdminAuthorizationMiddleware'), 'Independent Admin authorization boundary is missing.');
    foreach ([
        __DIR__ . '/../admin/api.php',
        __DIR__ . '/../app/Controllers/AdminController.php',
        __DIR__ . '/../app/Requests/AdminRequestValidator.php',
    ] as $adminActionSource) {
        unifiedAdminAssert(!str_contains((string)file_get_contents($adminActionSource), 'admin.database.testCurrent'), 'Saved database test remains exposed as an Admin API action.');
    }
    unifiedAdminAssert(str_contains((string)file_get_contents(__DIR__ . '/../admin/router.php'), "['127.0.0.1', '::1']"), 'Admin router lacks loopback enforcement.');
    foreach ([
        'setup-database-encryption.bat',
        'setup-database-encryption.sh',
        'scripts/generate-encryption-key.php',
        'scripts/setup-database-encryption.php',
        'scripts/check-database-encryption-key.php',
        'sqlparser/start-windows.bat',
        'sqlparser/start-linux.sh',
    ] as $obsoletePath) {
        unifiedAdminAssert(!file_exists(__DIR__ . '/../' . $obsoletePath), "Obsolete manual workflow remains: {$obsoletePath}");
    }
    unifiedAdminAssert(is_file(__DIR__ . '/../scripts/prepare-local-encryption-key.php'), 'Internal automatic key preparation was removed.');

    echo "Unified admin console tests passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    foreach (glob($sessionPath . '/*') ?: [] as $file) @unlink($file);
    foreach (glob($temporaryDirectory . '/*') ?: [] as $file) {
        if (is_dir($file)) @rmdir($file); else @unlink($file);
    }
    @rmdir($temporaryDirectory);
    $restore = static function (string $name, $value): void {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    };
    $restore('GENERIC_ADMIN_CONFIG_PATH', $oldAdminPath);
    $restore(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, $oldEncryptionKey);
    $restore(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE, $oldApiKey);
    $restore('GENERIC_ADMIN_ENABLED', $oldAdminEnabled);
    $restore('GENERIC_API_ALLOWED_ORIGINS', $oldOriginOverride);
}
