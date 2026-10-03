<?php

function productionHostingAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function productionHostingXmlIsBalanced(string $xml): bool
{
    $xml = (string)preg_replace('/<\?.*?\?>|<!--.*?-->/s', '', $xml);
    preg_match_all('/<\s*(\/)?([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*?)?(\/)?\s*>/s', $xml, $matches, PREG_SET_ORDER);
    $stack = [];
    foreach ($matches as $match) {
        $closing = ($match[1] ?? '') === '/';
        $name = $match[2];
        $selfClosing = ($match[3] ?? '') === '/';
        if ($selfClosing) continue;
        if ($closing) {
            if (array_pop($stack) !== $name) return false;
            continue;
        }
        $stack[] = $name;
    }
    return $stack === [];
}

$root = dirname(__DIR__);
$nginxPath = $root . '/deployment/nginx/generic-sql-api.linux.example.conf';
$iniPath = $root . '/deployment/php-production-security.ini';
$iisPaths = [
    $root . '/deployment/iis/frontend.web.config.example',
    $root . '/deployment/iis/api.web.config.example',
    $root . '/deployment/iis/admin.web.config.example',
    $root . '/deployment/iis/sqlparser.web.config.example',
];

foreach ([$root . '/api/index.php', $root . '/admin/index.php', $root . '/admin/api.php', $root . '/sqlparser/index.php'] as $entryPoint) {
    productionHostingAssert(is_file($entryPoint), "Production entry point is missing: {$entryPoint}");
}

$nginx = (string)file_get_contents($nginxPath);
foreach ([
    'fastcgi_pass generic_sql_api_php',
    '/Backend/api/index.php',
    '/Backend/admin/index.php',
    '/Backend/admin/api.php',
    '/Backend/sqlparser/index.php',
    'listen 127.0.0.1:8090',
    'listen 127.0.0.1:8101',
    'location ^~ /api/ { return 404; }',
    'location / { return 404; }',
] as $required) {
    productionHostingAssert(str_contains($nginx, $required), "Linux hosting template is missing {$required}.");
}
productionHostingAssert(
    str_contains($nginx, 'listen 443 ssl;')
        && str_contains($nginx, 'ssl_protocols TLSv1.2 TLSv1.3;')
        && str_contains($nginx, 'return 308 https://reports.example.internal$request_uri;'),
    'Linux TLS or redirect configuration is missing.'
);
productionHostingAssert(
    !preg_match('/location\s+~[^\{]*\\\.php/', $nginx),
    'Linux hosting template exposes arbitrary PHP scripts.'
);
productionHostingAssert(
    str_contains($nginx, 'bak|backup|old|tmp|log|lock|ini|sql'),
    'Linux hosting template does not deny backup and temporary file extensions.'
);
foreach (['/Backend/config', '/Backend/logs', '/Backend/runtime', '/Backend/storage', '/Backend/database/config'] as $sensitiveRoot) {
    productionHostingAssert(!str_contains($nginx, 'root /srv/generic-reporting' . $sensitiveRoot), "Sensitive directory is configured as an Nginx root: {$sensitiveRoot}");
}

foreach ($iisPaths as $iisPath) {
    $xml = (string)file_get_contents($iisPath);
    productionHostingAssert(productionHostingXmlIsBalanced($xml), 'IIS template is not balanced XML: ' . basename($iisPath));
    productionHostingAssert(str_contains($xml, 'directoryBrowse enabled="false"'), 'IIS template permits directory browsing: ' . basename($iisPath));
    productionHostingAssert(!preg_match('/password|encryption.key|api.key/i', $xml), 'IIS template contains credential-like configuration: ' . basename($iisPath));
    foreach (['.bak', '.backup', '.old', '.tmp', '.lock'] as $extension) {
        productionHostingAssert(
            str_contains($xml, 'fileExtension="' . $extension . '" allowed="false"'),
            'IIS template does not deny ' . $extension . ': ' . basename($iisPath)
        );
    }
}
$iisApi = (string)file_get_contents($iisPaths[1]);
$iisAdmin = (string)file_get_contents($iisPaths[2]);
$iisParser = (string)file_get_contents($iisPaths[3]);
productionHostingAssert(str_contains($iisApi, 'Reject non-entry-point paths'), 'IIS API template does not restrict entry points.');
productionHostingAssert(str_contains($iisAdmin, 'allowUnlisted="false"') && str_contains($iisAdmin, '127.0.0.1'), 'IIS Admin template is not loopback restricted.');
productionHostingAssert(str_contains($iisParser, '<add segment="src" />'), 'IIS SQL Parser template does not hide parser source.');

