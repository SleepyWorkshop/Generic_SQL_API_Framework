<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../core/JsonFileStore.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * V3 release verification: a fresh installation and the V2 → V3 upgrade path,
 * entirely in temporary directories. Nothing connects to SQL Server; connection
 * tests are recorded.
 */

function upgradeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function upgradeFailure(callable $operation): ?Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    return null;
}

function upgradeRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    @chmod($directory, 0700);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) { @chmod($item->getPathname(), 0700); @rmdir($item->getPathname()); } else { @unlink($item->getPathname()); }
    }
    @rmdir($directory);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-upgrade-' . bin2hex(random_bytes(8));
$environment = [];
foreach ([DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE, 'GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_LOG_DIR', 'GENERIC_ADMIN_CONFIG_PATH'] as $name) {
    $environment[$name] = getenv($name);
}
$v2 = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql.upgrade.test', 'port' => '1433', 'database' => 'ReportingDB',
    'authentication' => 'sql', 'username' => 'upgrade_user', 'password' => 'upgrade-secret', 'options' => ['encrypt' => true, 'trustServerCertificate' => false]];
$secrets = ['upgrade-secret', 'upgrade_user', 'sql.upgrade.test', 'ReportingDB'];

try {
    mkdir($directory . '/logs', 0700, true);
    mkdir($directory . '/operational', 0700, true);
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    $key = base64_encode(random_bytes(32));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    $responses = [];
    $respond = function (?Throwable $failure) use (&$responses): array {
        if ($failure === null) return [null, null];
        [$status, $payload] = ExceptionHandler::responseFor($failure);
        $responses[] = json_encode($payload);
        return [$status, $payload['error']['code'] ?? null];
    };
    // An isolated installation: runtime configuration, database configuration, and runtime state.
    $install = function (string $name) use ($directory): array {
        $root = $directory . '/' . $name;
        foreach (['config', 'database/config', 'runtime/health'] as $path) mkdir($root . '/' . $path, 0700, true);
        putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $root . '/config');
        putenv('GENERIC_ADMIN_CONFIG_PATH=' . $root . '/config/admin.json');
        RuntimeConfiguration::ensure();
        return [$root, $root . '/database/config/database.json'];
    };

    // 1. Fresh install: configuration bootstraps, nothing is configured, and requests fail clearly.
    [$fresh, $freshLegacy] = $install('fresh');
    foreach (['admin.json', 'auth.json', 'installation.json', 'authorization.json', 'database-state.json', 'application-runtime-state.json'] as $file) {
        upgradeAssert(is_file($fresh . '/config/' . $file), "A fresh installation lacks config/{$file}.");
    }
    $registry = DatabaseRegistry::forLegacyPath($freshLegacy);
    $availability = new DatabaseAvailabilityManager($fresh . '/config/database-state.json', $registry);
    upgradeAssert($registry->source() === DatabaseRegistry::SOURCE_NONE && $registry->defaultDatabaseId() === null && !$registry->requiresEncryptionKey()
        && JsonFileStore::load($fresh . '/config/database-state.json') === ['version' => 2, 'databases' => []] && !is_file($freshLegacy),
        'A fresh installation is not empty V3 state without a V2 database.json.');
    $resolver = new DatabaseContextResolver($registry, $availability);
    [$status, $code] = $respond(upgradeFailure(fn () => (new DatabaseQueryPlanner($resolver))->plan(['action' => 'select', 'source' => ['table' => 'T'], 'fields' => ['Id']])));
    upgradeAssert($status === 503 && $code === 'DATABASE_UNAVAILABLE', 'An unconfigured installation did not answer DATABASE_UNAVAILABLE.');
    [$status, $code] = $respond(upgradeFailure(fn () => $resolver->resolve()));
    upgradeAssert($status === 503 && $code === 'DATABASE_CONFIGURATION_ERROR', 'An unconfigured installation did not report missing configuration.');
    $healthOptions = fn (string $root, $registry) => ['root' => dirname(__DIR__), 'configurationDirectory' => $root . '/config', 'databasePath' => $root . '/database/config/database.json',
        'registry' => $registry, 'runtimeDirectory' => $root . '/runtime', 'logDirectory' => $directory . '/logs',
        'databaseCachePath' => $root . '/runtime/health/database-health.json', 'diskSpace' => static fn (): int => PHP_INT_MAX, 'production' => false];
    upgradeAssert((new ApplicationHealthMonitor($healthOptions($fresh, $registry)))->readiness()['checks']['database']['status'] === 'unhealthy',
        'An unconfigured installation was ready.');

    // First configuration through the Admin service; the first database becomes the default.
    $tested = [];
    $sqlServerDown = true;
    $tester = static function (array $configuration) use (&$tested, &$sqlServerDown): void {
        $tested[] = $configuration['database'];
        if ($sqlServerDown) throw DatabaseConnectionException::fromDriverError('08001 [Microsoft][ODBC Driver 18]TCP Provider: No such host is known (sql.upgrade.test); PWD=upgrade-secret');
    };
    $monitor = new ApplicationHealthMonitor($healthOptions($fresh, $registry) + ['databaseTester' => $tester]);
    $admin = new AdminService(new AdminConfigurationRepository($fresh . '/config/admin.json'), $freshLegacy, $tester, null, null, null, null,
        $availability, new Logger($directory . '/logs'), $monitor);
    $administration = $admin->databaseAdministration();
    $administration->saveServer(['id' => 'sql01', 'name' => 'SQL Server', 'enabled' => true, 'connection' => array_diff_key($v2, ['database' => true]) + ['options' => $v2['options']]]);
    $administration->saveDatabase(['id' => 'reporting', 'name' => 'Reporting', 'server' => 'sql01', 'enabled' => true, 'catalog' => 'ReportingDB']);
    upgradeAssert($registry->defaultDatabaseId() === 'reporting' && $registry->requiresEncryptionKey() && $registry->source() === DatabaseRegistry::SOURCE_REGISTRY,
        'The first Admin configuration did not create an encrypted registry with a default database.');
    $registry->verify();
    // SQL Server unreachable: a clear error, nothing opened, nothing exposed.
    [$status, $code] = $respond(upgradeFailure(fn () => $admin->controlDatabase('connect', 'reporting')));
    upgradeAssert($status === 422 && $code === 'DATABASE_CONNECTION_FAILED' && !$availability->available('reporting'), 'An unreachable SQL Server was not reported safely.');
    $sqlServerDown = false;
    upgradeAssert($admin->controlDatabase('connect', 'reporting')['connected'] && $availability->available('reporting'), 'A reachable SQL Server did not connect.');
    // The key-preparation script never replaces a key that encrypted configuration depends on.
    $keyScript = (string)file_get_contents(dirname(__DIR__) . '/scripts/prepare-local-encryption-key.php');
    upgradeAssert(strpos($keyScript, 'requiresEncryptionKey()') !== false
        && strpos($keyScript, 'requiresEncryptionKey()') < strpos($keyScript, 'random_bytes(32)'), 'Key preparation can replace a key in use.');

    // 2. V2 → V3 migration of a plaintext and an encrypted database.json.
    foreach (['plaintext' => $v2, 'encrypted' => (new DatabaseCredentialEncryption($key))->encryptConfiguration($v2)] as $kind => $stored) {
        [$root, $legacyPath] = $install("v2-{$kind}");
        JsonFileStore::save($legacyPath, $stored);
        JsonFileStore::save($root . '/config/database-state.json', ['version' => 1, 'available' => true, 'updatedAt' => gmdate(DATE_ATOM)]);
        $legacyContents = (string)file_get_contents($legacyPath);
        $registry = DatabaseRegistry::forLegacyPath($legacyPath);
        $availability = new DatabaseAvailabilityManager($root . '/config/database-state.json', $registry);
        // Before migration V2 keeps working: the file is the default server and database.
        $before = (new DatabaseContextResolver($registry, $availability))->resolveAvailable();
        upgradeAssert($registry->source() === DatabaseRegistry::SOURCE_LEGACY && $before->id === 'default' && $before->physicalName() === 'ReportingDB'
            && $before->driverConfiguration()['password'] === 'upgrade-secret' && (string)file_get_contents($legacyPath) === $legacyContents,
            "A {$kind} V2 configuration did not keep working before migration.");
        $migration = $registry->migrateLegacy();
        upgradeAssert($migration === ['migrated' => true, 'source' => 'registry', 'legacyRetired' => true] && !is_file($legacyPath), "{$kind} migration did not complete.");
        $after = (new DatabaseContextResolver($registry, $availability))->resolveAvailable();
        $plan = (new DatabaseQueryPlanner(new DatabaseContextResolver($registry, $availability)))->plan(['action' => 'select', 'source' => ['table' => 'T'], 'fields' => ['Id']]);
        upgradeAssert($after->id === 'default' && $after->driverConfiguration() == $before->driverConfiguration() && $plan->primaryDatabase->id === 'default'
            && $registry->requiresEncryptionKey(), "{$kind} migration changed the default database or its connection.");
        $registryContents = (string)file_get_contents($registry->registryPath());
        foreach ($secrets as $secret) upgradeAssert(!str_contains($registryContents, $secret), "{$kind} migration stored {$secret} in plaintext.");
        upgradeAssert($registry->migrateLegacy() === ['migrated' => false, 'source' => 'registry', 'legacyRetired' => false]
            && (string)file_get_contents($registry->registryPath()) === $registryContents, "{$kind} migration is not idempotent.");
        // Runtime state: the V1 gate is the default database's, and the first write upgrades it to version 2.
        $availability->setAvailable(false, 'default');
        $state = JsonFileStore::load($root . '/config/database-state.json');
        upgradeAssert($state['version'] === 2 && $state['databases']['default']['available'] === false && !$availability->available(), "{$kind} runtime state did not migrate.");
    }

    // The Admin migration: Databases → Servers → edit the `default` profile, leave the password blank, save.
    // The form submits the login timeout it shows (the default), so only that option may become explicit.
    $withoutTimeout = function (array $configuration): array {
        unset($configuration['options']['loginTimeoutSeconds']);
        return $configuration;
    };
    foreach (['plaintext' => $v2, 'encrypted' => (new DatabaseCredentialEncryption($key))->encryptConfiguration($v2)] as $kind => $stored) {
        [$root, $legacyPath] = $install("v2-admin-{$kind}");
        JsonFileStore::save($legacyPath, $stored);
        $registry = DatabaseRegistry::forLegacyPath($legacyPath);
        $availability = new DatabaseAvailabilityManager($root . '/config/database-state.json', $registry);
        $before = (new DatabaseContextResolver($registry, $availability))->resolve();
        $administration = (new AdminService(new AdminConfigurationRepository($root . '/config/admin.json'), $legacyPath, $tester, null, null, null, null,
            $availability, new Logger($directory . '/logs'), new ApplicationHealthMonitor($healthOptions($root, $registry))))->databaseAdministration();
        $validator = new AdminRequestValidator();
        $listed = $administration->dispatch($validator->validate(['action' => 'admin.servers.list']))['servers'][0];
        $administration->dispatch($validator->validate(['action' => 'admin.servers.save', 'server' => ['id' => $listed['id'], 'name' => $listed['name'],
            'enabled' => $listed['enabled'], 'password' => null] + array_diff_key($listed['connection'], ['provider' => true, 'passwordConfigured' => true])]));
        $after = (new DatabaseContextResolver($registry, $availability))->resolve();
        upgradeAssert($listed['id'] === 'default' && !is_file($legacyPath) && $registry->source() === DatabaseRegistry::SOURCE_REGISTRY
            && $after->id === 'default' && $withoutTimeout($after->driverConfiguration()) == $withoutTimeout($before->driverConfiguration())
            && DatabaseServerProfile::loginTimeoutFrom($after->driverConfiguration()) === DatabaseServerProfile::loginTimeoutFrom($before->driverConfiguration()),
            "Saving the default server profile did not migrate a {$kind} V2 configuration unchanged.");
    }

    // 3. Failed migrations leave the V2 file in place and write no registry.
    $encryptedV2 = (new DatabaseCredentialEncryption($key))->encryptConfiguration($v2);
    $failures = [
        'missing key' => [$encryptedV2, fn () => putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)],
        'wrong key' => [$encryptedV2, fn () => putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)))],
        'invalid configuration' => [['provider' => 'sqlserver', 'server' => '', 'database' => 'X'], fn () => null],
        'tampered ciphertext' => [array_replace($encryptedV2, ['tag' => base64_encode(random_bytes(16))]), fn () => null],
    ];
    foreach ($failures as $case => [$stored, $prepare]) {
        [$root, $legacyPath] = $install('failed-' . str_replace(' ', '-', $case));
        JsonFileStore::save($legacyPath, $stored);
        $legacyContents = (string)file_get_contents($legacyPath);
        $prepare();
        $failure = upgradeFailure(fn () => DatabaseRegistry::forLegacyPath($legacyPath)->migrateLegacy());
        putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
        upgradeAssert($failure !== null && (string)file_get_contents($legacyPath) === $legacyContents && !is_file(dirname($legacyPath) . '/databases.json'),
            "A failed migration ({$case}) changed the V2 configuration or wrote a registry.");
        [$status] = $respond($failure);
        upgradeAssert($status >= 500, "A failed migration ({$case}) was not reported as a configuration error.");
    }
    // A registry that cannot be written: the V2 file is kept.
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        [$root, $legacyPath] = $install('failed-unwritable');
        JsonFileStore::save($legacyPath, $encryptedV2);
        $legacyContents = (string)file_get_contents($legacyPath);
        chmod(dirname($legacyPath), 0500);
        $failure = upgradeFailure(fn () => DatabaseRegistry::forLegacyPath($legacyPath)->migrateLegacy());
        chmod(dirname($legacyPath), 0700);
        upgradeAssert($failure !== null && (string)file_get_contents($legacyPath) === $legacyContents && !is_file(dirname($legacyPath) . '/databases.json'),
            'A migration that could not write the registry lost the V2 configuration.');
    }

    // 4. Both files present: the registry is authoritative and the V2 file is left alone.
    [$root, $legacyPath] = $install('both');
    $registry = DatabaseRegistry::forLegacyPath($legacyPath);
    JsonFileStore::save($legacyPath, $v2);
    $registry->migrateLegacy();
    JsonFileStore::save($legacyPath, array_replace($v2, ['database' => 'StaleDB']));
    $staleContents = (string)file_get_contents($legacyPath);
    $registry = DatabaseRegistry::forLegacyPath($legacyPath);
    upgradeAssert($registry->source() === DatabaseRegistry::SOURCE_REGISTRY && $registry->databaseCatalog('default') === 'ReportingDB'
        && $registry->migrateLegacy()['migrated'] === false && (string)file_get_contents($legacyPath) === $staleContents,
        'A leftover V2 file overrode or was changed by the registry.');

    // No secret reached a response or a log.
    $logText = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/logs', FilesystemIterator::SKIP_DOTS)) as $file) $logText .= (string)file_get_contents($file->getPathname());
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/operational', FilesystemIterator::SKIP_DOTS)) as $file) $logText .= (string)file_get_contents($file->getPathname());
    foreach ([...$secrets, $key] as $secret) {
        upgradeAssert(!str_contains(implode("\n", $responses), $secret) && !str_contains($logText, $secret), "An error or log exposed {$secret}.");
    }

    echo "Upgrade verification tests passed.\n";
} finally {
    foreach ($environment as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
    upgradeRemoveDirectory($directory);
}
