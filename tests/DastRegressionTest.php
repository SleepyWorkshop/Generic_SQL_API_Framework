<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';

/*
 * Regression tests for the v2.1.4 DAST findings (docs/security/Security-Verification.md;
 * deferred dynamic testing in docs/security/Penetration-Test-Preparation.md).
 * The SQL Parser runs on the PHP built-in server with expose_php forced on, so
 * the PHP version header is only absent when the application removes it.
 *
 * DAST-01: the SQL Parser page and its 404 responses disclosed X-Powered-By.
 * DAST-02: the development router executed any PHP file under sqlparser/,
 *          including src/*.php and router.php itself.
 */

function dastAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function dastRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') dastRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

/**
 * Start the SQL Parser on the PHP built-in server with expose_php enabled.
 * Without the router, index.php is invoked directly, as IIS and Nginx do.
 */
function dastStartParser(string $root, array $environment, bool $router = true): array
{
    $port = null;
    foreach (range(19950 + random_int(0, 20) * 10, 20999) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    dastAssert($port !== null, 'Unable to reserve a test port.');
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-d', 'expose_php=On', '-S', "127.0.0.1:{$port}", '-t', "{$root}/sqlparser");
    if ($router) $command[] = "{$root}/sqlparser/router.php";
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $processEnvironment = array_merge(is_array(getenv()) ? getenv() : [], $environment);
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root, $processEnvironment);
    dastAssert(is_resource($server), 'Unable to start the SQL Parser test server.');
    $deadline = microtime(true) + 10;
    while (!($probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2)) && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (!is_resource($probe)) {
        proc_terminate($server);
        proc_close($server);
        throw new RuntimeException('The SQL Parser test server did not start.');
    }
    fclose($probe);
    return [$server, $port];
}

function dastRequest(int $port, string $path, string $method = 'GET', ?string $body = null): array
{
    $options = ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30];
    if ($body !== null) {
        $options['header'] = 'Content-Type: application/json';
        $options['content'] = $body;
    }
    $responseBody = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => $options]));
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    dastAssert($responseBody !== false && $responseHeaders !== [], "SQL Parser did not answer {$method} {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $status);
    $named = [];
    foreach ($responseHeaders as $header) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $header, $match) === 1) $named[strtolower($match[1])] = trim($match[2]);
    }
    $decoded = json_decode((string)$responseBody, true);
    return ['status' => (int)$status[1], 'headers' => $named, 'body' => (string)$responseBody, 'json' => is_array($decoded) ? $decoded : null];
}

function dastNoVersion(array $response, string $label): void
{
    dastAssert(!isset($response['headers']['x-powered-by']), "{$label} disclosed X-Powered-By.");
}

$root = dirname(__DIR__);

// The bundled Linux runtime must not advertise the PHP version (the Windows
// runtime and deployment/php-production-security.ini already disable it).
$linuxIni = parse_ini_file($root . '/runtime/linux/php/php.ini', false, INI_SCANNER_TYPED);
dastAssert(is_array($linuxIni) && ($linuxIni['expose_php'] ?? null) === false, 'Bundled Linux php.ini exposes the PHP version.');

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-dast-regression-' . bin2hex(random_bytes(8));
$servers = [];

try {
    mkdir($directory . DIRECTORY_SEPARATOR . 'logs', 0700, true);
    mkdir($directory . DIRECTORY_SEPARATOR . 'operational', 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory);
    RuntimeConfiguration::ensure();
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    $environment = [
        'GENERIC_APP_ENV' => 'development',
        'GENERIC_RUNTIME_CONFIG_DIR' => $directory,
        'GENERIC_LOG_DIR' => $directory . DIRECTORY_SEPARATOR . 'logs',
        'GENERIC_OPERATIONAL_LOG_DIR' => $directory . DIRECTORY_SEPARATOR . 'operational',
    ];
    [$servers[], $port] = dastStartParser($root, $environment);
    [$servers[], $direct] = dastStartParser($root, $environment, false);

    // DAST-01: no version disclosure on any SQL Parser response.
    $page = dastRequest($port, '/');
    dastAssert($page['status'] === 200 && str_contains($page['body'], 'SQL → API JSON Generator'), 'SQL Parser page did not render.');
    dastNoVersion($page, 'SQL Parser page');
    dastAssert(($page['headers']['x-frame-options'] ?? null) === 'DENY', 'SQL Parser page lacks X-Frame-Options in development.');
    dastAssert(($page['headers']['referrer-policy'] ?? null) === 'no-referrer', 'SQL Parser page lacks Referrer-Policy in development.');
    dastAssert(($page['headers']['x-content-type-options'] ?? null) === 'nosniff', 'SQL Parser page lacks nosniff in development.');
    dastAssert(str_contains($page['headers']['content-security-policy'] ?? '', "frame-ancestors 'none'"), 'SQL Parser page lacks its CSP.');
    dastNoVersion(dastRequest($port, '/index.php'), 'SQL Parser index.php');
    $parse = dastRequest($port, '/', 'POST', json_encode(['sql' => 'SELECT Item_Code FROM ItemMasterTable']));
    dastAssert($parse['status'] === 200 && $parse['json'] !== null, 'SQL Parser did not parse a valid SELECT.');
    dastNoVersion($parse, 'SQL Parser parse response');
    $invalid = dastRequest($port, '/', 'POST', '{');
    dastAssert($invalid['status'] === 400 && ($invalid['json']['error']['code'] ?? $invalid['json']['code'] ?? null) === 'INVALID_JSON', 'SQL Parser accepted malformed JSON.');
    dastNoVersion($invalid, 'SQL Parser error response');
    dastNoVersion(dastRequest($port, '/health'), 'SQL Parser health');
    $directPage = dastRequest($direct, '/index.php');
    dastAssert($directPage['status'] === 200, 'SQL Parser page did not render without the router.');
    dastNoVersion($directPage, 'SQL Parser page without the router');
    dastNoVersion(dastRequest($direct, '/index.php', 'POST', json_encode(['sql' => 'SELECT 1'])), 'SQL Parser parse response without the router');

    // DAST-02: only the public assets are served; sources and the router are not executable.
    foreach (['/assets/css/app.css', '/assets/js/app.js'] as $asset) {
        $response = dastRequest($port, $asset);
        dastAssert($response['status'] === 200 && $response['body'] !== '', "SQL Parser asset {$asset} is not served.");
    }
    foreach (['/router.php', '/src/SqlParser.php', '/src/SqlParserRequestHandler.php', '/src/SqlLexer.php',
        '/assets/../src/SqlParser.php', '/%2e%2e/README.md', '/src/', '/assets/', '/.git/config', '/missing'] as $path) {
        $response = dastRequest($port, $path);
        dastAssert($response['status'] === 404 && str_contains($response['body'], 'NOT_FOUND'), "SQL Parser exposed {$path} (status {$response['status']}).");
        dastNoVersion($response, "SQL Parser {$path}");
    }

    echo "DAST regression tests passed.\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    dastRemove($directory);
}