$ini = parse_ini_file($iniPath, false, INI_SCANNER_TYPED);
productionHostingAssert(is_array($ini), 'Production PHP configuration template is invalid.');
foreach (['display_errors', 'display_startup_errors', 'html_errors', 'expose_php', 'file_uploads'] as $disabled) {
    productionHostingAssert(($ini[$disabled] ?? null) === false, "Production PHP setting {$disabled} is not disabled.");
}
productionHostingAssert(($ini['log_errors'] ?? null) === true, 'Production PHP error logging is not enabled.');
productionHostingAssert(($ini['opcache.enable'] ?? null) === 1, 'Production OPcache is not enabled.');
productionHostingAssert(($ini['opcache.validate_timestamps'] ?? null) === 0, 'Production OPcache timestamp policy is not explicit.');

$linuxLauncher = (string)file_get_contents($root . '/start-linux.sh');
$windowsLauncher = (string)file_get_contents($root . '/start-windows.bat');
productionHostingAssert(str_contains($linuxLauncher, '-S "127.0.0.1:$ADMIN_PORT"'), 'Linux development launcher no longer uses its local Admin server.');
productionHostingAssert(str_contains($windowsLauncher, '-S "127.0.0.1:%ADMIN_PORT%"'), 'Windows development launcher no longer uses its local Admin server.');
productionHostingAssert(
    str_contains($windowsLauncher, 'icacls "%KEY_FILE%" /inheritance:r /grant:r "%USERNAME%:(R,W,D)"')
        && strpos($windowsLauncher, 'icacls "%KEY_FILE%"') < strpos($windowsLauncher, 'prepare-local-encryption-key.php" > "%KEY_FILE%"'),
    'Windows launcher does not restrict its temporary key-output file before writing it.'
);
foreach ([$linuxLauncher, $windowsLauncher] as $launcher) {
    productionHostingAssert(str_contains($launcher, 'PHP_VERSION_ID >= 80200'), 'Development launcher does not enforce the supported PHP version.');
    productionHostingAssert(str_contains($launcher, 'GENERIC_APP_ENV') && str_contains($launcher, 'development'), 'Development launcher does not pin development hosting mode.');
    productionHostingAssert(str_contains($launcher, 'api-runtime-control.php') && str_contains($launcher, 'start'), 'Development launcher does not start the managed API.');
    productionHostingAssert(str_contains($launcher, 'sqlparser-runtime-control.php') && str_contains($launcher, 'start'), 'Development launcher does not start the managed SQL Parser.');
    productionHostingAssert(str_contains($launcher, 'database-runtime-control.php') && str_contains($launcher, 'connect'), 'Development launcher does not connect application database availability.');
    productionHostingAssert(str_contains($launcher, 'verify-development-runtime.php'), 'Development launcher does not verify the completed runtime.');
    productionHostingAssert(!preg_match('/systemctl|iisreset|appcmd|net\s+(?:start|stop)|sc\s+(?:start|stop)|nginx\s+-s/i', $launcher), 'Development launcher contains infrastructure service control.');
}

$hostingDocumentation = (string)file_get_contents($root . '/docs/Production-Security-and-Deployment.md');
foreach (['IIS', 'FastCGI', 'Nginx', 'PHP-FPM', 'OPcache'] as $topic) {
    productionHostingAssert(str_contains($hostingDocumentation, $topic), "Production hosting documentation is missing {$topic}.");
}

echo "Production hosting tests passed.\n";
