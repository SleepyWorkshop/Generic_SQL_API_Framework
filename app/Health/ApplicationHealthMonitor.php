<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Database/DatabaseRegistry.php';
require_once __DIR__ . '/../Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../Database/DatabaseConnectionException.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../Repositories/ApiKeyRepository.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../Security/DatabaseTransportSecurity.php';

final class ApplicationHealthMonitor
{
    private string $root;
    private string $configurationDirectory;
    private string $databasePath;
    private DatabaseRegistry $registry;
    private string $runtimeDirectory;
    private string $logDirectory;
    private string $databaseCachePath;
    private int $databaseCacheTtl;
    private int $diskWarningBytes;
    private int $diskCriticalBytes;
    private $databaseTester;
    /** @var null|callable(DatabaseContext): array connects to a server profile; returns safe server facts */
    private $serverInspector;
    private $databaseAvailable;
    private $diskSpace;
    private ?bool $production;

    public function __construct(array $options = [])
    {
        $this->root = rtrim($options['root'] ?? dirname(__DIR__, 2), '/\\');
        $this->configurationDirectory = $options['configurationDirectory'] ?? RuntimeConfiguration::directory();
        $this->databasePath = $options['databasePath'] ?? $this->root . '/database/config/database.json';
        // databasePath is the V2 file; the V3 registry is stored beside it.
        $this->registry = $options['registry'] ?? DatabaseRegistry::forLegacyPath($this->databasePath);
        $this->runtimeDirectory = $options['runtimeDirectory'] ?? $this->root . '/runtime';
        $this->logDirectory = $options['logDirectory'] ?? $this->root . '/logs';
        $this->databaseCachePath = $options['databaseCachePath']
            ?? $this->runtimeDirectory . '/health/database-health.json';
        $this->databaseCacheTtl = max(1, (int)($options['databaseCacheTtl'] ?? 15));
        $this->diskWarningBytes = max(0, (int)($options['diskWarningBytes'] ?? 1073741824));
        $this->diskCriticalBytes = max(0, (int)($options['diskCriticalBytes'] ?? 268435456));
        $this->databaseTester = $options['databaseTester'] ?? null;
        $this->serverInspector = $options['serverInspector'] ?? null;
        $this->databaseAvailable = $options['databaseAvailable'] ?? null;
        $this->diskSpace = $options['diskSpace'] ?? static fn (string $path) => @disk_free_space($path);
        $this->production = isset($options['production']) ? (bool)$options['production'] : null;
    }

    public function liveness(string $service = 'api', ?int $port = null, ?string $startedAt = null): array
    {
        $started = is_string($startedAt) ? strtotime($startedAt) : false;
        return [
            'status' => 'healthy',
            'service' => $service,
            'version' => $this->applicationVersion(),
            'port' => $port,
            'startedAt' => $startedAt,
            'uptimeSeconds' => $started === false ? null : max(0, time() - $started),
        ];
    }

    /**
     * API readiness never opens a SQL connection: proxy probes must not create
     * SQL Server load. A recent detailed connectivity failure that is already
     * cached is honoured, and in production a disabled API runtime is not ready.
     */
    public function readiness(): array
    {
        $configuration = $this->configurationHealth();
        $runtime = $this->directoryHealth($this->runtimeDirectory, true);
        $application = $this->applicationReadiness();
        $database = $this->databaseReadiness();
        $ready = $configuration['status'] === 'healthy'
            && $runtime['status'] === 'healthy'
            && $application['status'] === 'healthy'
            && $database['status'] === 'healthy';
        return [
            'status' => $ready ? 'healthy' : 'unhealthy',
            'checks' => [
                'configuration' => $this->publicCheck($configuration),
                'runtime' => $this->publicCheck($runtime),
                'application' => $this->publicCheck($application),
                'database' => $this->publicCheck($database),
            ],
        ];
    }

    /**
     * Backup state is reported for operators but, as before, never changes the
     * overall System Health status or readiness: backups are not request serving.
     */
    public function detailed(array $processes, ?array $backup = null): array
    {
        $checks = [
            'application' => ['status' => 'healthy', 'category' => 'responding', 'version' => $this->applicationVersion()],
            'configuration' => $this->configurationHealth(),
            'database' => $this->withTransportWarnings($this->databaseHealth()),
            'logging' => $this->loggingHealth(),
            'encryption' => $this->encryptionHealth(),
            'processes' => ['status' => $this->processAggregate($processes), 'services' => $processes],
        ];
        $statuses = array_column($checks, 'status');
        $status = in_array('unhealthy', $statuses, true) ? 'unhealthy'
            : (in_array('degraded', $statuses, true) ? 'degraded' : 'healthy');
        if ($backup !== null) $checks['backup'] = $backup;
        return ['status' => $status, 'checks' => $checks];
    }

