<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/DatabaseCredentialResolver.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/DatabaseRegistryMigrator.php';

/**
 * V3 database registry (database/config/databases.json).
 *
 * A server profile holds one SQL Server connection and its credentials; a
 * database context names one physical catalog on a profile. Only ids, display
 * names, flags, each database's profile reference, and the default database are
 * plaintext. Every profile connection and every physical catalog is sealed in
 * its own AES-256-GCM envelope bound (as AAD) to its entry, so an envelope moved
 * to another entry does not decrypt.
 *
 * Until the first write, a V2 database/config/database.json is presented
 * read-only as profile "default" hosting database "default". The first write,
 * or an explicit migration, replaces it with the registry.
 */
final class DatabaseRegistry
{
    public const VERSION = 2;
    public const DEFAULT_ID = 'default';
    public const SOURCE_REGISTRY = 'registry';
    public const SOURCE_LEGACY = 'legacy';
    public const SOURCE_NONE = 'none';
    public const CONNECTION_KEYS = ['provider', 'driver', 'server', 'port', 'authentication', 'username', 'password', 'options'];
    private const ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';
    private const MAX_NAME_LENGTH = 128;

    private string $registryPath;
    private string $legacyPath;
    private ?DatabaseCredentialEncryption $encryption;

    public function __construct(?string $registryPath = null, ?string $legacyPath = null, ?DatabaseCredentialEncryption $encryption = null)
    {
        $this->registryPath = $registryPath ?? ROOT_PATH . '/database/config/databases.json';
        $this->legacyPath = $legacyPath ?? dirname($this->registryPath) . DIRECTORY_SEPARATOR . 'database.json';
        $this->encryption = $encryption;
    }

    /** The registry stored beside a V2 database.json path, as existing callers pass it. */
    public static function forLegacyPath(string $legacyPath): self
    {
        return new self(dirname($legacyPath) . DIRECTORY_SEPARATOR . 'databases.json', $legacyPath);
    }

    public function registryPath(): string { return $this->registryPath; }
    public function legacyPath(): string { return $this->legacyPath; }

    public static function isValidId($id): bool
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    public static function serverBinding(string $serverId): string { return 'server/' . $serverId; }
    public static function databaseBinding(string $databaseId): string { return 'database/' . $databaseId; }

    public static function emptyDocument(): array
    {
        return ['version' => self::VERSION, 'defaultDatabase' => null, 'servers' => [], 'databases' => []];
    }

    public function source(): string
    {
        if (is_file($this->registryPath)) return self::SOURCE_REGISTRY;
        if (is_file($this->legacyPath)) return self::SOURCE_LEGACY;
        return self::SOURCE_NONE;
    }

    /** Changes whenever the stored configuration changes; null when none is stored. */
    public function fingerprint(): ?string
    {
        $source = $this->source();
        $path = $source === self::SOURCE_REGISTRY ? $this->registryPath
            : ($source === self::SOURCE_LEGACY ? $this->legacyPath : null);
        if ($path === null) return null;
        $hash = @hash_file('sha256', $path);
        return is_string($hash) ? $source . ':' . $hash : null;
    }

