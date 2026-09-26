<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/ApiKeyService.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../Runtime/SqlParserProcessManager.php';
require_once __DIR__ . '/../Runtime/DatabaseAuthenticationSupport.php';
require_once __DIR__ . '/../Runtime/RuntimeDetector.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Runtime/ApplicationRuntimeManager.php';
require_once __DIR__ . '/../Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../Backup/ConfigurationMutationBackup.php';
require_once __DIR__ . '/../Backup/BackupRecoveryService.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

final class AdminService
{
    private AdminConfigurationRepository $configuration;
    private string $databasePath;
    private bool $runtimeDatabasePath;
    private $connectionTester;
    private ApiProcessManager $processManager;
    private SqlParserProcessManager $parserProcessManager;
    private RuntimeDetector $runtimeDetector;
    private DatabaseAuthenticationSupport $databaseAuthentication;
    private DatabaseAvailabilityManager $databaseAvailability;
    private ApplicationRuntimeManager $applicationRuntime;
    private Logger $logger;
    private ApplicationHealthMonitor $healthMonitor;
    private BackupRecoveryService $backupRecovery;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?string $databasePath = null,
        ?callable $connectionTester = null,
        ?ApiProcessManager $processManager = null,
        ?RuntimeDetector $runtimeDetector = null,
        ?SqlParserProcessManager $parserProcessManager = null,
        ?DatabaseAuthenticationSupport $databaseAuthentication = null,
        ?DatabaseAvailabilityManager $databaseAvailability = null,
        ?Logger $logger = null,
        ?ApplicationHealthMonitor $healthMonitor = null,
        ?ApplicationRuntimeManager $applicationRuntime = null,
        ?BackupRecoveryService $backupRecovery = null
    ) {
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
        $this->runtimeDatabasePath = $databasePath === null;
        $this->databasePath = $databasePath
            ?? dirname(__DIR__, 2) . '/database/config/database.json';
        $this->connectionTester = $connectionTester ?? function (array $database): void {
            $driver = new SqlServerDriver($database);
            try {
                $driver->connect();
            } finally {
                $driver->disconnect();
            }
        };
        $this->processManager = $processManager ?? new ApiProcessManager($this->configuration);
        $this->runtimeDetector = $runtimeDetector ?? new RuntimeDetector();
        $this->parserProcessManager = $parserProcessManager ?? new SqlParserProcessManager($this->configuration);
        $this->databaseAuthentication = $databaseAuthentication ?? new DatabaseAuthenticationSupport();
        $this->databaseAvailability = $databaseAvailability ?? new DatabaseAvailabilityManager();
        $this->applicationRuntime = $applicationRuntime ?? new ApplicationRuntimeManager();
        $this->logger = $logger ?? new Logger();
        $this->healthMonitor = $healthMonitor ?? new ApplicationHealthMonitor([
            'databasePath' => $this->databasePath,
            'databaseAvailable' => fn (): bool => $this->databaseAvailability->available(),
            'databaseTester' => $this->connectionTester,
        ]);
        $this->backupRecovery = $backupRecovery ?? new BackupRecoveryService();
    }

    public function status(): array
    {
        $databaseAvailable = $this->databaseAvailability->available();
        $api = SecurityConfiguration::isProduction()
            ? $this->applicationRuntime->status('api') : $this->processManager->status();
        $parser = SecurityConfiguration::isProduction()
            ? $this->applicationRuntime->status('sqlParser') : $this->parserProcessManager->status();
        $admin = [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'pid' => getmypid(),
                'port' => isset($_SERVER['SERVER_PORT']) && filter_var($_SERVER['SERVER_PORT'], FILTER_VALIDATE_INT) !== false
                    ? (int)$_SERVER['SERVER_PORT']
                    : null,
                'startedAt' => getenv('GENERIC_ADMIN_STARTED_AT') ?: null,
            ];
        $monitoring = $this->healthMonitor->detailed([
            'adminConsole' => $admin,
            'api' => $api,
            'sqlParser' => $parser,
        ]);
        $databaseCheck = $monitoring['checks']['database'];
        $databaseConnected = $databaseAvailable && $databaseCheck['status'] === 'healthy';
        $database = [...$this->databaseStatus(),
            'connected' => $databaseConnected,
            'healthy' => $databaseConnected,
            'status' => $databaseConnected ? 'connected'
                : ($databaseAvailable ? $databaseCheck['category'] : 'disconnected'),
            'available' => $databaseAvailable,
        ];
        return [
            'adminConsole' => $admin,
            'api' => $api,
            'sqlParser' => $parser,
            'database' => $database,
            'phpRuntime' => [
                'running' => true,
                'healthy' => true,
                'status' => 'healthy',
                'phpVersion' => PHP_VERSION,
                'odbcAvailable' => extension_loaded('odbc'),
                'opensslAvailable' => extension_loaded('openssl'),
            ],
            'monitoring' => $monitoring,
        ];
    }

    public function systemInformation(): array
    {
        $runtime = $this->runtimeDetector->information();
        $installation = (new InstallationRepository())->load();
        $api = SecurityConfiguration::isProduction()
            ? $this->applicationRuntime->status('api') : $this->processManager->status();
        $parser = SecurityConfiguration::isProduction()
            ? $this->applicationRuntime->status('sqlParser') : $this->parserProcessManager->status();
        $databaseAvailable = $this->databaseAvailability->available();
        $database = $databaseAvailable ? $this->databaseHealth() : ['status' => 'disconnected'];
        return [
            'application' => 'Generic SQL API Framework',
            'status' => 'running',
            'platform' => $runtime['operatingSystem'] . ' ' . $runtime['architecture'],
            'phpRuntime' => $runtime['phpRuntime'] . ' · PHP ' . $runtime['phpVersion'],
            'configurationStatus' => $installation['initialized'] ? 'initialized' : 'setup required',
            'databaseStatus' => $database['status'] ?? 'unknown',
            'services' => [
                'adminConsole' => 'running',
                'api' => $api['status'],
                'sqlParser' => $parser['status'],
            ],
        ];
    }

    public function databaseConfiguration(): array
    {
        if (!is_file($this->databasePath)) {
            return [
                'configured' => false,
                'encrypted' => false,
                'provider' => 'sqlserver',
                'driver' => 'auto',
                'server' => '',
                'port' => '1433',
                'database' => '',
                'authentication' => 'sql',
                'username' => '',
                'passwordConfigured' => false,
                'encrypt' => true,
                'trustServerCertificate' => false,
                'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
                'availableAuthenticationModes' => $this->databaseAuthentication->modes(),
            ];
        }
        try {
            $stored = DatabaseConfigurationResolver::readStored($this->databasePath);
            $database = DatabaseConfigurationResolver::resolve($stored);
        } catch (Throwable $exception) {
            $this->logger->audit('database.connection_test', 'failure', 'WARNING', [
                'reason' => 'connection_failed', 'component' => 'database',
            ]);
            throw new ApiRequestException(
                'Database configuration is unavailable.',
                'DATABASE_CONFIGURATION_UNAVAILABLE',
                [],
                503
            );
        }
        return [
            'configured' => true,
            'encrypted' => DatabaseConfigurationResolver::usesEncryption($stored),
            'provider' => $database['provider'],
            'driver' => $database['driver'] ?? 'auto',
            'server' => $database['server'] ?? '',
            'port' => isset($database['port']) ? (string)$database['port'] : '',
            'database' => $database['database'] ?? '',
            'authentication' => $database['authentication'] ?? 'sql',
            'username' => $database['username'] ?? '',
            'passwordConfigured' => (string)($database['password'] ?? '') !== '',
            'encrypt' => ($database['options']['encrypt'] ?? true) === true,
            'trustServerCertificate' => ($database['options']['trustServerCertificate'] ?? false) === true,
            'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
            'availableAuthenticationModes' => $this->databaseAuthentication->modes(),
        ];
    }

    public function testDatabase(array $database): array
    {
        (new OperationalLogger())->info('database', 'Database connection test started');
        $resolved = $this->withExistingPassword($database);
        $this->validateDatabaseAuthentication($resolved);
        try {
            ($this->connectionTester)($resolved);
        } catch (Throwable $exception) {
            (new OperationalLogger())->error('database', 'Database connection test failed', [
                'error_code' => 'DATABASE_CONNECTION_FAILED',
            ]);
            throw new ApiRequestException(
                'Database connection failed.',
                'DATABASE_CONNECTION_FAILED',
                [],
                422
            );
        }
        $this->logger->audit('database.connection_test', 'success', 'INFO', ['component' => 'database']);
        (new OperationalLogger())->info('database', 'Database connection test successful');
        return ['connected' => true];
    }

    public function testCurrentDatabase(): array
    {
        (new OperationalLogger())->info('database', 'Database connection test started');
        try {
            $database = DatabaseConfigurationResolver::load($this->databasePath);
        } catch (Throwable $exception) {
            (new OperationalLogger())->error('database', 'Database configuration load failed', [
                'error_code' => 'DATABASE_CONFIGURATION_UNAVAILABLE',
            ]);
            throw new ApiRequestException(
                'Database configuration is unavailable.',
                'DATABASE_CONFIGURATION_UNAVAILABLE',
                [],
                503
            );
        }
        $this->validateDatabaseAuthentication($database);
        try {
            ($this->connectionTester)($database);
        } catch (Throwable $exception) {
            (new OperationalLogger())->error('database', 'Database connection test failed', [
                'error_code' => 'DATABASE_CONNECTION_FAILED',
            ]);
            $this->logger->audit('database.connection_test', 'failure', 'WARNING', [
                'reason' => 'connection_failed', 'component' => 'database',
            ]);
            throw new ApiRequestException('Database connection failed.', 'DATABASE_CONNECTION_FAILED', [], 422);
        }
        $this->logger->audit('database.connection_test', 'success', 'INFO', ['component' => 'database']);
        (new OperationalLogger())->info('database', 'Database connection test successful');
        return ['connected' => true];
    }

    public function saveDatabase(array $database): array
    {
        $resolved = $this->withExistingPassword($database);
        $this->validateDatabaseAuthentication($resolved);
        if ($resolved['authentication'] === 'sql' && $resolved['password'] === '') {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'database.password', 'message' => 'Password is required for SQL authentication.']]
            );
        }
        try {
            $encrypted = (new DatabaseCredentialEncryption())->encryptConfiguration($resolved);
            if (DatabaseConfigurationResolver::resolve($encrypted) !== $resolved) {
                throw new RuntimeException('Encrypted database configuration verification failed.');
            }
            if ($this->runtimeDatabasePath) ConfigurationMutationBackup::before('database_configuration');
            JsonFileStore::save($this->databasePath, $encrypted);
        } catch (DatabaseCredentialException $exception) {
            $this->logger->audit('configuration.database', 'failure', 'ERROR', [
                'configurationCategory' => 'database', 'reason' => 'encryption_unavailable', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Database encryption is unavailable.',
                'DATABASE_ENCRYPTION_UNAVAILABLE',
                [],
                503
            );
        } catch (Throwable $exception) {
            $this->logger->audit('configuration.database', 'failure', 'ERROR', [
                'configurationCategory' => 'database', 'reason' => 'save_failed', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Unable to save database configuration.',
                'DATABASE_CONFIGURATION_SAVE_FAILED',
                [],
                500
            );
        }
        $this->logger->audit('configuration.database', 'success', 'NOTICE', [
            'configurationCategory' => 'database',
            'reason' => $resolved['password'] !== '' ? 'password_configured' : 'integrated_authentication',
            'component' => 'admin',
        ]);
        return ['configured' => true, 'encrypted' => true, 'passwordConfigured' => $resolved['password'] !== ''];
    }

    public function settings(): array
    {
        $settings = $this->configuration->load();
        return [
            'hostingMode' => SecurityConfiguration::isProduction() ? 'production' : 'development',
            'server' => $settings['server'],
            'cors' => $settings['cors'],
            'authentication' => [
                'mode' => $settings['authentication']['mode'],
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured() || (new ApiKeyService())->configured(),
            ],
            'runtime' => $settings['runtime'],
            'security' => [
                'csrfEnabled' => true,
                'session' => SecurityConfiguration::sessionOptions(),
            ],
            'advanced' => [
                'debugMode' => $this->runtimeDetector->information()['debugMode'],
                'logging' => 'server-managed',
            ],
        ];
    }

    public function saveServer(array $server): array
    {
        $currentServer = $this->configuration->load()['server'];
        if (!SecurityConfiguration::isProduction()) {
            $runtime = $this->processManager->status();
            $parserRuntime = $this->parserProcessManager->status();
            if (($runtime['running'] ?? false) === true && ($runtime['port'] ?? null) === $server['adminPort']) {
                throw new ApiRequestException(
                    'Invalid admin request.',
                    'INVALID_ADMIN_REQUEST',
                    [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running API port.']]
                );
            }
            if (($parserRuntime['running'] ?? false) === true && ($parserRuntime['port'] ?? null) === $server['adminPort']) {
                throw new ApiRequestException(
                    'Invalid admin request.',
                    'INVALID_ADMIN_REQUEST',
                    [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running SQL Parser port.']]
                );
            }
        }
        $apiRestartRequired = $server['apiPortMinimum'] !== $currentServer['apiPortMinimum']
            || $server['apiPortMaximum'] !== $currentServer['apiPortMaximum'];
        $parserRestartRequired = $server['parserPortMinimum'] !== $currentServer['parserPortMinimum']
            || $server['parserPortMaximum'] !== $currentServer['parserPortMaximum'];
        $adminRestartRequired = $server['adminPort'] !== $currentServer['adminPort'];
        $result = $this->configuration->update(function (array &$settings) use (
            $server,
            $apiRestartRequired,
            $parserRestartRequired,
            $adminRestartRequired
        ): array {
            $settings['server'] = $server;
            return [
                'server' => $server,
                'apiRestartRequired' => $apiRestartRequired,
                'parserRestartRequired' => $parserRestartRequired,
                'adminRestartRequired' => $adminRestartRequired,
            ];
        });
        $this->configurationAudit('server');
        return $result;
    }

    public function saveCors(array $cors): array
    {
        $result = $this->configuration->update(function (array &$settings) use ($cors): array {
            $settings['cors'] = $cors;
            return $cors;
        });
        $this->configurationAudit('cors');
        return $result;
    }

    public function saveAuthentication(string $mode): array
    {
        $result = $this->configuration->update(function (array &$settings) use ($mode): array {
            $settings['authentication']['mode'] = $mode;
            return [
                'mode' => $mode,
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured() || (new ApiKeyService())->configured(),
            ];
        });
        $this->configurationAudit('authentication');
        return $result;
    }

    public function saveRuntime(array $runtime): array
    {
        try {
            $result = $this->configuration->update(function (array &$settings) use ($runtime): array {
                $settings['runtime'] = $runtime;
                return $runtime;
            });
            $this->configurationAudit('runtime_security');
            return $result;
        } catch (Throwable $exception) {
            $this->logger->audit('configuration.changed', 'failure', 'ERROR', [
                'configurationCategory' => 'runtime_security', 'reason' => 'save_failed', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Unable to save runtime configuration.',
                'RUNTIME_CONFIGURATION_SAVE_FAILED',
                [],
                500
            );
        }
    }

    public function controlApi(string $operation): array
    {
        if (SecurityConfiguration::isProduction()) {
            return $this->controlApplicationRuntime('api', $operation, 'API_RUNTIME_OPERATION_FAILED');
        }
        try {
            $result = $operation === 'start' ? $this->processManager->start()
                : ($operation === 'stop' ? $this->processManager->stop() : $this->processManager->restart());
            $this->runtimeAudit('api', $operation, 'success', $result);
            return $result;
        } catch (RuntimeException $exception) {
            $this->runtimeAudit('api', $operation, 'failure', [], 'operation_failed');
            throw new ApiRequestException($exception->getMessage(), 'API_PROCESS_OPERATION_FAILED', [], 409);
        }
    }

    public function controlSqlParser(string $operation): array
    {
        if (SecurityConfiguration::isProduction()) {
            return $this->controlApplicationRuntime(
                'sqlParser',
                $operation,
                'SQL_PARSER_RUNTIME_OPERATION_FAILED'
            );
        }
        try {
            $result = $operation === 'start' ? $this->parserProcessManager->start()
                : ($operation === 'stop' ? $this->parserProcessManager->stop() : $this->parserProcessManager->restart());
            $this->runtimeAudit('sql_parser', $operation, 'success', $result);
            return $result;
        } catch (RuntimeException $exception) {
            $this->runtimeAudit('sql_parser', $operation, 'failure', [], 'operation_failed');
            throw new ApiRequestException($exception->getMessage(), 'SQL_PARSER_PROCESS_OPERATION_FAILED', [], 409);
        }
    }

    public function controlDatabase(string $operation): array
    {
        (new OperationalLogger())->info('database', 'Database runtime operation started', ['operation' => $operation]);
        if ($operation === 'disconnect') {
            $result = $this->databaseAvailability->setAvailable(false);
            $this->runtimeAudit('database', $operation, 'success', $result);
            (new OperationalLogger())->info('database', 'Database disconnect successful');
            return $result;
        }
        if ($operation === 'restart') $this->databaseAvailability->setAvailable(false);
        try { $this->testCurrentDatabase(); $result = $this->databaseAvailability->setAvailable(true); $this->runtimeAudit('database', $operation, 'success', $result); (new OperationalLogger())->info('database', 'Database runtime operation successful', ['operation' => $operation]); return $result; }
        catch (Throwable $exception) { $this->databaseAvailability->setAvailable(false); $this->runtimeAudit('database', $operation, 'failure', [], 'connection_failed'); (new OperationalLogger())->error('database', 'Database runtime operation failed', ['operation' => $operation, 'error_code' => 'DATABASE_UNAVAILABLE']); throw $exception; }
    }

    public function backupHistory(): array
    {
        return $this->backupRecovery->history();
    }

    public function createBackup(): array
    {
        return $this->backupRecovery->create();
    }

    public function downloadBackup(string $recoveryPointId): array
    {
        return $this->backupRecovery->download($recoveryPointId);
    }

    public function previewBackupRestore(string $filename, string $archive): array
    {
        return $this->backupRecovery->previewUpload($filename, $archive);
    }

    public function restoreBackup(string $uploadToken, bool $confirmed): array
    {
        return $this->backupRecovery->restore($uploadToken, $confirmed);
    }

    public function recordFrontendOperationalEvent(array $event): array
    {
        (new OperationalLogger())->info('admin', $event['event'], array_filter([
            'page' => $event['page'] ?? null,
            'operation' => $event['operation'] ?? null,
            'error_code' => $event['errorCode'] ?? null,
            'browser_request_id' => $event['requestId'] ?? null,
        ], static fn ($value): bool => $value !== null));
        return ['recorded' => true];
    }

    private function configurationAudit(string $category): void
    {
        $this->logger->audit('configuration.changed', 'success', 'NOTICE', [
            'configurationCategory' => $category,
            'component' => 'admin',
        ]);
    }

    private function runtimeAudit(string $component, string $action, string $outcome, array $result = [], ?string $reason = null): void
    {
        $this->logger->audit('runtime.lifecycle', $outcome, $outcome === 'success' ? 'INFO' : 'ERROR', [
            'component' => $component,
            'action' => $action,
            'pid' => is_int($result['pid'] ?? null) ? $result['pid'] : null,
            'port' => is_int($result['port'] ?? null) ? $result['port'] : null,
            'reason' => $reason,
        ]);
    }

    private function controlApplicationRuntime(string $service, string $operation, string $errorCode): array
    {
        $auditComponent = $service === 'api' ? 'api' : 'sql_parser';
        try {
            $result = $this->applicationRuntime->control($service, $operation);
            $this->runtimeAudit($auditComponent, $operation, 'success', $result);
            return $result;
        } catch (Throwable $exception) {
            $this->runtimeAudit($auditComponent, $operation, 'failure', [], 'operation_failed');
            throw new ApiRequestException(
                'Application runtime operation failed.',
                $errorCode,
                [],
                409
            );
        }
    }

    private function databaseStatus(): array
    {
        if (!is_file($this->databasePath)) {
            return ['configured' => false, 'encrypted' => false, 'readable' => false];
        }
        try {
            $stored = DatabaseConfigurationResolver::readStored($this->databasePath);
            $resolved = DatabaseConfigurationResolver::resolve($stored);
            $this->databaseAuthentication->validate((string)($resolved['authentication'] ?? ''));
            return [
                'configured' => true,
                'encrypted' => DatabaseConfigurationResolver::usesEncryption($stored),
                'readable' => true,
                'server' => (string)($resolved['server'] ?? ''),
                'port' => isset($resolved['port']) && $resolved['port'] !== '' ? (string)$resolved['port'] : null,
                'database' => (string)($resolved['database'] ?? ''),
            ];
        } catch (Throwable $exception) {
            return [
                'configured' => true,
                'encrypted' => $this->storedDatabaseIsEncrypted(),
                'readable' => false,
            ];
        }
    }

    private function databaseHealth(): array
    {
        $status = $this->databaseStatus();
        if (!$status['readable']) {
            return [...$status, 'connected' => false, 'healthy' => false, 'status' => 'not configured'];
        }
        try {
            ($this->connectionTester)(DatabaseConfigurationResolver::load($this->databasePath));
            return [...$status, 'connected' => true, 'healthy' => true, 'status' => 'connected'];
        } catch (Throwable $exception) {
            return [...$status, 'connected' => false, 'healthy' => false, 'status' => 'connection failed'];
        }
    }

    private function withExistingPassword(array $database): array
    {
        if ($database['password'] !== null && $database['password'] !== '') {
            return $database;
        }
        $database['password'] = '';
        if (!is_file($this->databasePath)) return $database;
        try {
            $existing = DatabaseConfigurationResolver::load($this->databasePath);
            $database['password'] = (string)($existing['password'] ?? '');
        } catch (Throwable $exception) {
            // Saving a replacement password remains possible when an old
            // encrypted file cannot be opened. Empty passwords fail validation.
        }
        return $database;
    }

    private function storedDatabaseIsEncrypted(): bool
    {
        try {
            return DatabaseConfigurationResolver::usesEncryption(
                DatabaseConfigurationResolver::readStored($this->databasePath)
            );
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function validateDatabaseAuthentication(array $database): void
    {
        try {
            $this->databaseAuthentication->validate((string)($database['authentication'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'database.authentication', 'message' => $exception->getMessage()]]
            );
        }
    }
}
