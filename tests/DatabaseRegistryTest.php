<?php

require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseRegistryMigrator.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../core/JsonFileStore.php';

function registryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function registryFailure(callable $operation, string $message, string $expectedClass = Throwable::class): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        registryAssert($exception instanceof $expectedClass, $message . ' (unexpected ' . get_class($exception) . ')');
        return $exception;
    }
    throw new RuntimeException($message);
}

function registryRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($directory);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-database-registry-' . bin2hex(random_bytes(8));
$oldKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
$key = base64_encode(random_bytes(32));

$company = [
    'provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'sql01.registry.test', 'port' => '1433',
    'database' => 'CompanyDB', 'authentication' => 'sql', 'username' => 'registry_user',
    'password' => 'registry-secret', 'options' => ['encrypt' => true, 'trustServerCertificate' => false],
];
$sql02 = ['provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'sql02.registry.test', 'authentication' => 'sql',
    'username' => 'legacy_user', 'password' => 'legacy-secret', 'options' => ['encrypt' => true, 'trustServerCertificate' => false]];

try {
    mkdir($directory, 0700, true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);
    $encryption = new DatabaseCredentialEncryption();

    // 1. Owner-bound envelopes: version 2, AAD-authenticated, never interchangeable.
    $bound = $encryption->encryptBound(['database' => 'CompanyDB'], 'database/company');
    registryAssert(DatabaseCredentialEncryption::isBoundEnvelope($bound) && $bound['version'] === 2, 'Bound envelope has the wrong format.');
    registryAssert($encryption->decryptBound($bound, 'database/company') === ['database' => 'CompanyDB'], 'Bound envelope round trip failed.');
    registryFailure(fn () => $encryption->decryptBound($bound, 'database/inventory'), 'A bound envelope decrypted for another owner.', DatabaseCredentialException::class);
    registryFailure(fn () => $encryption->decryptConfiguration($bound), 'A bound envelope decrypted without its binding.', DatabaseCredentialException::class);
    $unbound = $encryption->encryptConfiguration(['database' => 'CompanyDB']);
    registryAssert($unbound['version'] === 1 && !DatabaseCredentialEncryption::isBoundEnvelope($unbound), 'Version 1 envelopes changed format.');
    registryFailure(fn () => $encryption->decryptBound($unbound, 'database/company'), 'An unbound envelope was accepted as bound.', DatabaseCredentialException::class);
    $tampered = $bound;
    $tampered['ciphertext'] = base64_encode(strrev((string)base64_decode($bound['ciphertext'])));
    registryFailure(fn () => $encryption->decryptBound($tampered, 'database/company'), 'A tampered bound envelope decrypted.', DatabaseCredentialException::class);
    registryFailure(fn () => (new DatabaseCredentialEncryption(base64_encode(random_bytes(32))))->decryptBound($bound, 'database/company'),
        'A bound envelope decrypted with the wrong key.', DatabaseCredentialException::class);

    // 2. Nothing stored: not configured, no key needed.
    $emptyDirectory = $directory . '/empty';
    mkdir($emptyDirectory, 0700);
    $empty = DatabaseRegistry::forLegacyPath($emptyDirectory . '/database.json');
    registryAssert($empty->source() === DatabaseRegistry::SOURCE_NONE && $empty->configurationState() === 'configuration_missing'
        && !$empty->requiresEncryptionKey() && $empty->defaultDatabaseId() === null, 'An empty installation is not reported as unconfigured.');

    // 3. A V2 database.json is read through, unchanged, until the first write.
    $legacyDirectory = $directory . '/legacy';
    mkdir($legacyDirectory, 0700);
    $legacyPath = $legacyDirectory . '/database.json';
    JsonFileStore::save($legacyPath, $encryption->encryptConfiguration($company));
    $legacyContents = (string)file_get_contents($legacyPath);
    $legacy = DatabaseRegistry::forLegacyPath($legacyPath);
    $metadata = $legacy->metadata();
    registryAssert($legacy->source() === DatabaseRegistry::SOURCE_LEGACY && $metadata['defaultDatabase'] === 'default'
        && $metadata['databases']['default']['server'] === 'default', 'A V2 configuration is not presented as default/default.');
    registryAssert($legacy->connectionConfiguration() == $company && $legacy->connectionConfiguration('default') == $company,
        'A V2 configuration did not resolve unchanged.');
    registryAssert($legacy->databaseCatalog('default') === 'CompanyDB' && !array_key_exists('database', $legacy->serverConnection('default')),
        'A V2 configuration was not split into profile and catalog.');
    registryFailure(fn () => $legacy->connectionConfiguration('inventory'), 'A V2 configuration resolved an unknown database.', DatabaseCredentialException::class);
    registryAssert($legacy->requiresEncryptionKey() && $legacy->configurationState() === 'configured', 'V2 key requirements were not detected.');
    registryAssert(!is_file($legacyDirectory . '/databases.json') && (string)file_get_contents($legacyPath) === $legacyContents,
        'Reading a V2 configuration wrote to storage.');
    // Password-only and plaintext V2 files remain readable.
    $passwordOnlyDirectory = $directory . '/password-only';
    mkdir($passwordOnlyDirectory, 0700);
    JsonFileStore::save($passwordOnlyDirectory . '/database.json', [...$company, 'password' => $encryption->encryptPassword('registry-secret')]);
    $passwordOnly = DatabaseRegistry::forLegacyPath($passwordOnlyDirectory . '/database.json');
    registryAssert($passwordOnly->connectionConfiguration()['password'] === 'registry-secret' && $passwordOnly->requiresEncryptionKey(),
        'A V2 password-only configuration did not resolve.');
    $plaintextDirectory = $directory . '/plaintext';
    mkdir($plaintextDirectory, 0700);
    JsonFileStore::save($plaintextDirectory . '/database.json', $company);
    $plaintext = DatabaseRegistry::forLegacyPath($plaintextDirectory . '/database.json');
    registryAssert($plaintext->connectionConfiguration() == $company && !$plaintext->requiresEncryptionKey() && !$plaintext->usesEncryption(),
        'A plaintext V2 configuration did not resolve.');

    // 4. Without the key, a sealed V2 file is reported as a missing key.
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    $keylessLegacy = DatabaseRegistry::forLegacyPath($legacyPath);
    registryAssert($keylessLegacy->configurationState() === 'encryption_key_missing', 'A missing key was not detected for a V2 configuration.');
    registryAssert($keylessLegacy->metadata()['defaultDatabase'] === 'default', 'Registry metadata required the encryption key.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    // 5. Explicit migration: verified, sealed per entry, and the V2 file retired.
    $migration = $legacy->migrateLegacy();
    registryAssert($migration === ['migrated' => true, 'source' => 'registry', 'legacyRetired' => true], 'Migration result is incorrect.');
    registryAssert($legacy->source() === DatabaseRegistry::SOURCE_REGISTRY && !is_file($legacyPath), 'Migration did not replace database.json.');
    registryAssert($legacy->connectionConfiguration() == $company, 'Migration changed the effective configuration.');
    $registryContents = (string)file_get_contents($legacy->registryPath());
    foreach (['registry-secret', 'sql01.registry.test', 'registry_user', 'CompanyDB'] as $secret) {
        registryAssert(!str_contains($registryContents, $secret), 'The registry stored connection data in plaintext.');
    }
    $document = $legacy->storedDocument();
    registryAssert(DatabaseCredentialEncryption::isBoundEnvelope($document['servers']['default']['connection'])
        && DatabaseCredentialEncryption::isBoundEnvelope($document['databases']['default']['catalog']), 'Registry entries are not owner-bound envelopes.');
    registryAssert($legacy->migrateLegacy() === ['migrated' => false, 'source' => 'registry', 'legacyRetired' => false], 'A second migration was not a no-op.');
    if (PHP_OS_FAMILY !== 'Windows') registryAssert((fileperms($legacy->registryPath()) & 0777) === 0600, 'The registry file is not owner-only.');

    // 6. Profiles and databases: same-server contexts share one profile.
    $registry = $legacy;
    $registry->saveDatabase('inventory', 'Inventory', 'default', true, 'InventoryDB');
    $registry->saveDatabase('reports', 'Reporting', 'default', true, 'Reporting]DB');
    $registry->saveServer('sql02', 'SQL Server 02', true, $sql02);
    $registry->saveDatabase('legacy', 'Legacy', 'sql02', false, 'LegacyDB');
    $metadata = $registry->metadata();
    registryAssert(array_keys($metadata['databases']) === ['default', 'inventory', 'reports', 'legacy']
        && $metadata['databases']['inventory']['server'] === 'default' && $metadata['databases']['legacy']['enabled'] === false,
        'Registry metadata does not reflect the configured topology.');
    registryAssert($registry->connectionConfiguration('inventory')['database'] === 'InventoryDB'
        && $registry->connectionConfiguration('inventory')['server'] === 'sql01.registry.test', 'A same-server database did not use its profile.');
    registryAssert($registry->databaseCatalog('reports') === 'Reporting]DB', 'A physical name needing quoting was not preserved.');
    registryAssert($registry->connectionConfiguration('legacy')['server'] === 'sql02.registry.test'
        && $registry->connectionConfiguration('legacy')['password'] === 'legacy-secret', 'A second server profile did not resolve its own credentials.');
    registryAssert($registry->connectionConfiguration()['database'] === 'CompanyDB', 'The default database changed.');
    $registry->verify();

    // 7. Registry invariants.
    registryFailure(fn () => $registry->saveDatabase('Bad Id', 'Bad', 'default', true, 'X'), 'An invalid database id was accepted.', InvalidArgumentException::class);
    registryFailure(fn () => $registry->saveServer('1abc', 'Bad', true, $sql02), 'An invalid server id was accepted.', InvalidArgumentException::class);
    registryFailure(fn () => $registry->saveDatabase('orphan', 'Orphan', 'missing', true, 'X'), 'A database on an unknown profile was accepted.', InvalidArgumentException::class);
    registryFailure(fn () => $registry->saveServer('bad', 'Bad', true, [...$sql02, 'database' => 'X']), 'A profile with a database name was accepted.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveServer('bad', 'Bad', true, [...$sql02, 'provider' => 'mysql']), 'A non-SQL Server profile was accepted.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveServer('bad', 'Bad', true, [...$sql02, 'connectionString' => 'Server=x']), 'An unknown connection key was accepted.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveDatabase('bad', 'Bad', 'default', true, "Bad\nName"), 'A control character in a database name was accepted.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveDatabase('bad', 'Bad', 'default', true, str_repeat('x', 129)), 'An overlong database name was accepted.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->deleteServer('sql02'), 'A profile hosting databases was deleted.', InvalidArgumentException::class);
    registryFailure(fn () => $registry->deleteDatabase('default'), 'The default database was deleted.', InvalidArgumentException::class);
    registryFailure(fn () => $registry->setDefaultDatabase('legacy'), 'A disabled database became the default.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveDatabase('default', 'Default', 'default', false, 'CompanyDB'), 'The default database was disabled.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveServer('default', 'Default', false, $registry->serverConnection('default')), 'The default database profile was disabled.', DatabaseCredentialException::class);
    registryAssert($registry->connectionConfiguration() == $company, 'A rejected change modified the registry.');
    $registry->setDefaultDatabase('inventory');
    registryAssert($registry->defaultDatabaseId() === 'inventory' && $registry->connectionConfiguration()['database'] === 'InventoryDB', 'The default database could not be changed.');
    $registry->deleteDatabase('legacy');
    $registry->deleteServer('sql02');
    registryAssert(array_keys($registry->metadata()['servers']) === ['default'], 'Deleting a database and its profile failed.');
    $registry->setDefaultDatabase('default');

    // 8. Structural validation of stored documents.
    $stored = JsonFileStore::load($registry->registryPath());
    foreach ([
        'unknown key' => [...$stored, 'extra' => true],
        'wrong version' => [...$stored, 'version' => 1],
        'unknown default' => [...$stored, 'defaultDatabase' => 'missing'],
        'list sections' => [...$stored, 'servers' => [$stored['servers']['default']]],
        'unbound envelope' => array_replace_recursive($stored, ['databases' => ['default' => ['catalog' => $unbound]]]),
        'dangling profile' => array_replace_recursive($stored, ['databases' => ['default' => ['server' => 'missing']]]),
    ] as $case => $invalid) {
        registryFailure(fn () => DatabaseRegistry::validateDocument($invalid), "A registry with {$case} was accepted.", DatabaseCredentialException::class);
    }
    // An envelope moved between entries is structurally valid but never decrypts.
    $swapped = $stored;
    [$swapped['databases']['default']['catalog'], $swapped['databases']['inventory']['catalog']]
        = [$stored['databases']['inventory']['catalog'], $stored['databases']['default']['catalog']];
    DatabaseRegistry::validateDocument($swapped);
    registryFailure(fn () => DatabaseRegistry::verifyDocument($swapped), 'Swapped catalog envelopes decrypted.', DatabaseCredentialException::class);
    $copiedProfile = $stored;
    $copiedProfile['databases']['inventory']['catalog'] = $stored['servers']['default']['connection'];
    registryFailure(fn () => DatabaseRegistry::verifyDocument($copiedProfile), 'A profile envelope decrypted as a catalog.', DatabaseCredentialException::class);
    JsonFileStore::save($registry->registryPath(), $swapped);
    registryAssert($registry->configurationState() === 'configuration_invalid', 'A tampered registry was not reported invalid.');
    registryFailure(fn () => $registry->verify(), 'A tampered registry verified.', DatabaseCredentialException::class);
    registryFailure(fn () => $registry->saveDatabase('extra', 'Extra', 'default', true, 'ExtraDB'), 'A tampered registry accepted a write.', DatabaseCredentialException::class);
    JsonFileStore::save($registry->registryPath(), $stored);
    registryAssert($registry->configurationState() === 'configured', 'The restored registry is not configured.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    // A new instance, as each request creates: the key is read when first needed.
    $keyless = new DatabaseRegistry($registry->registryPath(), $registry->legacyPath());
    registryAssert($keyless->configurationState() === 'encryption_key_missing' && $keyless->requiresEncryptionKey(),
        'A missing key was not detected for the registry.');
    registryAssert($keyless->metadata()['defaultDatabase'] === 'default', 'Registry metadata required the encryption key.');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $key);

    // 9. Single-database writes (the V2 Admin form) migrate on first write.
    $writeDirectory = $directory . '/write';
    mkdir($writeDirectory, 0700);
    JsonFileStore::save($writeDirectory . '/database.json', $encryption->encryptConfiguration($company));
    $writable = DatabaseRegistry::forLegacyPath($writeDirectory . '/database.json');
    $writable->saveDefaultConnection([...$company, 'password' => 'rotated-secret']);
    registryAssert($writable->source() === DatabaseRegistry::SOURCE_REGISTRY && !is_file($writeDirectory . '/database.json')
        && $writable->connectionConfiguration()['password'] === 'rotated-secret', 'A first write did not migrate database.json.');
    $writable->saveDatabase('inventory', 'Inventory', 'default', true, 'InventoryDB');
    $writable->setDefaultDatabase('inventory');
    $writable->saveDefaultConnection([...$company, 'database' => 'InventoryArchive']);
    registryAssert($writable->databaseCatalog('inventory') === 'InventoryArchive' && $writable->databaseCatalog('default') === 'CompanyDB',
        'A single-database write did not target the default database.');
    $fresh = DatabaseRegistry::forLegacyPath($directory . '/fresh/database.json');
    mkdir($directory . '/fresh', 0700);
    $fresh->saveDefaultConnection($company);
    registryAssert($fresh->defaultDatabaseId() === 'default' && $fresh->connectionConfiguration() == $company, 'A first save did not create default/default.');
    $firstServer = DatabaseRegistry::forLegacyPath($directory . '/first-server/database.json');
    mkdir($directory . '/first-server', 0700);
    $firstServer->saveServer('sql02', 'SQL Server 02', true, $sql02);
    registryAssert($firstServer->metadata()['defaultDatabase'] === null && $firstServer->configurationState() === 'configuration_missing',
        'A registry without databases is not reported as unconfigured.');
    registryAssert(str_contains((string)file_get_contents($firstServer->registryPath()), '"databases": {}'), 'Empty registry sections are not JSON objects.');
    $firstServer->saveDatabase('legacy', 'Legacy', 'sql02', true, 'LegacyDB');
    registryAssert($firstServer->defaultDatabaseId() === 'legacy', 'The first enabled database did not become the default.');

    // 10. Runtime state v2: per database, with V1 files read as the default database.
    $statePath = $directory . '/database-state.json';
    JsonFileStore::save($statePath, ['version' => 1, 'available' => true, 'updatedAt' => '2026-10-01T00:00:00+00:00']);
    $availability = new DatabaseAvailabilityManager($statePath, $registry);
    registryAssert($availability->available() && $availability->available('default') && !$availability->available('inventory'),
        'A version 1 state was not applied to the default database only.');
    registryAssert($availability->status() === ['version' => 2, 'database' => 'default', 'available' => true, 'updatedAt' => '2026-10-01T00:00:00+00:00'],
        'Availability status has the wrong shape.');
    $changed = $availability->setAvailable(true, 'inventory');
    registryAssert($changed['database'] === 'inventory' && $changed['available'] === true, 'Per-database availability was not saved.');
    $state = JsonFileStore::load($statePath);
    registryAssert($state['version'] === 2 && array_keys($state['databases']) === ['default', 'inventory']
        && $state['databases']['default']['available'] === true, 'A version 1 state was not rewritten as version 2.');
    $availability->setAvailable(false);
    registryAssert(!$availability->available() && $availability->available('inventory'), 'Database availability is not independent.');
    $availability->forget('inventory');
    registryAssert(!$availability->available('inventory') && array_keys(JsonFileStore::load($statePath)['databases']) === ['default'], 'Forgetting a database failed.');
    registryFailure(fn () => $availability->setAvailable(true, 'Bad Id'), 'An invalid database id was accepted.', InvalidArgumentException::class);
    foreach ([
        ['version' => 2, 'databases' => ['Bad Id' => ['available' => true, 'updatedAt' => null]]],
        ['version' => 2, 'databases' => ['company' => ['available' => 'yes', 'updatedAt' => null]]],
        ['version' => 2, 'databases' => ['company' => ['available' => true, 'updatedAt' => null, 'extra' => 1]]],
        ['version' => 2, 'databases' => [], 'available' => true],
        ['version' => 3, 'databases' => []],
        ['version' => 1, 'available' => 'yes'],
    ] as $invalidState) {
        registryAssert(!DatabaseAvailabilityManager::isValidState($invalidState), 'An invalid availability state was accepted.');
    }
    registryAssert(DatabaseAvailabilityManager::isValidState(['version' => 2, 'databases' => []])
        && DatabaseAvailabilityManager::isValidState(['version' => 1, 'available' => false]), 'Valid availability states were rejected.');

    // 11. Health reads the default database through the registry.
    $healthRoot = $directory . '/health';
    foreach (['config', 'runtime', 'logs', 'database/config'] as $path) mkdir($healthRoot . '/' . $path, 0700, true);
    file_put_contents($healthRoot . '/config/app.php', "<?php return ['version' => 'test'];\n");
    $healthRegistry = DatabaseRegistry::forLegacyPath($healthRoot . '/database/config/database.json');
    $healthRegistry->saveDefaultConnection($company);
    $tested = [];
    $monitor = new ApplicationHealthMonitor([
        'root' => $healthRoot, 'configurationDirectory' => $healthRoot . '/config', 'runtimeDirectory' => $healthRoot . '/runtime',
        'logDirectory' => $healthRoot . '/logs', 'databaseAvailable' => static fn (): bool => true, 'production' => false,
        'databaseTester' => static function (array $configuration) use (&$tested): void { $tested[] = $configuration; },
        'diskSpace' => static fn (): int => PHP_INT_MAX,
    ]);
    $detailed = $monitor->detailed([]);
    registryAssert($detailed['checks']['database']['status'] === 'healthy' && $detailed['checks']['encryption']['category'] === 'configured'
        && count($tested) === 1 && $tested[0] == $company, 'Health did not test the registry default database.');
    $healthDocument = JsonFileStore::load($healthRegistry->registryPath());
    $healthDocument['servers']['default']['connection']['tag'] = base64_encode(random_bytes(16));
    JsonFileStore::save($healthRegistry->registryPath(), $healthDocument);
    registryAssert($monitor->detailed([])['checks']['encryption']['category'] === 'invalid', 'Health accepted a tampered registry.');

    // 12. The connection layer and key preparation no longer read database.json directly.
    foreach (['database/factory/DriverFactory.php', 'database/drivers/SqlServerDriver.php'] as $source) {
        registryAssert(!str_contains((string)file_get_contents(dirname(__DIR__) . '/' . $source), "config/database.json"),
            "{$source} still reads the V2 database configuration directly.");
    }
    registryAssert(str_contains((string)file_get_contents(dirname(__DIR__) . '/scripts/prepare-local-encryption-key.php'), 'requiresEncryptionKey()'),
        'Key preparation does not protect registry-encrypted configuration.');
    $gitignore = (string)file_get_contents(dirname(__DIR__) . '/.gitignore');
    registryAssert(str_contains($gitignore, "database/config/databases.json\n"), 'The database registry is not excluded from version control.');

    echo "Database registry tests passed.\n";
} finally {
    $oldKey === false ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE) : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    registryRemoveDirectory($directory);
}
