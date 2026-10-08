<?php

require_once __DIR__ . '/../Database/DatabaseRegistry.php';
require_once __DIR__ . '/../Database/DatabaseContext.php';
require_once __DIR__ . '/../Database/DatabaseServerProfile.php';
require_once __DIR__ . '/../Database/DatabaseConnectionException.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Runtime/DatabaseAuthenticationSupport.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/../Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Security/DatabaseCredentialException.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

/**
 * Admin Console management of server profiles and database contexts.
 *
 * The registry is the only store: every change goes through its mutations,
 * which keep it valid (an enabled default database on an enabled profile;
 * no profile deleted while it hosts databases). Changes that would break
 * those rules are refused with a clear error before the registry is touched.
 * Responses never contain passwords, connection strings, encryption
 * envelopes, or keys. Connectivity results never change the registry.
 */
final class DatabaseAdministrationService
{
    /** @var callable(string): array opens and closes one connection to a configured, enabled database */
    private $testDatabase;
    /** @var callable(string, string): array opens or closes a database's availability gate */
    private $controlDatabase;

    public function __construct(
        private DatabaseRegistry $registry,
        private DatabaseAvailabilityManager $availability,
        private ApplicationHealthMonitor $health,
        callable $testDatabase,
        callable $controlDatabase,
        private ?Logger $logger = null
    ) {
        $this->testDatabase = $testDatabase;
        $this->controlDatabase = $controlDatabase;
        $this->logger ??= new Logger();
    }

    public function dispatch(array $request): array
    {
        $id = $request['id'] ?? null;
        return match ($request['action']) {
            'admin.servers.list' => ['servers' => $this->servers(),
                'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
                'availableAuthenticationModes' => (new DatabaseAuthenticationSupport())->modes()],
            'admin.servers.save' => $this->saveServer($request['server']),
            'admin.servers.enable' => $this->setServerEnabled($id, true),
            'admin.servers.disable' => $this->setServerEnabled($id, false),
            'admin.servers.delete' => $this->deleteServer($id),
            'admin.servers.test' => $this->testServer($id),
            'admin.databases.list' => ['defaultDatabase' => $this->metadata()['defaultDatabase'], 'databases' => $this->databases()],
            'admin.databases.save' => $this->saveDatabase($request['database']),
            'admin.databases.enable' => $this->setDatabaseEnabled($id, true),
            'admin.databases.disable' => $this->setDatabaseEnabled($id, false),
            'admin.databases.default' => $this->setDefaultDatabase($id),
            'admin.databases.delete' => $this->deleteDatabase($id),
            'admin.databases.test' => $this->testDatabase($id),
            'admin.databases.connect' => ($this->controlDatabase)('connect', $this->existingDatabase($id)),
            'admin.databases.disconnect' => ($this->controlDatabase)('disconnect', $this->existingDatabase($id)),
            'admin.databases.health' => $this->healthTree(),
            default => throw new LogicException('Unsupported database administration action.'),
        };
    }

