<?php

require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Middleware/ApplicationRuntimeMiddleware.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

function productionRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function productionRuntimeRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') productionRuntimeRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

final class ProductionRuntimeProcessProbe extends ApiProcessManager
{
    public int $operations = 0;

    public function __construct() {}
    public function status(): array { $this->operations++; return []; }
    public function start(): array { $this->operations++; return []; }
    public function stop(): array { $this->operations++; return []; }
    public function restart(): array { $this->operations++; return []; }
}

$directory = sys_get_temp_dir() . '/generic-production-runtime-' . bin2hex(random_bytes(8));
$configurationDirectory = $directory . '/config';
$logs = $directory . '/logs';
$databasePath = $directory . '/database.json';
$parserProcessPath = $directory . '/sqlparser-process.json';
$oldEnvironment = getenv('GENERIC_APP_ENV');
$oldConfigurationDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');

try {
    mkdir($configurationDirectory, 0700, true);
    mkdir($logs, 0700, true);
    putenv('GENERIC_APP_ENV=production');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    RuntimeConfiguration::ensure();

    $applicationStatePath = RuntimeConfiguration::path(RuntimeConfiguration::APPLICATION_RUNTIME_STATE_FILE);
    $applicationRuntime = new ApplicationRuntimeManager($applicationStatePath);
    $configuration = new AdminConfigurationRepository();
    $databaseAvailability = new DatabaseAvailabilityManager(
        RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE)
    );
    JsonFileStore::save($databasePath, [
        'provider' => 'sqlserver',
        'driver' => 'auto',
        'server' => 'localhost',
        'port' => '1433',
        'database' => 'RuntimeTest',
        'authentication' => 'sql',
        'username' => 'runtime_test',
        'password' => 'test-only',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false],
    ]);
    $apiProcessProbe = new ProductionRuntimeProcessProbe();
    $parserProcessManager = new SqlParserProcessManager(
        $configuration,
        null,
        null,
        $parserProcessPath,
        dirname(__DIR__)
    );
    $connectionTests = 0;
    $service = new AdminService(
        $configuration,
        $databasePath,
        static function () use (&$connectionTests): void { $connectionTests++; },
        $apiProcessProbe,
        null,
        $parserProcessManager,
        null,
        $databaseAvailability,
        new Logger($logs),
        null,
        $applicationRuntime
    );

    productionRuntimeAssert(
        $service->settings()['hostingMode'] === 'production',
        'Production hosting mode was not server-controlled through the existing environment.'
    );
    $initial = $applicationRuntime->status('api');
    productionRuntimeAssert(
        $initial['running'] === true
            && $initial['controlMode'] === 'application'
            && $initial['infrastructure']['managedExternally'] === true
            && $initial['applicationRuntime']['enabled'] === true
            && $initial['pid'] === null
            && $initial['port'] === null
            && $initial['startedAt'] === null,
        'Production health did not separate external infrastructure from application runtime state.'
    );

    $apiStopped = $service->controlApi('stop');
    productionRuntimeAssert(
        $apiStopped['status'] === 'disabled'
            && $apiStopped['applicationRuntime']['enabled'] === false,
        'Production API Stop did not disable application runtime availability.'
    );
    try {
        (new ApplicationRuntimeMiddleware('api', $applicationRuntime))->handle(['action' => 'query.select']);
        productionRuntimeAssert(false, 'Disabled production API request was accepted.');
    } catch (ApiRequestException $exception) {
        [$status, $payload] = ExceptionHandler::responseFor($exception);
        productionRuntimeAssert(
            $status === 503
                && ($payload['error']['code'] ?? null) === 'SERVICE_UNAVAILABLE'
                && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), $directory),
            'Disabled production API did not return a safe service-unavailable response.'
        );
    }
    $apiStarted = $service->controlApi('start');
    productionRuntimeAssert(
        $apiStarted['status'] === 'enabled'
            && $apiStarted['applicationRuntime']['enabled'] === true,
        'Production API Start did not enable application runtime availability.'
    );
    (new ApplicationRuntimeMiddleware('api', $applicationRuntime))->handle(['action' => 'query.select']);
    $apiReloaded = $service->controlApi('restart');
    productionRuntimeAssert(
        $apiReloaded['status'] === 'enabled'
            && is_string($apiReloaded['applicationRuntime']['reloadedAt']),
        'Production API Restart did not reload application runtime state.'
    );

    $parserStopped = $service->controlSqlParser('stop');
    productionRuntimeAssert(
        $parserStopped['service'] === 'sqlparser'
            && $parserStopped['status'] === 'disabled'
            && $parserStopped['applicationRuntime']['enabled'] === false,
        'Production SQL Parser Stop did not disable application runtime availability.'
    );
    try {
        (new ApplicationRuntimeMiddleware('sqlParser', $applicationRuntime))->handle([]);
        productionRuntimeAssert(false, 'Disabled production SQL Parser request was accepted.');
    } catch (ApiRequestException $exception) {
        productionRuntimeAssert(
            $exception->getStatusCode() === 503
                && $exception->getErrorCode() === 'SERVICE_UNAVAILABLE'
                && $exception->getDetails() === [],
            'Disabled production SQL Parser did not return a safe service-unavailable response.'
        );
    }
    $parserStarted = $service->controlSqlParser('start');
    productionRuntimeAssert(
        $parserStarted['status'] === 'enabled'
            && $parserStarted['applicationRuntime']['enabled'] === true,
        'Production SQL Parser Start did not enable application runtime availability.'
    );
    $parserReloaded = $service->controlSqlParser('restart');
    productionRuntimeAssert(
        is_string($parserReloaded['applicationRuntime']['reloadedAt']),
        'Production SQL Parser Restart did not reload application runtime state.'
    );

    productionRuntimeAssert(
        $apiProcessProbe->operations === 0 && !is_file($parserProcessPath),
        'Production application controls invoked a development process manager.'
    );
    $managerSource = (string)file_get_contents(__DIR__ . '/../app/Runtime/ApplicationRuntimeManager.php');
    foreach (['proc_open', 'shell_exec', 'system(', 'exec(', 'passthru', 'popen'] as $processApi) {
        productionRuntimeAssert(
            !str_contains($managerSource, $processApi),
            "Production application runtime manager contains process execution API {$processApi}."
        );
    }

    $service->controlApi('stop');
    $service->controlSqlParser('stop');
    $health = $service->status();
    productionRuntimeAssert(
        $health['api']['infrastructure']['status'] === 'externally managed'
            && $health['api']['infrastructure']['healthy'] === null
            && $health['api']['applicationRuntime']['status'] === 'disabled'
            && $health['sqlParser']['infrastructure']['status'] === 'externally managed'
            && $health['sqlParser']['applicationRuntime']['status'] === 'disabled'
            && $health['api']['pid'] === null
            && $health['sqlParser']['port'] === null,
        'Production System Health fabricated process details or merged runtime and infrastructure state.'
    );

    $disconnected = $service->controlDatabase('disconnect');
    productionRuntimeAssert(
        $disconnected['available'] === false && !array_key_exists('pid', $disconnected),
        'Production database Disconnect stopped something other than application availability.'
    );
    $connected = $service->controlDatabase('connect');
    productionRuntimeAssert(
        $connected['available'] === true && $connectionTests === 1,
        'Production database Connect no longer validates and enables application availability.'
    );

    $persisted = JsonFileStore::load($applicationStatePath);
    $generation = $persisted['generation'];
    $secondManager = new ApplicationRuntimeManager($applicationStatePath);
    productionRuntimeAssert(
        $secondManager->status('api')['applicationRuntime']['enabled'] === false
            && $secondManager->status('sqlParser')['applicationRuntime']['enabled'] === false
            && $generation === 8,
        'Production application runtime state did not persist across manager instances.'
    );

    $logSource = implode("\n", glob($logs . '/*.log') ? array_map(
        static fn (string $path): string => (string)file_get_contents($path),
        glob($logs . '/*.log')
    ) : []);
    productionRuntimeAssert(
        substr_count($logSource, '"event":"runtime.lifecycle"') >= 8
            && str_contains($logSource, '"component":"api"')
            && str_contains($logSource, '"component":"sql_parser"'),
        'Production application runtime mutations were not security-audited.'
    );

    $apiEntry = (string)file_get_contents(__DIR__ . '/../api/index.php');
    $apiRouter = (string)file_get_contents(__DIR__ . '/../api/router.php');
    $parserEntry = (string)file_get_contents(__DIR__ . '/../sqlparser/index.php');
    $parserRouter = (string)file_get_contents(__DIR__ . '/../sqlparser/router.php');
    $adminApi = (string)file_get_contents(__DIR__ . '/../admin/api.php');
    productionRuntimeAssert(
        str_contains($apiEntry, "ApplicationRuntimeMiddleware('api')")
            && str_contains($parserEntry, "ApplicationRuntimeMiddleware('sqlParser')")
            && str_contains($apiRouter, "['/health', '/health/live', '/health/ready']")
            && str_contains($parserRouter, "if (\$path === '/health')"),
        'Production runtime request gates or independent liveness routes are missing.'
    );
    productionRuntimeAssert(
        !str_contains($adminApi, 'ApplicationRuntimeMiddleware')
            && strpos($adminApi, 'new AuthenticationMiddleware') < strpos($adminApi, 'new AdminAuthorizationMiddleware')
            && strpos($adminApi, 'new AdminAuthorizationMiddleware') < strpos($adminApi, 'new CsrfProtectionMiddleware')
            && str_contains($adminApi, "'admin.api.stop'")
            && str_contains($adminApi, "'admin.sqlParser.stop'"),
        'Admin control plane independence, authorization, or CSRF enforcement regressed.'
    );

    putenv('GENERIC_APP_ENV=development');
    (new ApplicationRuntimeMiddleware('api', $applicationRuntime))->handle(['action' => 'query.select']);
    productionRuntimeAssert(
        $service->settings()['hostingMode'] === 'development',
        'Development mode did not continue using the existing server-controlled environment.'
    );

    echo "Production runtime control tests passed.\n";
} finally {
    $oldEnvironment === false
        ? putenv('GENERIC_APP_ENV')
        : putenv('GENERIC_APP_ENV=' . $oldEnvironment);
    $oldConfigurationDirectory === false
        ? putenv('GENERIC_RUNTIME_CONFIG_DIR')
        : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldConfigurationDirectory);
    productionRuntimeRemove($directory);
}