    /** The validated registry document. Requires the registry file. */
    public function storedDocument(): array
    {
        try {
            $document = JsonFileStore::load($this->registryPath);
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
        self::validateDocument($document);
        return self::normalizeDocument($document);
    }

    /**
     * Ids, names, flags, and profile references only. Nothing is decrypted, so
     * this works without the encryption key.
     */
    public function metadata(): array
    {
        $source = $this->source();
        if ($source === self::SOURCE_NONE) {
            return ['source' => $source, 'defaultDatabase' => null, 'servers' => [], 'databases' => []];
        }
        if ($source === self::SOURCE_LEGACY) {
            DatabaseConfigurationResolver::readStored($this->legacyPath);
            return [
                'source' => $source,
                'defaultDatabase' => self::DEFAULT_ID,
                'servers' => [self::DEFAULT_ID => ['name' => 'Default', 'enabled' => true]],
                'databases' => [self::DEFAULT_ID => ['name' => 'Default', 'server' => self::DEFAULT_ID, 'enabled' => true]],
            ];
        }
        $document = $this->storedDocument();
        $servers = [];
        foreach ($document['servers'] as $id => $server) {
            $servers[$id] = ['name' => $server['name'], 'enabled' => $server['enabled']];
        }
        $databases = [];
        foreach ($document['databases'] as $id => $database) {
            $databases[$id] = ['name' => $database['name'], 'server' => $database['server'], 'enabled' => $database['enabled']];
        }
        return ['source' => $source, 'defaultDatabase' => $document['defaultDatabase'], 'servers' => $servers, 'databases' => $databases];
    }

    public function defaultDatabaseId(): ?string
    {
        try {
            return $this->metadata()['defaultDatabase'];
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** Decrypted connection settings (with credentials) of one server profile. */
    public function serverConnection(string $serverId): array
    {
        if ($this->source() === self::SOURCE_LEGACY) {
            if ($serverId !== self::DEFAULT_ID) throw new DatabaseCredentialException('Database server profile is not configured.');
            return DatabaseRegistryMigrator::splitLegacyConfiguration($this->legacyConfiguration())['connection'];
        }
        $document = $this->storedDocument();
        if (!isset($document['servers'][$serverId])) {
            throw new DatabaseCredentialException('Database server profile is not configured.');
        }
        return self::decryptConnection($serverId, $document['servers'][$serverId]['connection'], $this->encryption());
    }

    /** Decrypted physical catalog name of one database context. */
    public function databaseCatalog(string $databaseId): string
    {
        if ($this->source() === self::SOURCE_LEGACY) {
            if ($databaseId !== self::DEFAULT_ID) throw new DatabaseCredentialException('Database is not configured.');
            return DatabaseRegistryMigrator::splitLegacyConfiguration($this->legacyConfiguration())['catalog'];
        }
        $document = $this->storedDocument();
        if (!isset($document['databases'][$databaseId])) {
            throw new DatabaseCredentialException('Database is not configured.');
        }
        return self::decryptCatalog($databaseId, $document['databases'][$databaseId]['catalog'], $this->encryption());
    }

    /**
     * The V2-shaped connection configuration of one database (the default when
     * null): its profile's connection plus its physical catalog. Enabled and
     * availability flags are not evaluated here.
     */
    public function connectionConfiguration(?string $databaseId = null): array
    {
        $source = $this->source();
        if ($source === self::SOURCE_NONE) {
            throw new DatabaseCredentialException('Database configuration has not been saved.');
        }
        if ($source === self::SOURCE_LEGACY) {
            if ($databaseId !== null && $databaseId !== self::DEFAULT_ID) {
                throw new DatabaseCredentialException('Database is not configured.');
            }
            return $this->legacyConfiguration();
        }
        $document = $this->storedDocument();
        $databaseId ??= $document['defaultDatabase'];
        if ($databaseId === null || !isset($document['databases'][$databaseId])) {
            throw new DatabaseCredentialException('Database is not configured.');
        }
        $database = $document['databases'][$databaseId];
        $encryption = $this->encryption();
        $connection = self::decryptConnection($database['server'], $document['servers'][$database['server']]['connection'], $encryption);
        return self::mergeConnection($connection, self::decryptCatalog($databaseId, $database['catalog'], $encryption));
    }

    /** Whether reading the stored configuration needs the encryption key. */
    public function requiresEncryptionKey(): bool
    {
        $source = $this->source();
        if ($source === self::SOURCE_NONE) return false;
        if ($source === self::SOURCE_LEGACY) {
            try {
                $stored = DatabaseConfigurationResolver::readStored($this->legacyPath);
            } catch (Throwable $exception) {
                return false;
            }
            return DatabaseConfigurationResolver::usesEncryption($stored)
                || DatabaseCredentialResolver::usesEncryption($stored['password'] ?? null);
        }
        try {
            $document = JsonFileStore::load($this->registryPath);
        } catch (Throwable $exception) {
            return true;
        }
        return !empty($document['servers']) || !empty($document['databases']);
    }

    /** Whether the stored configuration is fully sealed (always true for the registry). */
    public function usesEncryption(): bool
    {
        $source = $this->source();
        if ($source === self::SOURCE_REGISTRY) return true;
        if ($source === self::SOURCE_NONE) return false;
        try {
            return DatabaseConfigurationResolver::usesEncryption(DatabaseConfigurationResolver::readStored($this->legacyPath));
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * configured, configuration_missing, encryption_key_missing, or
     * configuration_invalid, for the default database.
     */
    public function configurationState(): string
    {
        $source = $this->source();
        if ($source === self::SOURCE_NONE) return 'configuration_missing';
        try {
            $this->connectionConfiguration();
            return 'configured';
        } catch (Throwable $exception) {
        }
        if ($source === self::SOURCE_REGISTRY) {
            try {
                if ($this->storedDocument()['defaultDatabase'] === null) return 'configuration_missing';
            } catch (Throwable $exception) {
                return 'configuration_invalid';
            }
        }
        return $this->requiresEncryptionKey() && !DatabaseConfigurationResolver::encryptionKeyIsAvailable()
            ? 'encryption_key_missing' : 'configuration_invalid';
    }

    /** Decrypt and validate every stored entry. */
    public function verify(): void
    {
        $source = $this->source();
        if ($source === self::SOURCE_REGISTRY) {
            self::verifyDocument($this->storedDocument(), $this->encryption());
        } elseif ($source === self::SOURCE_LEGACY) {
            $this->connectionConfiguration();
        } else {
            throw new DatabaseCredentialException('Database configuration has not been saved.');
        }
    }

    public function saveServer(string $id, string $name, bool $enabled, array $connection): void
    {
        self::assertId($id, 'server');
        $name = self::validName($name);
        self::validateConnection($connection);
        $this->mutate(function (array $document, DatabaseCredentialEncryption $encryption) use ($id, $name, $enabled, $connection): array {
            $document['servers'][$id] = [
                'name' => $name,
                'enabled' => $enabled,
                'connection' => $encryption->encryptBound($connection, self::serverBinding($id)),
            ];
            return $document;
        });
    }

    /** Create or update a database context. The first enabled database becomes the default. */
    public function saveDatabase(string $id, string $name, string $serverId, bool $enabled, string $catalog): void
    {
        self::assertId($id, 'database');
        $name = self::validName($name);
        self::validatePhysicalName($catalog);
        $this->mutate(function (array $document, DatabaseCredentialEncryption $encryption) use ($id, $name, $serverId, $enabled, $catalog): array {
            if (!isset($document['servers'][$serverId])) {
                throw new InvalidArgumentException('The database references an unknown server profile.');
            }
            $document['databases'][$id] = [
                'name' => $name,
                'server' => $serverId,
                'enabled' => $enabled,
                'catalog' => $encryption->encryptBound(['database' => $catalog], self::databaseBinding($id)),
            ];
            if ($document['defaultDatabase'] === null && $enabled) $document['defaultDatabase'] = $id;
            return $document;
        });
    }

    public function deleteServer(string $id): void
    {
        $this->mutate(function (array $document) use ($id): array {
            if (!isset($document['servers'][$id])) throw new InvalidArgumentException('The server profile does not exist.');
            foreach ($document['databases'] as $database) {
                if ($database['server'] === $id) {
                    throw new InvalidArgumentException('A server profile that still hosts databases cannot be deleted.');
                }
            }
            unset($document['servers'][$id]);
            return $document;
        });
    }

    public function deleteDatabase(string $id): void
    {
        $this->mutate(function (array $document) use ($id): array {
            if (!isset($document['databases'][$id])) throw new InvalidArgumentException('The database does not exist.');
            if ($document['defaultDatabase'] === $id) {
                throw new InvalidArgumentException('The default database cannot be deleted.');
            }
            unset($document['databases'][$id]);
            return $document;
        });
    }

    public function setDefaultDatabase(string $id): void
    {
        $this->mutate(function (array $document) use ($id): array {
            if (!isset($document['databases'][$id])) throw new InvalidArgumentException('The database does not exist.');
            $document['defaultDatabase'] = $id;
            return $document;
        });
    }

    /**
     * Store a V2-shaped configuration (from the single-database Admin form) as
     * the default database and its server profile, creating "default"/"default"
     * when the registry is empty. Names and flags are kept.
     */
    public function saveDefaultConnection(array $configuration): void
    {
        $split = DatabaseRegistryMigrator::splitLegacyConfiguration($configuration);
        self::validateConnection($split['connection']);
        self::validatePhysicalName($split['catalog']);
        $this->mutate(function (array $document, DatabaseCredentialEncryption $encryption) use ($split): array {
            $databaseId = $document['defaultDatabase'] ?? self::DEFAULT_ID;
            $database = $document['databases'][$databaseId] ?? null;
            $serverId = $database['server'] ?? self::DEFAULT_ID;
            $server = $document['servers'][$serverId] ?? null;
            $document['servers'][$serverId] = [
                'name' => $server['name'] ?? 'Default',
                'enabled' => $server['enabled'] ?? true,
                'connection' => $encryption->encryptBound($split['connection'], self::serverBinding($serverId)),
            ];
            $document['databases'][$databaseId] = [
                'name' => $database['name'] ?? 'Default',
                'server' => $serverId,
                'enabled' => $database['enabled'] ?? true,
                'catalog' => $encryption->encryptBound(['database' => $split['catalog']], self::databaseBinding($databaseId)),
            ];
            $document['defaultDatabase'] = $databaseId;
            return $document;
        });
    }

    /**
     * Replace a V2 database.json with an equivalent registry. Nothing happens
     * when the registry already exists or nothing is configured.
     */
    public function migrateLegacy(): array
    {
        return $this->withMutationLock(function (): array {
            $source = $this->source();
            if ($source !== self::SOURCE_LEGACY) {
                return ['migrated' => false, 'source' => $source, 'legacyRetired' => false];
            }
            $legacy = DatabaseRegistryMigrator::splitLegacyConfiguration($this->legacyConfiguration());
            $expected = self::mergeConnection($legacy['connection'], $legacy['catalog']);
            $document = DatabaseRegistryMigrator::documentFromLegacyStored(
                DatabaseConfigurationResolver::readStored($this->legacyPath),
                $this->encryption()
            );
            $this->persist($document);
            if (self::canonical($this->connectionConfiguration(self::DEFAULT_ID)) !== self::canonical($expected)) {
                @unlink($this->registryPath);
                throw new DatabaseCredentialException('Unable to verify the migrated database configuration.');
            }
            return ['migrated' => true, 'source' => self::SOURCE_REGISTRY, 'legacyRetired' => $this->retireLegacy()];
        });
    }

    /**
     * Structural validation that needs no key: shape, ids, names, references,
     * the default database, and owner-bound envelopes.
     */
    public static function validateDocument(array $document): void
    {
        $invalid = static fn () => throw new DatabaseCredentialException('Invalid database configuration.');
        $keys = array_keys($document);
        sort($keys);
        if ($keys !== ['databases', 'defaultDatabase', 'servers', 'version'] || $document['version'] !== self::VERSION) $invalid();
        foreach (['servers', 'databases'] as $section) {
            if (!is_array($document[$section]) || ($document[$section] !== [] && array_is_list($document[$section]))) $invalid();
        }
        foreach ($document['servers'] as $id => $server) {
            if (!self::isValidId((string)$id) || !is_array($server) || array_keys($server) !== ['name', 'enabled', 'connection']
                || !self::isValidName($server['name']) || !is_bool($server['enabled'])
                || !DatabaseCredentialEncryption::isBoundEnvelope($server['connection'])) $invalid();
        }
        foreach ($document['databases'] as $id => $database) {
            if (!self::isValidId((string)$id) || !is_array($database) || array_keys($database) !== ['name', 'server', 'enabled', 'catalog']
                || !self::isValidName($database['name']) || !is_bool($database['enabled'])
                || !is_string($database['server']) || !isset($document['servers'][$database['server']])
                || !DatabaseCredentialEncryption::isBoundEnvelope($database['catalog'])) $invalid();
        }
        $default = $document['defaultDatabase'];
        if ($default === null) {
            if ($document['databases'] !== []) $invalid();
            return;
        }
        // The default serves every request that names no database, so it and
        // its server profile must stay enabled.
        if (!is_string($default) || !isset($document['databases'][$default])
            || !$document['databases'][$default]['enabled']
            || !$document['servers'][$document['databases'][$default]['server']]['enabled']) $invalid();
    }

    /** Decrypt and validate every envelope of a structurally valid document. */
    public static function verifyDocument(array $document, ?DatabaseCredentialEncryption $encryption = null): void
    {
        self::validateDocument($document);
        $encryption ??= new DatabaseCredentialEncryption();
        foreach ($document['servers'] as $id => $server) {
            self::decryptConnection((string)$id, $server['connection'], $encryption);
        }
        foreach ($document['databases'] as $id => $database) {
            self::decryptCatalog((string)$id, $database['catalog'], $encryption);
        }
    }

    /** Server profile connection: SQL Server only, and never a database name. */
    public static function validateConnection(array $connection): void
    {
        if (array_diff(array_keys($connection), self::CONNECTION_KEYS) !== []
            || strtolower(trim((string)($connection['provider'] ?? ''))) !== 'sqlserver'
            || !is_string($connection['server'] ?? null) || trim($connection['server']) === ''
            || (array_key_exists('password', $connection) && !is_string($connection['password']))) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
        DatabaseConfigurationResolver::validateResolved($connection);
    }

    /** A SQL Server database name (sysname): 1-128 characters without control characters. */
    public static function validatePhysicalName($name): void
    {
        if (!is_string($name) || trim($name) === '' || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || preg_match('//u', $name) !== 1 || preg_match_all('/./su', $name) > 128) {
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
    }

    public static function mergeConnection(array $connection, string $catalog): array
    {
        $merged = [];
        foreach ($connection as $key => $value) {
            $merged[$key] = $value;
            if ($key === 'server') $merged['database'] = $catalog;
        }
        return $merged;
    }

    private static function decryptConnection(string $serverId, array $envelope, DatabaseCredentialEncryption $encryption): array
    {
        $connection = $encryption->decryptBound($envelope, self::serverBinding($serverId));
        self::validateConnection($connection);
        return $connection;
    }

    private static function decryptCatalog(string $databaseId, array $envelope, DatabaseCredentialEncryption $encryption): string
    {
        $catalog = $encryption->decryptBound($envelope, self::databaseBinding($databaseId));
        if (array_keys($catalog) !== ['database']) throw new DatabaseCredentialException('Invalid database configuration.');
        self::validatePhysicalName($catalog['database']);
        return $catalog['database'];
    }

    private function legacyConfiguration(): array
    {
        $configuration = DatabaseConfigurationResolver::load($this->legacyPath);
        if (array_key_exists('password', $configuration)) {
            $configuration['password'] = DatabaseCredentialResolver::resolve($configuration['password']);
        }
        return $configuration;
    }

    /**
     * Read-modify-write under the registry mutation lock. A V2 file is migrated
     * in the same write and retired afterwards.
     */
    private function mutate(callable $change): array
    {
        return $this->withMutationLock(function () use ($change): array {
            $encryption = $this->encryption();
            $source = $this->source();
            $document = match ($source) {
                self::SOURCE_REGISTRY => $this->storedDocument(),
                self::SOURCE_LEGACY => DatabaseRegistryMigrator::documentFromLegacyStored(
                    DatabaseConfigurationResolver::readStored($this->legacyPath),
                    $encryption
                ),
                default => self::emptyDocument(),
            };
            $document = $change($document, $encryption);
            $this->persist($document);
            if ($source === self::SOURCE_LEGACY) $this->retireLegacy();
            return $document;
        });
    }

    private function persist(array $document): void
    {
        $document = self::normalizeDocument($document);
        self::verifyDocument($document, $this->encryption());
        $stored = $document;
        foreach (['servers', 'databases'] as $section) {
            if ($stored[$section] === []) $stored[$section] = new stdClass();
        }
        JsonFileStore::save($this->registryPath, $stored);
        if (self::canonical($this->storedDocument()) !== self::canonical($document)) {
            throw new DatabaseCredentialException('Unable to verify the saved database configuration.');
        }
    }

    private function retireLegacy(): bool
    {
        return !is_file($this->legacyPath) || @unlink($this->legacyPath);
    }

    private function withMutationLock(callable $callback)
    {
        $directory = dirname($this->registryPath);
        if (!is_dir($directory)) throw new RuntimeException('Database configuration directory is unavailable.');
        $lockPath = $this->registryPath . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new RuntimeException('Database configuration lock is unavailable.');
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Database configuration lock could not be acquired.');
            return $callback();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function encryption(): DatabaseCredentialEncryption
    {
        return $this->encryption ??= new DatabaseCredentialEncryption();
    }

    private static function normalizeDocument(array $document): array
    {
        return [
            'version' => $document['version'],
            'defaultDatabase' => $document['defaultDatabase'],
            'servers' => $document['servers'],
            'databases' => $document['databases'],
        ];
    }

    private static function canonical(array $value): string
    {
        $sort = static function ($item) use (&$sort) {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function assertId(string $id, string $kind): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException("The {$kind} id must match ^[a-z][a-z0-9_-]{0,63}$.");
        }
    }

    private static function isValidName($name): bool
    {
        return is_string($name) && trim($name) === $name && $name !== ''
            && strlen($name) <= self::MAX_NAME_LENGTH && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1
            && preg_match('//u', $name) === 1;
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        if (!self::isValidName($name)) throw new InvalidArgumentException('The display name is invalid.');
        return $name;
    }
}
