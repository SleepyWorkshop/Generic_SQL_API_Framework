<?php

require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Database/DatabaseRegistry.php';
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
     * Discard the short-lived connection-check cache after an availability
     * change so the next detailed check reflects the new state.
     */
    public function forgetDatabaseHealth(): void
    {
        if (is_file($this->databaseCachePath)) @unlink($this->databaseCachePath);
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

    private function storedDatabaseAvailability(): bool
    {
        $state = JsonFileStore::load($this->configurationDirectory . '/database-state.json');
        if (!DatabaseAvailabilityManager::isValidState($state)) return false;
        if ($state['version'] === 1) return $state['available'] === true;
        $default = $this->registry->defaultDatabaseId() ?? DatabaseRegistry::DEFAULT_ID;
        return ($state['databases'][$default]['available'] ?? false) === true;
    }

    private function databaseHealth(): array
    {
        $ready = $this->databaseReadiness();
        if ($ready['status'] !== 'healthy' || !is_callable($this->databaseTester)) return $ready;
        $fingerprint = $this->registry->fingerprint();
        $cached = is_string($fingerprint) ? $this->readDatabaseCache($fingerprint) : null;
        if ($cached !== null) return $cached + ['cached' => true];
        $started = microtime(true);
        try {
            ($this->databaseTester)($this->registry->connectionConfiguration());
            $result = ['status' => 'healthy', 'category' => 'connected'];
        } catch (Throwable $exception) {
            $result = ['status' => 'unhealthy', 'category' => $this->databaseFailureCategory($exception)];
        }
        $result += ['cached' => false, 'checkedAt' => gmdate(DATE_ATOM),
            'durationMs' => round((microtime(true) - $started) * 1000, 2)];
        if (is_string($fingerprint)) $this->writeDatabaseCache($result + ['configurationFingerprint' => $fingerprint]);
        return $result;
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

    private function readDatabaseCache(string $fingerprint): ?array
    {
        if (!is_file($this->databaseCachePath)) return null;
        try { $value = JsonFileStore::load($this->databaseCachePath); }
        catch (Throwable $exception) { return null; }
        $checked = strtotime((string)($value['checkedAt'] ?? ''));
        if ($checked === false || time() - $checked > $this->databaseCacheTtl
            || !hash_equals($fingerprint, (string)($value['configurationFingerprint'] ?? ''))) return null;
        unset($value['configurationFingerprint']);
        return isset($value['status'], $value['category']) ? $value : null;
    }

    private function writeDatabaseCache(array $value): void
    {
        $directory = dirname($this->databaseCachePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        try { JsonFileStore::save($this->databaseCachePath, $value); } catch (Throwable $exception) {}
    }

    private function applicationVersion(): string
    {
        $config = require $this->root . '/config/app.php';
        return (string)($config['version'] ?? 'unknown');
    }
}