    /** Server profiles with their safe connection settings; the password is reported only as configured. */
    public function servers(): array
    {
        $metadata = $this->metadata();
        $servers = [];
        foreach ($metadata['servers'] as $id => $server) {
            $id = (string)$id;
            $databases = array_keys(array_filter($metadata['databases'], fn (array $database): bool => $database['server'] === $id));
            $entry = ['id' => $id, 'name' => $server['name'], 'enabled' => $server['enabled'],
                'databaseCount' => count($databases), 'databases' => array_map('strval', $databases)];
            try {
                $connection = $this->registry->serverConnection($id);
                $entry += ['readable' => true, 'connection' => [
                    'provider' => $connection['provider'] ?? 'sqlserver',
                    'driver' => $connection['driver'] ?? 'auto',
                    'server' => (string)($connection['server'] ?? ''),
                    'port' => isset($connection['port']) && $connection['port'] !== '' ? (string)$connection['port'] : '',
                    'authentication' => $connection['authentication'] ?? 'sql',
                    'username' => (string)($connection['username'] ?? ''),
                    'passwordConfigured' => (string)($connection['password'] ?? '') !== '',
                    'encrypt' => ($connection['options']['encrypt'] ?? true) === true,
                    'trustServerCertificate' => ($connection['options']['trustServerCertificate'] ?? false) === true,
                    'loginTimeoutSeconds' => DatabaseServerProfile::loginTimeoutFrom($connection),
                ]];
            } catch (Throwable $exception) {
                $entry += ['readable' => false];
            }
            $servers[] = $entry;
        }
        usort($servers, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $servers;
    }

    /**
     * Database contexts: `enabled` is the database's own flag, `usable` also
     * requires its server profile, and `available` is its runtime gate.
     */
    public function databases(): array
    {
        $metadata = $this->metadata();
        $databases = [];
        foreach ($metadata['databases'] as $id => $database) {
            $id = (string)$id;
            $serverEnabled = ($metadata['servers'][$database['server']]['enabled'] ?? false) === true;
            $entry = ['id' => $id, 'name' => $database['name'], 'server' => $database['server'],
                'serverName' => $metadata['servers'][$database['server']]['name'] ?? null,
                'enabled' => $database['enabled'], 'serverEnabled' => $serverEnabled, 'usable' => $database['enabled'] && $serverEnabled,
                'default' => $id === $metadata['defaultDatabase'], 'available' => $this->available($id)];
            try {
                $entry['catalog'] = $this->registry->databaseCatalog($id);
            } catch (Throwable $exception) {
                $entry['catalog'] = null;
            }
            $databases[] = $entry;
        }
        usort($databases, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $databases;
    }

    /** Create or update a server profile. A blank password keeps the stored one. */
    public function saveServer(array $server): array
    {
        $metadata = $this->metadata();
        $existing = $metadata['servers'][$server['id']] ?? null;
        $connection = $server['connection'];
        if ((string)($connection['password'] ?? '') === '') {
            $connection['password'] = '';
            if ($existing !== null) {
                try {
                    $connection['password'] = (string)($this->registry->serverConnection($server['id'])['password'] ?? '');
                } catch (Throwable $exception) {
                    // A replacement password can still be saved when the old one cannot be read.
                }
            }
        }
        if ($connection['authentication'] === 'sql' && $connection['password'] === '') {
            throw self::invalid('server.password', 'Password is required for SQL authentication.');
        }
        if (!array_key_exists('loginTimeoutSeconds', $connection['options']) && $existing !== null) {
            try {
                $stored = $this->registry->serverConnection($server['id'])['options']['loginTimeoutSeconds'] ?? null;
                if (DatabaseServerProfile::isValidLoginTimeout($stored)) $connection['options']['loginTimeoutSeconds'] = $stored;
            } catch (Throwable $exception) {}
        }
        if (!$server['enabled'] && $this->hostsDefault($server['id'], $metadata)) throw self::hostsDefaultError();
        $this->mutate(fn () => $this->registry->saveServer($server['id'], $server['name'], $server['enabled'], $connection),
            $existing === null ? 'server_created' : 'server_updated');
        $this->health->forgetServerHealth($server['id']);
        return ['server' => $this->serverEntry($server['id'])];
    }

    public function setServerEnabled(string $id, bool $enabled): array
    {
        $metadata = $this->metadata();
        if (!isset($metadata['servers'][$id])) throw self::serverNotFound();
        if (!$enabled && $this->hostsDefault($id, $metadata)) throw self::hostsDefaultError();
        $connection = $this->readableServerConnection($id);
        $this->mutate(fn () => $this->registry->saveServer($id, $metadata['servers'][$id]['name'], $enabled, $connection),
            $enabled ? 'server_enabled' : 'server_disabled');
        $this->health->forgetServerHealth($id);
        return ['server' => $this->serverEntry($id)];
    }

    public function deleteServer(string $id): array
    {
        $metadata = $this->metadata();
        if (!isset($metadata['servers'][$id])) throw self::serverNotFound();
        $hosted = array_keys(array_filter($metadata['databases'], fn (array $database): bool => $database['server'] === $id));
        if ($hosted !== []) {
            throw new ApiRequestException('The server profile still hosts databases.', 'SERVER_PROFILE_IN_USE', [
                ['path' => 'id', 'message' => 'Move or delete its databases first: ' . implode(', ', array_map('strval', $hosted)) . '.'],
            ], 409);
        }
        $this->mutate(fn () => $this->registry->deleteServer($id), 'server_deleted');
        $this->health->forgetServerHealth($id);
        return ['deleted' => true, 'id' => $id];
    }

    /**
     * Connect to the profile itself (its `master` catalog), without choosing a
     * database, and report safe server facts. Nothing is changed.
     */
    public function testServer(string $id): array
    {
        $metadata = $this->metadata();
        if (!isset($metadata['servers'][$id])) throw self::serverNotFound();
        $this->health->forgetServerHealth($id);
        // Health never contacts a disabled profile; an explicit test still checks its credentials.
        $status = $this->health->serverStatus($id, true);
        $this->health->forgetServerHealth($id);
        if ($status['status'] !== 'healthy') {
            $this->logger->audit('database.server_test', 'failure', 'WARNING', ['component' => 'database', 'reason' => $status['category']]);
            throw match ($status['category']) {
                'connection_timeout' => new ApiRequestException('Database connection timed out.', 'DATABASE_CONNECTION_TIMEOUT', [], 504),
                'configuration_invalid' => new ApiRequestException('Database configuration is unavailable.', 'DATABASE_CONFIGURATION_UNAVAILABLE', [], 503),
                default => new ApiRequestException('Database connection failed.', 'DATABASE_CONNECTION_FAILED', [], 422),
            };
        }
        $this->logger->audit('database.server_test', 'success', 'INFO', ['component' => 'database']);
        return ['connected' => true, 'server' => $id, 'crossDatabaseQueries' => true] + ($status['serverInfo'] ?? []);
    }

    /** Create or update a database context; its registry rules are checked first. */
    public function saveDatabase(array $database): array
    {
        $metadata = $this->metadata();
        $server = $metadata['servers'][$database['server']] ?? null;
        if ($server === null) {
            throw new ApiRequestException('Database server profile not found.', 'SERVER_PROFILE_NOT_FOUND', [
                ['path' => 'database.server', 'message' => 'Choose an existing server profile.'],
            ], 404);
        }
        $isDefault = $metadata['defaultDatabase'] === $database['id'];
        $becomesDefault = $metadata['defaultDatabase'] === null;
        if (($isDefault || $becomesDefault) && (!$database['enabled'] || !$server['enabled'])) {
            throw new ApiRequestException('The default database must stay enabled on an enabled server profile.', 'DEFAULT_DATABASE_REQUIRED', [
                ['path' => 'database.enabled', 'message' => $becomesDefault
                    ? 'The first database becomes the default and must be enabled on an enabled server profile.'
                    : 'Choose another default database first.'],
            ], 409);
        }
        $created = !isset($metadata['databases'][$database['id']]);
        $this->mutate(fn () => $this->registry->saveDatabase($database['id'], $database['name'], $database['server'], $database['enabled'], $database['catalog']),
            $created ? 'database_created' : 'database_updated');
        $this->health->forgetDatabaseHealth($database['id']);
        return ['database' => $this->databaseEntry($database['id'])];
    }

    public function setDatabaseEnabled(string $id, bool $enabled): array
    {
        $metadata = $this->metadata();
        $database = $metadata['databases'][$id] ?? null;
        if ($database === null) throw self::databaseNotFound();
        if (!$enabled && $metadata['defaultDatabase'] === $id) {
            throw new ApiRequestException('The default database cannot be disabled.', 'DEFAULT_DATABASE_REQUIRED', [
                ['path' => 'id', 'message' => 'Choose another default database first.'],
            ], 409);
        }
        $catalog = $this->readableCatalog($id);
        $this->mutate(fn () => $this->registry->saveDatabase($id, $database['name'], $database['server'], $enabled, $catalog),
            $enabled ? 'database_enabled' : 'database_disabled');
        $this->health->forgetDatabaseHealth($id);
        return ['database' => $this->databaseEntry($id)];
    }

    public function setDefaultDatabase(string $id): array
    {
        $metadata = $this->metadata();
        $database = $metadata['databases'][$id] ?? null;
        if ($database === null) throw self::databaseNotFound();
        if (!$database['enabled'] || !($metadata['servers'][$database['server']]['enabled'] ?? false)) {
            throw new ApiRequestException('The default database must be enabled on an enabled server profile.', 'DEFAULT_DATABASE_REQUIRED', [
                ['path' => 'id', 'message' => 'Enable the database and its server profile first.'],
            ], 409);
        }
        $this->mutate(fn () => $this->registry->setDefaultDatabase($id), 'default_database_changed');
        return ['defaultDatabase' => $id];
    }

    public function deleteDatabase(string $id): array
    {
        $metadata = $this->metadata();
        if (!isset($metadata['databases'][$id])) throw self::databaseNotFound();
        if ($metadata['defaultDatabase'] === $id) {
            throw new ApiRequestException('The default database cannot be deleted.', 'DEFAULT_DATABASE_REQUIRED', [
                ['path' => 'id', 'message' => 'Choose another default database first.'],
            ], 409);
        }
        $this->mutate(fn () => $this->registry->deleteDatabase($id), 'database_deleted');
        try {
            $this->availability->forget($id);
        } catch (Throwable $exception) {}
        $this->health->forgetDatabaseHealth($id);
        return ['deleted' => true, 'id' => $id];
    }

    /** Open and close one connection to a configured, enabled database; its state is not changed. */
    public function testDatabase(string $id): array
    {
        $this->existingDatabase($id);
        return ($this->testDatabase)($id) + ['database' => $id];
    }

    /**
     * System → server profiles → databases. Each profile's own connection
     * status is reported apart from its databases': a database failure never
     * marks its server unhealthy. Checks are cached briefly; disabled and
     * disconnected items are not contacted.
     */
    public function healthTree(): array
    {
        $servers = [];
        foreach ($this->servers() as $server) {
            $databases = [];
            foreach ($server['databases'] as $databaseId) {
                $status = $this->health->databaseStatus($databaseId);
                $databases[] = ['id' => $databaseId, 'name' => $this->metadata()['databases'][$databaseId]['name']] + array_intersect_key($status,
                    ['configured' => true, 'enabled' => true, 'available' => true, 'status' => true, 'category' => true, 'cached' => true, 'checkedAt' => true]);
            }
            $serverStatus = $this->health->serverStatus($server['id']);
            $servers[] = ['id' => $server['id'], 'name' => $server['name'], 'enabled' => $server['enabled']]
                + array_intersect_key($serverStatus, ['status' => true, 'category' => true, 'cached' => true, 'checkedAt' => true, 'serverInfo' => true])
                + ['databases' => $databases];
        }
        return ['defaultDatabase' => $this->metadata()['defaultDatabase'], 'servers' => $servers];
    }

    private function serverEntry(string $id): array
    {
        foreach ($this->servers() as $server) if ($server['id'] === $id) return $server;
        throw self::serverNotFound();
    }

    private function databaseEntry(string $id): array
    {
        foreach ($this->databases() as $database) if ($database['id'] === $id) return $database;
        throw self::databaseNotFound();
    }

    private function existingDatabase(string $id): string
    {
        if (!isset($this->metadata()['databases'][$id])) throw self::databaseNotFound();
        return $id;
    }

    private function hostsDefault(string $serverId, array $metadata): bool
    {
        $default = $metadata['defaultDatabase'];
        return $default !== null && ($metadata['databases'][$default]['server'] ?? null) === $serverId;
    }

    private function readableServerConnection(string $id): array
    {
        try {
            return $this->registry->serverConnection($id);
        } catch (Throwable $exception) {
            throw self::configurationUnavailable();
        }
    }

    private function readableCatalog(string $id): string
    {
        try {
            return $this->registry->databaseCatalog($id);
        } catch (Throwable $exception) {
            throw self::configurationUnavailable();
        }
    }

    private function available(string $id): bool
    {
        try {
            return $this->availability->available($id);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function metadata(): array
    {
        try {
            return $this->registry->metadata();
        } catch (Throwable $exception) {
            throw self::configurationUnavailable();
        }
    }

    /** Run one registry mutation, mapping its failures to safe Admin errors, and audit it. */
    private function mutate(callable $mutation, string $reason): void
    {
        try {
            $mutation();
        } catch (InvalidArgumentException $exception) {
            $this->audit('failure', $reason);
            throw self::invalid('', $exception->getMessage());
        } catch (DatabaseCredentialException $exception) {
            $this->audit('failure', $reason);
            throw getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE) === false || getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE) === ''
                ? new ApiRequestException('Database encryption is unavailable.', 'DATABASE_ENCRYPTION_UNAVAILABLE', [], 503)
                : new ApiRequestException('The change would leave the database registry invalid.', 'DATABASE_REGISTRY_INVALID', [], 409);
        } catch (Throwable $exception) {
            $this->audit('failure', $reason);
            throw new ApiRequestException('Unable to save database configuration.', 'DATABASE_CONFIGURATION_SAVE_FAILED', [], 500);
        }
        $this->audit('success', $reason);
    }

    private function audit(string $outcome, string $reason): void
    {
        $this->logger->audit('configuration.database', $outcome, $outcome === 'success' ? 'NOTICE' : 'ERROR', [
            'configurationCategory' => 'database', 'reason' => $reason, 'component' => 'admin',
        ]);
        (new OperationalLogger())->info('admin', 'Database registry change', ['reason' => $reason, 'outcome' => $outcome]);
    }

    private static function invalid(string $path, string $message): ApiRequestException
    {
        return new ApiRequestException('Invalid admin request.', 'INVALID_ADMIN_REQUEST', [['path' => $path, 'message' => $message]]);
    }

    private static function hostsDefaultError(): ApiRequestException
    {
        return new ApiRequestException('The server profile hosts the default database.', 'DEFAULT_DATABASE_REQUIRED', [
            ['path' => 'id', 'message' => 'Choose a default database on another enabled server profile first.'],
        ], 409);
    }

    private static function serverNotFound(): ApiRequestException
    {
        return new ApiRequestException('Database server profile not found.', 'SERVER_PROFILE_NOT_FOUND', [
            ['path' => 'id', 'message' => 'The server profile is not configured.'],
        ], 404);
    }

    private static function databaseNotFound(): ApiRequestException
    {
        return new ApiRequestException('Database not found.', 'DATABASE_NOT_FOUND', [
            ['path' => 'id', 'message' => 'The database is not configured.'],
        ], 404);
    }

    private static function configurationUnavailable(): ApiRequestException
    {
        return new ApiRequestException('Database configuration is unavailable.', 'DATABASE_CONFIGURATION_UNAVAILABLE', [], 503);
    }
}
