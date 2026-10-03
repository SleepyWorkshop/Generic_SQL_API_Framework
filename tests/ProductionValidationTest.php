<?php

require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';
require_once __DIR__ . '/../app/Services/AdminService.php';

function productionValidationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class ProductionValidationProcessManager extends ApiProcessManager
{
    public int $operations = 0;
    public function __construct() {}
    public function status(): array { $this->operations++; return []; }
    public function start(): array { $this->operations++; return []; }
    public function stop(): array { $this->operations++; return []; }
    public function restart(): array { $this->operations++; return []; }
}

$root = dirname(__DIR__);
$report = (new ProductionValidator($root))->report();
productionValidationAssert(($report['environment']['php']['status'] ?? null) === ProductionValidator::VALIDATED,
    'PHP environment discovery was not completed.');
foreach (['templates', 'nginxStructure', 'phpProductionIni', 'healthRoutes', 'httpsRedirects',
    'securityHeaders', 'requestLimits', 'sensitivePathDenial', 'secretScan'] as $check) {
    productionValidationAssert(($report['staticValidation'][$check] ?? null) === ProductionValidator::VALIDATED,
        "Static production validation failed: {$check}.");
}
foreach (['windowsIisFastCgi', 'linuxNginxPhpFpm', 'sqlServer', 'trustedTls', 'productionLoad'] as $live) {
    productionValidationAssert(in_array($report['liveValidation'][$live] ?? null,
        [ProductionValidator::VALIDATED, ProductionValidator::PARTIAL, ProductionValidator::NOT_EXECUTED, ProductionValidator::OPERATOR], true),
        "Live validation has an invalid status: {$live}.");
}

$iisFiles = glob($root . '/deployment/iis/*.web.config.example') ?: [];
productionValidationAssert(count($iisFiles) === 5, 'Expected IIS deployment templates are missing.');
$frontendIis = (string)file_get_contents($root . '/deployment/iis/frontend.web.config.example');
$apiIis = (string)file_get_contents($root . '/deployment/iis/api.web.config.example');
productionValidationAssert(str_contains($frontendIis, 'url="/api/health/{R:1}"'), 'IIS does not expose top-level health routes through the API application.');
productionValidationAssert(str_contains($apiIis, '^(?:index|health)\.php$'), 'IIS rejects its rewritten health entry point.');

$nginx = (string)file_get_contents($root . '/deployment/nginx/generic-sql-api.linux.example.conf');
productionValidationAssert(substr_count($nginx, '{') === substr_count($nginx, '}'), 'Nginx braces are unbalanced.');
productionValidationAssert(!preg_match('/location\s+~[^\{]*\\\.php/', $nginx), 'Nginx exposes arbitrary PHP scripts.');
foreach (['config', 'runtime', 'logs', 'database/config', '.git'] as $sensitive) {
    productionValidationAssert(!str_contains($nginx, 'root /srv/generic-reporting/Backend/' . $sensitive),
        "Nginx exposes sensitive path {$sensitive}.");
}

$ini = parse_ini_file($root . '/deployment/php-production-security.ini', false, INI_SCANNER_TYPED);
productionValidationAssert(is_array($ini)
    && $ini['display_errors'] === false && $ini['display_startup_errors'] === false
    && $ini['log_errors'] === true && $ini['expose_php'] === false
    && $ini['file_uploads'] === false && $ini['opcache.enable'] === 1
    && $ini['opcache.validate_timestamps'] === 0
    && $ini['date.timezone'] === 'UTC'
    && $ini['max_execution_time'] === 60 && $ini['max_input_time'] === 60,
    'Production PHP security/runtime settings are incomplete.');

foreach (['api/index.php', 'api/health.php', 'admin/index.php', 'admin/api.php',
    'sqlparser/index.php'] as $entry) {
    productionValidationAssert(is_file($root . '/' . $entry), "Production entry point is missing: {$entry}.");
}
$api = (string)file_get_contents($root . '/api/index.php');
$csrf = (string)file_get_contents($root . '/app/Middleware/CsrfProtectionMiddleware.php');
$errors = (string)file_get_contents($root . '/core/ExceptionHandler.php');
$adminJavaScript = (string)file_get_contents($root . '/admin/assets/admin.js');
productionValidationAssert(str_contains($api, 'CORS_ORIGIN_DENIED') && str_contains($api, 'METHOD_NOT_ALLOWED'),
    'CORS or method enforcement is absent from the API entry point.');
productionValidationAssert(str_contains($csrf, 'CSRF_VALIDATION_FAILED') || str_contains($csrf, 'validate('),
    'CSRF protection is not connected to the API pipeline.');
productionValidationAssert(str_contains($errors, 'INTERNAL_ERROR') && str_contains($errors, 'DATABASE_UNAVAILABLE'),
    'Production error categorization is incomplete.');
productionValidationAssert(
    str_contains($adminJavaScript, 'item.controlMode === "application"')
        && str_contains($adminJavaScript, 'data-control-mode="application"')
        && str_contains($adminJavaScript, '>Enable</button>')
        && str_contains($adminJavaScript, '>Disable</button>')
        && str_contains($adminJavaScript, '>Reload</button>'),
    'Admin Console does not present environment-aware application runtime controls.'
);

$oldEnvironment = getenv('GENERIC_APP_ENV');
$runtimeDirectory = sys_get_temp_dir() . '/generic-production-validation-' . bin2hex(random_bytes(6));
try {
    mkdir($runtimeDirectory, 0700, true);
    putenv('GENERIC_APP_ENV=production');
    $apiManager = new ProductionValidationProcessManager();
    $parserManager = new SqlParserProcessManager();
    $applicationStatePath = $runtimeDirectory . '/application-runtime-state.json';
    JsonFileStore::save($applicationStatePath, [
        'version' => 1,
        'generation' => 0,
        'services' => [
            'api' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
            'sqlParser' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
        ],
    ]);
    $service = new AdminService(null, $root . '/database/config/database.json', null,
        $apiManager, null, $parserManager, null, null, null, null,
        new ApplicationRuntimeManager($applicationStatePath));
    $apiResult = $service->controlApi('start');
    $parserResult = $service->controlSqlParser('restart');
    productionValidationAssert(
        $apiResult['applicationRuntime']['enabled'] === true
            && $parserResult['controlMode'] === 'application'
            && $parserResult['applicationRuntime']['reloadedAt'] !== null,
        'Production Admin did not apply application-level runtime controls.'
    );
    productionValidationAssert($apiManager->operations === 0,
        'Production Admin invoked the development API process manager.');
} finally {
    $oldEnvironment === false ? putenv('GENERIC_APP_ENV') : putenv('GENERIC_APP_ENV=' . $oldEnvironment);
    foreach (glob($runtimeDirectory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($runtimeDirectory);
}

$scriptOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/validate-production.php'), $scriptOutput, $scriptStatus);
$scriptReport = json_decode(implode("\n", $scriptOutput), true);
productionValidationAssert($scriptStatus === 0 && is_array($scriptReport)
    && isset($scriptReport['environment'], $scriptReport['staticValidation'], $scriptReport['liveValidation']),
    'Production validation CLI did not produce a valid report.');

foreach (['start-linux.sh', 'start-windows.bat'] as $launcher) {
    $source = (string)file_get_contents($root . '/' . $launcher);
    productionValidationAssert(str_contains($source, 'The PHP built-in server is for local setup and development, not production.'),
        "Development/production boundary is missing from {$launcher}.");
}

echo "Production validation tests passed.\n";
