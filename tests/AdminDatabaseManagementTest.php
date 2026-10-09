<?php

require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Services/DatabaseAdministrationService.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Runtime/ApplicationRuntimeManager.php';
require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 Phase 10: Admin management of server profiles and databases, and
 * server/database health. Connections are recorded by injected testers; no
 * SQL Server is involved.
 */

function adminDbAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function adminDbFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

/** [status, code, encoded payload] of the response an exception produces. */
function adminDbResponse(Throwable $exception): array
{
    [$status, $payload] = ExceptionHandler::responseFor($exception);
    return [$status, $payload['error']['code'] ?? null, json_encode($payload)];
}

function adminDbRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

/**
 * Render the Databases page's row actions with the shipped admin.js helpers in
 * Node: [servers|databases => [id => [action => disabled]]].
 */
function adminDbRenderedActions(array $servers, array $databases): array
{
    $source = (string)file_get_contents(dirname(__DIR__) . '/admin/assets/admin.js');
    $slice = static function (string $start, string $end) use ($source): string {
        $from = strpos($source, $start);
        $to = $from === false ? false : strpos($source, $end, $from);
        adminDbAssert($from !== false && $to !== false, "admin.js no longer contains {$start}.");
        return substr($source, $from, $to - $from);
    };
    $program = $slice('const escapeHtml =', 'const row =') . $slice('function serverActionButtons(', '  // Server profiles and database contexts')
        . 'const input = JSON.parse(process.argv[1]);'
        . 'process.stdout.write(JSON.stringify({servers: Object.fromEntries(input.servers.map((s) => [s.id, serverActionButtons(s)])),'
        . ' databases: Object.fromEntries(input.databases.map((d) => [d.id, databaseActionButtons(d)]))}));';
    $process = proc_open(['node', '-e', $program, json_encode(['servers' => $servers, 'databases' => $databases])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    adminDbAssert(is_resource($process), 'Node.js is required to render the Databases page.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    adminDbAssert(proc_close($process) === 0, "Rendering the Databases page failed: {$error}");
    $rendered = [];
    foreach (json_decode((string)$output, true, 512, JSON_THROW_ON_ERROR) as $kind => $rows) {
        foreach ($rows as $id => $html) {
            preg_match_all('/<button[^>]*data-(?:server|database)-action="([a-z]+)"[^>]*>/', $html, $buttons, PREG_SET_ORDER);
            foreach ($buttons as [$tag, $action]) $rendered[$kind][$id][$action] = str_contains($tag, ' disabled');
        }
    }
    return $rendered;
}

/** Counts process-manager use: production availability must never touch it. */
final class AdminDbProcessProbe extends ApiProcessManager
{
    public int $operations = 0;
    public function __construct() {}
    public function status(): array { $this->operations++; return ['running' => true]; }
    public function start(): array { $this->operations++; return []; }
    public function stop(): array { $this->operations++; return []; }
    public function restart(): array { $this->operations++; return []; }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-admin-databases-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR', 'GENERIC_APP_ENV'] as $name) {
    $environment[$name] = getenv($name);
}

try {
    foreach (['config', 'database/config', 'logs', 'operational', 'runtime/health'] as $path) mkdir($directory . '/' . $path, 0700, true);
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    putenv('GENERIC_APP_ENV=development');
    RuntimeConfiguration::ensure();

    $legacyPath = $directory . '/database/config/database.json';
    $registry = DatabaseRegistry::forLegacyPath($legacyPath);
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    $logger = new Logger($directory . '/logs');

    // Recorded connections; failures are chosen per catalog or per server profile.
    $tested = [];
    $failingCatalogs = [];
    $tester = static function (array $configuration) use (&$tested, &$failingCatalogs): void {
        $tested[] = $configuration['database'];
        $failure = $failingCatalogs[$configuration['database']] ?? null;
        if ($failure !== null) throw DatabaseConnectionException::fromDriverError($failure);
    };
    $inspected = [];
    $failingServers = [];
    $inspector = static function (DatabaseContext $server) use (&$inspected, &$failingServers): array {
        $inspected[] = [$server->serverProfileId(), $server->physicalName()];
        $failure = $failingServers[$server->serverProfileId()] ?? null;
        if ($failure !== null) throw DatabaseConnectionException::fromDriverError($failure);
        return ['productVersion' => '16.0.4135.4', 'edition' => 'Developer Edition (64-bit)', 'serverName' => 'SQL01', 'password' => 'never'];
    };
    $healthOptions = ['root' => dirname(__DIR__), 'configurationDirectory' => $directory . '/config', 'databasePath' => $legacyPath,
        'registry' => $registry, 'runtimeDirectory' => $directory . '/runtime', 'logDirectory' => $directory . '/logs',
        'databaseCachePath' => $directory . '/runtime/health/database-health.json', 'diskSpace' => static fn (): int => PHP_INT_MAX,
        'production' => false, 'databaseAvailable' => fn (?string $id = null): bool => $availability->available($id)];
    $monitor = new ApplicationHealthMonitor($healthOptions + ['databaseTester' => $tester, 'serverInspector' => $inspector]);
    $service = new AdminService(new AdminConfigurationRepository(), $legacyPath, $tester, null, null, null, null,
        $availability, $logger, $monitor, null, null, $inspector);
    $validator = new AdminRequestValidator();
    $responses = [];
    // Validate and dispatch an Admin request like admin/api.php does.
    $admin = function (array $request) use ($validator, $service, &$responses): array {
        $result = $service->databaseAdministration()->dispatch($validator->validate($request));
        $responses[] = json_encode($result);
        return $result;
    };
    $refused = function (array $request, int $status, string $code) use ($admin, &$responses): void {
        [$actualStatus, $actualCode, $payload] = adminDbResponse(adminDbFailure(fn () => $admin($request), 'Admin request was accepted: ' . json_encode($request)));
        adminDbAssert($actualStatus === $status && $actualCode === $code, "Expected {$status} {$code} for " . json_encode($request) . "; got {$actualStatus} {$actualCode}.");
        $responses[] = $payload;
    };
    $connection = fn (string $host, string $user, string $password, array $extra = []): array => array_replace([
        'driver' => 'ODBC Driver 18 for SQL Server', 'server' => $host, 'port' => '14330', 'authentication' => 'sql',
        'username' => $user, 'password' => $password, 'encrypt' => true, 'trustServerCertificate' => false], $extra);

    // 5. Create server profiles; 12. create databases (the first becomes the default).
    $created = $admin(['action' => 'admin.servers.save', 'server' => ['id' => 'sql01', 'name' => 'SQL Server 01', 'enabled' => true]
        + $connection('sql01.admin.test', 'admin_user', 'admin-secret', ['loginTimeoutSeconds' => 9])]);
    adminDbAssert($created['server']['id'] === 'sql01' && $created['server']['connection']['passwordConfigured'] === true
        && $created['server']['connection']['loginTimeoutSeconds'] === 9 && !array_key_exists('password', $created['server']['connection']),
        'A server profile was not created safely.');
    $admin(['action' => 'admin.servers.save', 'server' => ['id' => 'sql02', 'name' => 'SQL Server 02', 'enabled' => true] + $connection('10.7.7.7', 'legacy_user', 'legacy-secret')]);
    $admin(['action' => 'admin.servers.save', 'server' => ['id' => 'sql03', 'name' => 'Spare', 'enabled' => true] + $connection('sql03.admin.test', 'spare_user', 'spare-secret')]);
    $refused(['action' => 'admin.databases.save', 'database' => ['id' => 'company', 'name' => 'Company', 'server' => 'sql01', 'enabled' => false, 'catalog' => 'CompanyDB']],
        409, 'DEFAULT_DATABASE_REQUIRED');
    foreach ([['company', 'Company', 'sql01', 'CompanyDB'], ['inventory', 'Inventory', 'sql01', 'InventoryDB'], ['archive', 'Archive', 'sql01', 'ArchiveDB'],
        ['legacy', 'Legacy', 'sql02', 'Legacy-Archive']] as [$id, $name, $server, $catalog]) {
        $saved = $admin(['action' => 'admin.databases.save', 'database' => ['id' => $id, 'name' => $name, 'server' => $server, 'enabled' => true, 'catalog' => $catalog]]);
        adminDbAssert($saved['database']['id'] === $id && $saved['database']['catalog'] === $catalog && $saved['database']['server'] === $server, "Database {$id} was not created.");
    }
    $refused(['action' => 'admin.databases.save', 'database' => ['id' => 'ghost', 'name' => 'Ghost', 'server' => 'missing', 'enabled' => true, 'catalog' => 'GhostDB']],
        404, 'SERVER_PROFILE_NOT_FOUND');
    foreach ([['id' => 'Bad Id'], ['catalog' => 'Company;DB'], ['server' => 'SQL01'], ['enabled' => 'yes'], ['connectionString' => 'Server=x']] as $change) {
        $refused(['action' => 'admin.databases.save', 'database' => array_replace(['id' => 'x', 'name' => 'X', 'server' => 'sql01', 'enabled' => true, 'catalog' => 'XDB'], $change)],
            400, 'INVALID_ADMIN_REQUEST');
    }

    // 1-4. Listing: profiles, databases, membership, counts.
    $servers = $admin(['action' => 'admin.servers.list']);
    $byServer = array_column($servers['servers'], null, 'id');
    adminDbAssert(array_keys($byServer) === ['sql01', 'sql02', 'sql03'] && $byServer['sql01']['databaseCount'] === 3 && $byServer['sql02']['databaseCount'] === 1
        && $byServer['sql03']['databaseCount'] === 0 && $byServer['sql01']['databases'] === ['company', 'inventory', 'archive']
        && $byServer['sql01']['connection']['server'] === 'sql01.admin.test' && in_array('auto', $servers['availableDrivers'], true),
        'Server profiles were not listed with their databases.');
    $databases = $admin(['action' => 'admin.databases.list']);
    $byDatabase = array_column($databases['databases'], null, 'id');
    adminDbAssert($databases['defaultDatabase'] === 'company' && $byDatabase['company']['default'] && $byDatabase['legacy']['server'] === 'sql02'
        && $byDatabase['inventory']['serverName'] === 'SQL Server 01' && $byDatabase['inventory']['usable'] && !$byDatabase['inventory']['available'],
        'Databases were not listed with their server, default, and states.');

    // Row actions: the default database renders no Set Default, Disable, or Delete; others keep theirs.
    $rendered = adminDbRenderedActions($servers['servers'], $databases['databases']);
    adminDbAssert($rendered['databases']['company'] === ['edit' => false, 'test' => false, 'connect' => false],
        'The default database rendered more than Edit, Test Connection, and Connect: ' . json_encode($rendered['databases']['company']));
    adminDbAssert($rendered['databases']['inventory'] === ['edit' => false, 'test' => false, 'connect' => false, 'default' => false, 'disable' => false, 'delete' => false],
        'Another database lost an applicable action: ' . json_encode($rendered['databases']['inventory']));
    adminDbAssert($rendered['servers']['sql01'] === ['edit' => false, 'test' => false, 'disable' => false, 'delete' => true]
        && $rendered['servers']['sql03'] === ['edit' => false, 'test' => false, 'disable' => false, 'delete' => false],
        'Server profile actions changed: ' . json_encode($rendered['servers']));

    // 6. Update a profile: a blank password keeps the stored one.
    $admin(['action' => 'admin.servers.save', 'server' => ['id' => 'sql01', 'name' => 'Primary SQL', 'enabled' => true]
        + $connection('sql01.admin.test', 'admin_user', '', ['port' => '1433'])]);
    $stored = $registry->serverConnection('sql01');
    adminDbAssert($stored['password'] === 'admin-secret' && $stored['port'] === '1433' && $stored['options']['loginTimeoutSeconds'] === 9
        && $registry->metadata()['servers']['sql01']['name'] === 'Primary SQL', 'Updating a profile lost its password or timeout.');
    $refused(['action' => 'admin.servers.save', 'server' => ['id' => 'sql04', 'name' => 'New', 'enabled' => true] + $connection('h', 'u', '')], 400, 'INVALID_ADMIN_REQUEST');

    // 9. Server test: the profile's own connection to master, independent of any database.
    $inspected = [];
    $serverTest = $admin(['action' => 'admin.servers.test', 'id' => 'sql03']);
    adminDbAssert($serverTest['connected'] && $serverTest['edition'] === 'Developer Edition (64-bit)' && $serverTest['serverName'] === 'SQL01'
        && $serverTest['crossDatabaseQueries'] && !array_key_exists('password', $serverTest) && $inspected === [['sql03', 'master']],
        'A server profile test did not check the profile itself.');
    $failingServers['sql03'] = 'HYT00 [Microsoft]Login timeout expired; Server=sql03.admin.test;PWD=spare-secret';
    $refused(['action' => 'admin.servers.test', 'id' => 'sql03'], 504, 'DATABASE_CONNECTION_TIMEOUT');
    $failingServers['sql03'] = "28000 [Microsoft][SQL Server]Login failed for user 'spare_user'.";
    $refused(['action' => 'admin.servers.test', 'id' => 'sql03'], 422, 'DATABASE_CONNECTION_FAILED');
    unset($failingServers['sql03']);
    $refused(['action' => 'admin.servers.test', 'id' => 'nope'], 404, 'SERVER_PROFILE_NOT_FOUND');

    // 7, 8, 10, 11. Enable/disable and delete profiles; referenced profiles are kept.
    $admin(['action' => 'admin.servers.disable', 'id' => 'sql03']);
    adminDbAssert($registry->metadata()['servers']['sql03']['enabled'] === false && $registry->serverConnection('sql03')['password'] === 'spare-secret',
        'Disabling a profile changed its credentials.');
    $admin(['action' => 'admin.servers.enable', 'id' => 'sql03']);
    $refused(['action' => 'admin.servers.disable', 'id' => 'sql01'], 409, 'DEFAULT_DATABASE_REQUIRED');
    $refused(['action' => 'admin.servers.delete', 'id' => 'sql02'], 409, 'SERVER_PROFILE_IN_USE');
    $deleted = $admin(['action' => 'admin.servers.delete', 'id' => 'sql03']);
    adminDbAssert($deleted['deleted'] && !isset($registry->metadata()['servers']['sql03']), 'An unused profile was not deleted.');

    // 13-19. Database updates, enable/disable, default rules, delete.
    $admin(['action' => 'admin.databases.save', 'database' => ['id' => 'inventory', 'name' => 'Stock', 'server' => 'sql01', 'enabled' => true, 'catalog' => 'StockDB']]);
    adminDbAssert($registry->databaseCatalog('inventory') === 'StockDB' && $registry->metadata()['databases']['inventory']['name'] === 'Stock', 'A database was not updated.');
    $admin(['action' => 'admin.databases.disable', 'id' => 'archive']);
    adminDbAssert($registry->metadata()['databases']['archive']['enabled'] === false && $registry->databaseCatalog('archive') === 'ArchiveDB', 'A database was not disabled.');
    $refused(['action' => 'admin.databases.default', 'id' => 'archive'], 409, 'DEFAULT_DATABASE_REQUIRED');
    $refused(['action' => 'admin.databases.default', 'id' => 'missing'], 404, 'DATABASE_NOT_FOUND');
    $refused(['action' => 'admin.databases.disable', 'id' => 'company'], 409, 'DEFAULT_DATABASE_REQUIRED');
    $refused(['action' => 'admin.databases.delete', 'id' => 'company'], 409, 'DEFAULT_DATABASE_REQUIRED');
    $refused(['action' => 'admin.databases.save', 'database' => ['id' => 'company', 'name' => 'Company', 'server' => 'sql01', 'enabled' => false, 'catalog' => 'CompanyDB']],
        409, 'DEFAULT_DATABASE_REQUIRED');
    $admin(['action' => 'admin.databases.enable', 'id' => 'archive']);
    $admin(['action' => 'admin.databases.default', 'id' => 'inventory']);
    adminDbAssert($registry->defaultDatabaseId() === 'inventory', 'The default database was not changed.');
    // The new default is protected in the API and, after a refresh, in the rendered rows; the old one is not.
    $before = $registry->storedDocument();
    $refused(['action' => 'admin.databases.disable', 'id' => 'inventory'], 409, 'DEFAULT_DATABASE_REQUIRED');
    $refused(['action' => 'admin.databases.delete', 'id' => 'inventory'], 409, 'DEFAULT_DATABASE_REQUIRED');
    adminDbAssert($registry->storedDocument() === $before, 'A rejected default-database change altered the registry.');
    $rendered = adminDbRenderedActions($admin(['action' => 'admin.servers.list'])['servers'], $admin(['action' => 'admin.databases.list'])['databases']);
    adminDbAssert($rendered['databases']['inventory'] === ['edit' => false, 'test' => false, 'connect' => false]
        && $rendered['databases']['company'] === ['edit' => false, 'test' => false, 'connect' => false, 'default' => false, 'disable' => false, 'delete' => false],
        'Changing the default database did not move the hidden actions: ' . json_encode($rendered['databases']));
    $admin(['action' => 'admin.databases.default', 'id' => 'company']);
    $availability->setAvailable(true, 'archive');
    $admin(['action' => 'admin.databases.delete', 'id' => 'archive']);
    adminDbAssert(!isset($registry->metadata()['databases']['archive']) && !$availability->available('archive'), 'A database was not deleted with its availability state.');
    $refused(['action' => 'admin.databases.delete', 'id' => 'archive'], 404, 'DATABASE_NOT_FOUND');
    $admin(['action' => 'admin.databases.save', 'database' => ['id' => 'archive', 'name' => 'Archive', 'server' => 'sql01', 'enabled' => false, 'catalog' => 'ArchiveDB']]);
    $registry->verify();

    // 20-25. Connection tests never change the registry.
    $tested = [];
    adminDbAssert($admin(['action' => 'admin.databases.test', 'id' => 'inventory'])['connected'] && $tested === ['StockDB'], 'A database test did not use its own catalog.');
    $refused(['action' => 'admin.databases.test', 'id' => 'missing'], 404, 'DATABASE_NOT_FOUND');
    $refused(['action' => 'admin.databases.test', 'id' => 'archive'], 403, 'DATABASE_DISABLED');
    $before = $registry->storedDocument();
    $failingCatalogs['StockDB'] = 'HYT00 [Microsoft]Login timeout expired; PWD=admin-secret';
    $refused(['action' => 'admin.databases.test', 'id' => 'inventory'], 504, 'DATABASE_CONNECTION_TIMEOUT');
    $failingCatalogs['StockDB'] = '08001 [Microsoft]TCP Provider: No such host is known (sql01.admin.test).';
    $refused(['action' => 'admin.databases.test', 'id' => 'inventory'], 422, 'DATABASE_CONNECTION_FAILED');
    $refused(['action' => 'admin.databases.connect', 'id' => 'inventory'], 422, 'DATABASE_CONNECTION_FAILED');
    adminDbAssert($registry->storedDocument() === $before && $registry->defaultDatabaseId() === 'company'
        && $registry->metadata()['databases']['inventory']['enabled'] && !$availability->available('inventory'),
        'A connection failure changed the registry, the default, or opened the gate.');
    unset($failingCatalogs['StockDB']);
    $connected = $admin(['action' => 'admin.databases.connect', 'id' => 'inventory']);
    adminDbAssert($connected['connected'] && $availability->available('inventory'), 'A database was not connected.');
    $admin(['action' => 'admin.databases.connect', 'id' => 'company']);
    $admin(['action' => 'admin.databases.connect', 'id' => 'legacy']);

    // 23, 26, 27, 31. Health: servers apart from their databases.
    $tested = $inspected = [];
    $failingCatalogs['Legacy-Archive'] = '08001 [Microsoft]Cannot open database "Legacy-Archive" requested by the login.';
    $tree = $admin(['action' => 'admin.databases.health']);
    $treeServers = array_column($tree['servers'], null, 'id');
    $statuses = fn (array $server): array => array_column($server['databases'], 'status', 'id');
    adminDbAssert($treeServers['sql01']['status'] === 'healthy' && $statuses($treeServers['sql01']) === ['company' => 'healthy', 'inventory' => 'healthy', 'archive' => 'disabled']
        && $treeServers['sql02']['status'] === 'healthy' && $statuses($treeServers['sql02']) === ['legacy' => 'unhealthy']
        && $treeServers['sql01']['serverInfo']['edition'] === 'Developer Edition (64-bit)' && !isset($treeServers['sql01']['serverInfo']['password']),
        'Server health was not reported apart from database health: ' . json_encode($tree));
    adminDbAssert(!in_array('ArchiveDB', $tested, true), 'Health contacted a disabled database.');
    $failingServers['sql02'] = '08001 [Microsoft]TCP Provider: No such host is known.';
    $monitor->forgetServerHealth('sql02');
    adminDbAssert($admin(['action' => 'admin.databases.health'])['servers'][1]['status'] === 'unhealthy', 'A server connection failure was not reported on the server.');
    unset($failingServers['sql02'], $failingCatalogs['Legacy-Archive']);
    $monitor->forgetServerHealth('sql02');
    $monitor->forgetDatabaseHealth('legacy');
    $admin(['action' => 'admin.servers.save', 'server' => ['id' => 'sql02', 'name' => 'SQL Server 02', 'enabled' => false] + $connection('10.7.7.7', 'legacy_user', '')]);
    $legacy = $monitor->databaseStatus('legacy');
    adminDbAssert($legacy['configured'] && !$legacy['enabled'] && $legacy['status'] === 'disabled' && $legacy['category'] === 'server_profile_disabled'
        && $monitor->serverStatus('sql02')['status'] === 'disabled' && $byDatabase['legacy']['usable'], 'A disabled profile was not reflected by its databases.');
    $refused(['action' => 'admin.databases.test', 'id' => 'legacy'], 403, 'SERVER_PROFILE_DISABLED');
    $admin(['action' => 'admin.servers.enable', 'id' => 'sql02']);
    $inventoryStatus = $monitor->databaseStatus('inventory');
    adminDbAssert($inventoryStatus['configured'] && $inventoryStatus['enabled'] && $inventoryStatus['available'] && $inventoryStatus['status'] === 'healthy',
        'Configured, enabled, available, and reachable were not reported.');

    // 32, 33. The health cache saves connections but never overrides state.
    $tested = [];
    $monitor->forgetDatabaseHealth('inventory');
    $first = $monitor->databaseStatus('inventory');
    $second = $monitor->databaseStatus('inventory');
    adminDbAssert($first['cached'] === false && $second['cached'] === true && count($tested) === 1, 'The health cache was not used.');
    $admin(['action' => 'admin.databases.disconnect', 'id' => 'inventory']);
    adminDbAssert($monitor->databaseStatus('inventory')['status'] === 'disconnected', 'The health cache hid a disconnected database.');
    $admin(['action' => 'admin.databases.connect', 'id' => 'inventory']);
    $monitor->databaseStatus('inventory');
    $admin(['action' => 'admin.databases.disable', 'id' => 'inventory']);
    adminDbAssert($monitor->databaseStatus('inventory')['status'] === 'disabled', 'The health cache hid a disabled database.');
    $refused(['action' => 'admin.databases.test', 'id' => 'inventory'], 403, 'DATABASE_DISABLED');
    $admin(['action' => 'admin.databases.enable', 'id' => 'inventory']);

    // 28-30. Liveness never needs a database; readiness follows the default and connects to nothing.
    $tested = $inspected = [];
    $failingCatalogs['CompanyDB'] = '08001 [Microsoft]TCP Provider: No such host is known.';
    $availability->setAvailable(false, 'company');
    $live = $monitor->liveness('api', null, null);
    $notReady = $monitor->readiness();
    $availability->setAvailable(true, 'company');
    $ready = $monitor->readiness();
    adminDbAssert($live['status'] === 'healthy' && array_keys($live) === ['status', 'service', 'version', 'port', 'startedAt', 'uptimeSeconds']
        && $notReady['status'] === 'unhealthy' && $notReady['checks']['database']['category'] === 'database_disconnected'
        && $ready['status'] === 'healthy' && $tested === [] && $inspected === [], 'Liveness or readiness depended on, or connected to, databases.');
    unset($failingCatalogs['CompanyDB']);

    // 39. The admin.database.* actions still manage the default database.
    $public = $service->databaseConfiguration();
    adminDbAssert($public['database'] === 'CompanyDB' && $public['server'] === 'sql01.admin.test' && !array_key_exists('password', $public)
        && $service->testCurrentDatabase()['connected'] && $service->controlDatabase('disconnect')['state'] === 'disabled'
        && $service->controlDatabase('connect')['connected'], 'The admin.database.* actions changed.');

    // 40, 41. Production API/SQL Parser start/stop are application flags, never process control.
    putenv('GENERIC_APP_ENV=production');
    $probe = new AdminDbProcessProbe();
    $production = new AdminService(new AdminConfigurationRepository(), $legacyPath, $tester, $probe, null, null, null, $availability, $logger, $monitor,
        new ApplicationRuntimeManager(), null, $inspector);
    $stopped = $production->controlApi('stop');
    $started = $production->controlApi('start');
    putenv('GENERIC_APP_ENV=development');
    adminDbAssert($stopped['applicationRuntime']['enabled'] === false && $started['applicationRuntime']['enabled'] === true && $probe->operations === 0, 'Production availability used process control.');
    $serviceSource = (string)file_get_contents(dirname(__DIR__) . '/app/Services/DatabaseAdministrationService.php')
        . (string)file_get_contents(dirname(__DIR__) . '/app/Health/ApplicationHealthMonitor.php');
    adminDbAssert(!preg_match('/proc_open|shell_exec|passthru|popen|\bexec\(|\bsystem\(|net\s+(?:start|stop)|sc\s+(?:start|stop)|iisreset|appcmd|MSSQLSERVER/i', $serviceSource),
        'Database administration can control processes or services.');

    // 42, 43. IIS base path and no production ports.
    $adminScript = (string)file_get_contents(dirname(__DIR__) . '/admin/assets/admin.js');
    $adminPage = (string)file_get_contents(dirname(__DIR__) . '/admin/index.php');
    adminDbAssert(str_contains($adminPage, "\$adminUrl('databases')") && str_contains($adminScript, '"databases"')
        && !preg_match('#:(?:8000|8090|8100)\b|localhost:|127\.0\.0\.1:#', $adminScript) && !preg_match('#["\']/(?:admin|api|sqlparser)/#', $adminScript),
        'The Admin Console hard-codes ports or public paths.');
    // Both registry tables use their own content-sized layout, not the Users table's fixed widths.
    $adminCss = (string)file_get_contents(dirname(__DIR__) . '/admin/assets/admin.css');
    $cssRules = function (string $selector) use ($adminCss): string {
        adminDbAssert(preg_match_all('/(?:^|\n)' . preg_quote($selector, '/') . ' \{([^}]*)\}/', $adminCss, $matches) > 0, "admin.css lacks {$selector}.");
        return implode("\n", $matches[1]);
    };
    adminDbAssert(substr_count($adminScript, '<table class="registry-table">') === 2 && substr_count($adminScript, '<table class="users-table">') === 1,
        'The Databases page tables still use the Users table layout.');
    adminDbAssert(str_contains($cssRules('.actions'), 'flex-wrap: wrap') && str_contains($cssRules('.registry-table .actions button'), 'white-space: nowrap')
        && str_contains($cssRules('.registry-table td'), 'overflow-wrap: anywhere') && str_contains($cssRules('.table-wrap'), 'overflow-x: auto')
        && !str_contains($cssRules('.registry-table'), 'table-layout: fixed') && !preg_match('/\.registry-table[^{]*nth-child/', $adminCss)
        && str_contains($cssRules('.users-table'), 'table-layout: fixed') && str_contains($cssRules('.users-table'), 'min-width: 1040px'),
        'The Databases page tables are not compact and responsive, or the Users table changed.');

    // 44. Backups carry the registry: profiles, databases, default, flags, and encrypted configuration.
    $backupSource = (string)file_get_contents(dirname(__DIR__) . '/app/Backup/ApplicationBackupManager.php');
    adminDbAssert(ApplicationBackupManager::FORMAT_VERSION === 4 && str_contains($backupSource, 'databases.json'), 'Backups do not include the V3 registry.');
    $document = $registry->storedDocument();
    adminDbAssert(array_keys($document['servers']) === ['sql01', 'sql02'] && $document['defaultDatabase'] === 'company'
        && DatabaseCredentialEncryption::isBoundEnvelope($document['servers']['sql01']['connection']), 'The registry document is not what backups restore.');

    // 34-38. No secrets in Admin responses, errors, or logs.
    $stored = json_decode((string)file_get_contents($registry->registryPath()), true);
    $envelopes = [];
    array_walk_recursive($stored, function ($value) use (&$envelopes): void {
        if (is_string($value) && strlen($value) >= 16) $envelopes[] = $value;
    });
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('#/(logs|operational)/#', $file->getPathname())) $logText .= (string)file_get_contents($file->getPathname());
    }
    $responseText = implode("\n", $responses);
    adminDbAssert(count($envelopes) >= 4, 'The registry fixture holds no envelopes.');
    foreach (['admin-secret', 'legacy-secret', 'spare-secret', '"password"', 'never', $key, 'Driver={', 'PWD=', 'ciphertext', ...$envelopes] as $secret) {
        adminDbAssert(!str_contains($responseText, $secret), 'An Admin response exposed ' . substr($secret, 0, 20) . '.');
        adminDbAssert(!str_contains($logText, $secret), 'A log exposed ' . substr($secret, 0, 20) . '.');
    }

    echo "Admin database management tests passed.\n";
} finally {
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    adminDbRemoveDirectory($directory);
}