    public function restoreSafety(): array
    {
        $configuration = $this->configurationHealth();
        if ($configuration['status'] !== 'healthy') {
            return [
                'healthy' => false,
                'check' => 'configuration.' . ($configuration['component'] ?? 'unknown'),
                'errorCode' => $configuration['category'] === 'configuration_missing'
                    ? 'CONFIGURATION_MISSING' : 'CONFIGURATION_INVALID',
                'reason' => $configuration['category'],
            ];
        }
        $encryption = $this->encryptionHealth();
        if ($encryption['status'] !== 'healthy') {
            return [
                'healthy' => false,
                'check' => 'database.encryption',
                'errorCode' => $encryption['category'] === 'missing'
                    ? 'ENCRYPTION_KEY_MISSING' : 'DATABASE_CONFIG_INVALID',
                'reason' => $encryption['category'],
            ];
        }
        return [
            'healthy' => true,
            'check' => 'configuration_and_encryption',
            'errorCode' => null,
            'reason' => 'healthy',
        ];
    }

    private function configurationHealth(): array
    {
        $files = ['auth.json', 'installation.json', 'admin.json', 'authorization.json',
            'api-keys.json', 'database-state.json', 'application-runtime-state.json'];
        foreach ($files as $file) {
            $path = $this->configurationDirectory . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) return ['status' => 'unhealthy', 'category' => 'configuration_missing', 'component' => $file];
            try { $value = JsonFileStore::load($path); }
            catch (Throwable $exception) { return ['status' => 'unhealthy', 'category' => 'configuration_invalid', 'component' => $file]; }
            if (!$this->configurationShapeIsValid($file, $path, $value)) {
                return ['status' => 'unhealthy', 'category' => 'configuration_invalid', 'component' => $file];
            }
        }
        return ['status' => 'healthy', 'category' => 'configuration_valid'];
    }

    private function configurationShapeIsValid(string $file, string $path, array $value): bool
    {
        try {
            match ($file) {
                'auth.json' => (new AuthRepository($path))->validate($value),
                'installation.json' => (new InstallationRepository($path))->validate($value),
                'admin.json' => (new AdminConfigurationRepository($path))->validate($value),
                'authorization.json' => (new AuthorizationRepository($path))->validate($value),
                'api-keys.json' => (new ApiKeyRepository($path))->validate($value),
                'database-state.json' => DatabaseAvailabilityManager::isValidState($value)
                    ? null : throw new RuntimeException('invalid'),
                'application-runtime-state.json' => ($value['version'] ?? null) === 1
                    && $this->applicationRuntimeShapeIsValid($value) ? null : throw new RuntimeException('invalid'),
                default => throw new RuntimeException('invalid'),
            };
            return true;
        } catch (Throwable $exception) { return false; }
    }

    private function applicationRuntimeShapeIsValid(array $value): bool
    {
        if (!is_int($value['generation'] ?? null) || $value['generation'] < 0) return false;
        $services = $value['services'] ?? null;
        if (!is_array($services) || array_keys($services) !== ['api', 'sqlParser']) return false;
        foreach ($services as $runtime) {
            if (!is_array($runtime)
                || array_keys($runtime) !== ['enabled', 'updatedAt', 'reloadedAt']
                || !is_bool($runtime['enabled'] ?? null)) return false;
            foreach (['updatedAt', 'reloadedAt'] as $field) {
                $timestamp = $runtime[$field] ?? null;
                if ($timestamp !== null && (!is_string($timestamp) || strtotime($timestamp) === false)) return false;
            }
        }
        return true;
    }

    /**
     * Discard a database's short-lived connection-check cache (the default
     * database when null) after an availability change, so the next check
     * reflects the new state.
     */
    public function forgetDatabaseHealth(?string $databaseId = null): void
    {
        $path = $this->databaseCachePathFor($databaseId);
        if (is_file($path)) @unlink($path);
    }

    /**
     * One configured database (the default when null). Configured, enabled
     * (database and server profile), available (runtime gate), and reachable
     * are reported separately; disabled or disconnected databases are never
     * contacted. Without a tester nothing is contacted at all.
     */
    public function databaseStatus(?string $databaseId = null): array
    {
        try {
            $metadata = $this->registry->metadata();
        } catch (Throwable $exception) {
            return ['database' => $databaseId, 'configured' => false, 'enabled' => false, 'available' => false,
                'status' => 'unhealthy', 'category' => 'configuration_invalid'];
        }
        $databaseId ??= $metadata['defaultDatabase'];
        $database = $databaseId === null ? null : ($metadata['databases'][$databaseId] ?? null);
        if ($database === null) {
            return ['database' => $databaseId, 'configured' => false, 'enabled' => false, 'available' => false,
                'status' => 'not_configured', 'category' => $databaseId === null ? 'configuration_missing' : 'database_not_found'];
        }
        $serverEnabled = ($metadata['servers'][$database['server']]['enabled'] ?? false) === true;
        $enabled = $database['enabled'] && $serverEnabled;
        try {
            $available = $this->databaseAvailable($databaseId);
        } catch (Throwable $exception) {
            $available = false;
        }
        $status = ['database' => $databaseId, 'serverProfile' => $database['server'],
            'configured' => true, 'enabled' => $enabled, 'available' => $available];
        if (!$enabled) {
            return $status + ['status' => 'disabled', 'category' => $database['enabled'] ? 'server_profile_disabled' : 'database_disabled'];
        }
        if (!$available) return $status + ['status' => 'disconnected', 'category' => 'database_disconnected'];
        if (!is_callable($this->databaseTester)) return $status + ['status' => 'healthy', 'category' => 'database_available'];
        return $status + $this->connectivity($databaseId);
    }

    /**
     * One server profile, independent of its databases: `disabled` when the
     * profile is disabled (never contacted), else one cached connection to
     * the profile's `master` catalog. A database failure never marks the
     * server unhealthy; only a failed server connection does. Without an
     * inspector nothing is contacted (`unknown`). `$checkDisabled` lets an
     * explicit Admin test check a disabled profile's credentials.
     */
    public function serverStatus(string $serverId, bool $checkDisabled = false): array
    {
        try {
            $metadata = $this->registry->metadata();
        } catch (Throwable $exception) {
            return ['server' => $serverId, 'configured' => false, 'enabled' => false, 'status' => 'unhealthy', 'category' => 'configuration_invalid'];
        }
        $server = $metadata['servers'][$serverId] ?? null;
        if ($server === null) {
            return ['server' => $serverId, 'configured' => false, 'enabled' => false, 'status' => 'not_configured', 'category' => 'server_profile_not_found'];
        }
        $status = ['server' => $serverId, 'configured' => true, 'enabled' => $server['enabled']];
        if (!$server['enabled'] && !$checkDisabled) return $status + ['status' => 'disabled', 'category' => 'server_profile_disabled'];
        if (!is_callable($this->serverInspector)) return $status + ['status' => 'unknown', 'category' => 'not_checked'];
        $cachePath = dirname($this->databaseCachePath) . DIRECTORY_SEPARATOR . 'server-health.' . $serverId . '.json';
        $fingerprint = $this->registry->fingerprint();
        $cached = is_string($fingerprint) ? $this->readDatabaseCache($fingerprint, $cachePath) : null;
        if ($cached !== null) return $status + ['cached' => true] + $cached;
        $started = microtime(true);
        try {
            $facts = ($this->serverInspector)(self::serverContext($serverId, $server['name'], $this->registry->serverConnection($serverId)));
            $result = ['status' => 'healthy', 'category' => 'connected', 'serverInfo' => array_intersect_key((array)$facts,
                ['productVersion' => true, 'edition' => true, 'serverName' => true])];
        } catch (Throwable $exception) {
            $result = ['status' => 'unhealthy', 'category' => $this->databaseFailureCategory($exception)];
        }
        $result += ['cached' => false, 'checkedAt' => gmdate(DATE_ATOM), 'durationMs' => round((microtime(true) - $started) * 1000, 2)];
        if (is_string($fingerprint)) $this->writeDatabaseCache($result + ['configurationFingerprint' => $fingerprint], $cachePath);
        return $status + $result;
    }

    /** A connection target for a server profile itself: its `master` catalog. */
    public static function serverContext(string $serverId, string $name, array $connection): DatabaseContext
    {
        return new DatabaseContext('server-' . $serverId, $name, true, 'master', new DatabaseServerProfile($serverId, $name, true, $connection));
    }

    /** Discard a server profile's connection-check cache after a change. */
    public function forgetServerHealth(string $serverId): void
    {
        $path = dirname($this->databaseCachePath) . DIRECTORY_SEPARATOR . 'server-health.' . $serverId . '.json';
        if (is_file($path)) @unlink($path);
    }

    private function databaseReadiness(): array
    {
        try {
            $available = is_callable($this->databaseAvailable)
                ? (bool)($this->databaseAvailable)()
                : $this->storedDatabaseAvailability();
            if (!$available) {
                return ['status' => 'unhealthy', 'category' => 'database_disconnected'];
            }
            $this->registry->connectionConfiguration();
        } catch (Throwable $exception) {
            return ['status' => 'unhealthy', 'category' => $this->databaseConfigurationCategory()];
        }
        $fingerprint = $this->registry->fingerprint();
        $cached = is_string($fingerprint) ? $this->readDatabaseCache($fingerprint) : null;
        if ($cached !== null && $cached['status'] !== 'healthy') {
            return ['status' => 'unhealthy', 'category' => $cached['category']];
        }
        return ['status' => 'healthy', 'category' => 'database_available'];
    }

    private function applicationReadiness(): array
    {
        if (!($this->production ?? SecurityConfiguration::isProduction())) {
            return ['status' => 'healthy', 'category' => 'process_managed'];
        }
        try {
            $state = JsonFileStore::load($this->configurationDirectory . '/application-runtime-state.json');
            if (($state['version'] ?? null) !== 1 || !$this->applicationRuntimeShapeIsValid($state)) {
                return ['status' => 'unhealthy', 'category' => 'configuration_invalid'];
            }
        } catch (Throwable $exception) {
            return ['status' => 'unhealthy', 'category' => 'configuration_invalid'];
        }
        return $state['services']['api']['enabled']
            ? ['status' => 'healthy', 'category' => 'api_enabled']
            : ['status' => 'unhealthy', 'category' => 'api_disabled'];
    }

    private function storedDatabaseAvailability(?string $databaseId = null): bool
    {
        $state = JsonFileStore::load($this->configurationDirectory . '/database-state.json');
        if (!DatabaseAvailabilityManager::isValidState($state)) return false;
        $default = $this->registry->defaultDatabaseId() ?? DatabaseRegistry::DEFAULT_ID;
        $databaseId ??= $default;
        // A version 1 state described the single V2 database: the default.
        if ($state['version'] === 1) return $databaseId === $default && $state['available'] === true;
        return ($state['databases'][$databaseId]['available'] ?? false) === true;
    }

    private function databaseAvailable(?string $databaseId): bool
    {
        return is_callable($this->databaseAvailable)
            ? (bool)($this->databaseAvailable)($databaseId)
            : $this->storedDatabaseAvailability($databaseId);
    }

    private function databaseHealth(): array
    {
        $ready = $this->databaseReadiness();
        if ($ready['status'] !== 'healthy' || !is_callable($this->databaseTester)) return $ready;
        return $this->connectivity(null);
    }

    /**
     * One cached connection check through the resolver (the default database
     * when null). The cache is per database and keyed by the stored
     * configuration, so any registry change forces a new check.
     */
    private function connectivity(?string $databaseId): array
    {
        $cachePath = $this->databaseCachePathFor($databaseId);
        $fingerprint = $this->registry->fingerprint();
        $cached = is_string($fingerprint) ? $this->readDatabaseCache($fingerprint, $cachePath) : null;
        if ($cached !== null) return ['cached' => true] + $cached;
        $started = microtime(true);
        try {
            ($this->databaseTester)((new DatabaseContextResolver($this->registry))->resolve($databaseId)->driverConfiguration());
            $result = ['status' => 'healthy', 'category' => 'connected'];
        } catch (Throwable $exception) {
            $result = ['status' => 'unhealthy', 'category' => $this->databaseFailureCategory($exception)];
        }
        $result += ['cached' => false, 'checkedAt' => gmdate(DATE_ATOM),
            'durationMs' => round((microtime(true) - $started) * 1000, 2)];
        if (is_string($fingerprint)) $this->writeDatabaseCache($result + ['configurationFingerprint' => $fingerprint], $cachePath);
        return $result;
    }

    /** The default database keeps the V2 cache file; others get their own. */
    private function databaseCachePathFor(?string $databaseId): string
    {
        if ($databaseId === null || $databaseId === $this->registry->defaultDatabaseId() || !DatabaseRegistry::isValidId($databaseId)) {
            return $this->databaseCachePath;
        }
        return dirname($this->databaseCachePath) . DIRECTORY_SEPARATOR . 'database-health.' . $databaseId . '.json';
    }

    /**
     * Production reports weakened SQL Server transport settings as warnings.
     * They do not change the health status: the connection may still work.
     */
    private function withTransportWarnings(array $check): array
    {
        if (!($this->production ?? SecurityConfiguration::isProduction())
            || $this->registry->source() === DatabaseRegistry::SOURCE_NONE) return $check;
        try {
            $warnings = DatabaseTransportSecurity::warnings($this->registry->connectionConfiguration());
        } catch (Throwable $exception) {
            return $check;
        }
        return $warnings === [] ? $check : $check + ['warnings' => $warnings];
    }

    private function databaseConfigurationCategory(): string
    {
        $state = $this->registry->configurationState();
        return $state === 'configured' ? 'database_unavailable' : $state;
    }

    private function databaseFailureCategory(Throwable $exception): string
    {
        if ($exception instanceof DatabaseConnectionException) {
            return match ($exception->kind()) {
                DatabaseConnectionException::TIMEOUT => 'connection_timeout',
                DatabaseConnectionException::AUTHENTICATION => 'authentication_failure',
                default => 'database_unavailable',
            };
        }
        if ($exception instanceof DatabaseCredentialException) return 'configuration_invalid';
        if ($exception instanceof ApiRequestException) return strtolower($exception->getErrorCode());
        $message = strtoupper($exception->getMessage());
        return str_contains($message, '28000') || str_contains($message, 'LOGIN FAILED')
            ? 'authentication_failure' : 'database_unavailable';
    }

    private function encryptionHealth(): array
    {
        if (!DatabaseConfigurationResolver::encryptionKeyIsAvailable()) {
            return ['status' => 'unhealthy', 'category' => 'missing'];
        }
        try { new DatabaseCredentialEncryption(); }
        catch (Throwable $exception) { return ['status' => 'unhealthy', 'category' => 'key_invalid']; }
        if ($this->registry->source() === DatabaseRegistry::SOURCE_NONE) {
            return ['status' => 'unhealthy', 'category' => 'configuration_missing'];
        }
        // A wrong key, a tampered envelope, and an envelope moved to another
        // registry entry all fail AES-GCM authentication.
        try { $this->registry->verify(); }
        catch (Throwable $exception) { return ['status' => 'unhealthy', 'category' => 'invalid']; }
        return ['status' => 'healthy', 'category' => 'configured'];
    }

    private function loggingHealth(): array
    {
        $check = $this->directoryHealth($this->logDirectory, true);
        return ['status' => $check['status'] === 'healthy' ? 'healthy' : 'degraded',
            'category' => $check['status'] === 'healthy' ? 'operational' : 'unavailable'];
    }

    private function directoryHealth(string $path, bool $writable): array
    {
        if (!is_dir($path)) return ['status' => 'unhealthy', 'category' => 'missing'];
        if (!is_readable($path) || ($writable && !is_writable($path))) {
            return ['status' => 'unhealthy', 'category' => 'unavailable'];
        }
        $free = ($this->diskSpace)($path);
        if (is_int($free) || is_float($free)) {
            if ($this->diskCriticalBytes > 0 && $free < $this->diskCriticalBytes) {
                return ['status' => 'unhealthy', 'category' => 'disk_critical'];
            }
            if ($this->diskWarningBytes > 0 && $free < $this->diskWarningBytes) {
                return ['status' => 'degraded', 'category' => 'disk_warning'];
            }
        }
        return ['status' => 'healthy', 'category' => 'available'];
    }

    private function processAggregate(array $processes): string
    {
        foreach ($processes as $process) {
            if (($process['status'] ?? null) === 'unresponsive') return 'degraded';
        }
        return 'healthy';
    }

    private function publicCheck(array $check): array
    {
        return ['status' => $check['status'], 'category' => $check['category'] ?? 'unknown'];
    }

    private function readDatabaseCache(string $fingerprint, ?string $path = null): ?array
    {
        $path ??= $this->databaseCachePath;
        if (!is_file($path)) return null;
        try { $value = JsonFileStore::load($path); }
        catch (Throwable $exception) { return null; }
        $checked = strtotime((string)($value['checkedAt'] ?? ''));
        if ($checked === false || time() - $checked > $this->databaseCacheTtl
            || !hash_equals($fingerprint, (string)($value['configurationFingerprint'] ?? ''))) return null;
        unset($value['configurationFingerprint']);
        return isset($value['status'], $value['category']) ? $value : null;
    }

    private function writeDatabaseCache(array $value, ?string $path = null): void
    {
        $path ??= $this->databaseCachePath;
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        try { JsonFileStore::save($path, $value); } catch (Throwable $exception) {}
    }

    private function applicationVersion(): string
    {
        $config = require $this->root . '/config/app.php';
        return (string)($config['version'] ?? 'unknown');
    }
}
