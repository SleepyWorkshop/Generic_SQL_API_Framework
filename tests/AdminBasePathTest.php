<?php

require_once __DIR__ . '/../app/Http/AdminBasePath.php';

function adminBasePathAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function adminBasePathPhpCommand(): array
{
    return php_ini_loaded_file() === false ? [PHP_BINARY, '-n'] : [PHP_BINARY];
}

function adminBasePathEnvironment(array $environment): array
{
    $inherited = getenv();
    unset($inherited['GENERIC_APP_ENV'], $inherited['GENERIC_ADMIN_ENABLED'], $inherited[AdminBasePath::ENVIRONMENT_VARIABLE]);
    return array_merge($inherited, $environment);
}

function adminBasePathRun(array $command, array $environment = [], ?string $input = null): array
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        adminBasePathEnvironment($environment)
    );
    adminBasePathAssert(is_resource($process), 'Unable to start test subprocess.');
    fwrite($pipes[0], (string)$input);
    fclose($pipes[0]);
    $output = (string)stream_get_contents($pipes[1]);
    $error = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}

// Executes an Admin entry script as a web server would, with the given $_SERVER values.
function adminBasePathExecute(string $script, array $server, array $environment = []): string
{
    $code = '$_SERVER = array_merge($_SERVER, json_decode(stream_get_contents(STDIN), true));'
        . ' require ' . var_export(dirname(__DIR__) . '/admin/' . $script, true) . ';';
    [$status, $output, $error] = adminBasePathRun(
        [...adminBasePathPhpCommand(), '-d', 'display_errors=stderr', '-r', $code],
        $environment,
        json_encode($server, JSON_THROW_ON_ERROR)
    );
    adminBasePathAssert($status === 0 && $error === '', "Admin {$script} failed: {$error}");
    return $output;
}

function adminBasePathRender(string $scriptName, ?string $override = null): array
{
    $html = adminBasePathExecute('index.php', [
        'REMOTE_ADDR' => '127.0.0.1',
        'SCRIPT_NAME' => $scriptName,
        'SCRIPT_FILENAME' => dirname(__DIR__) . '/admin/index.php',
        'REQUEST_URI' => $scriptName,
    ], $override === null ? [] : [AdminBasePath::ENVIRONMENT_VARIABLE => $override]);
    adminBasePathAssert(preg_match('/data-admin-base="([^"]*)"/', $html, $base) === 1, 'Admin page omits its base path.');
    preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $html, $styles);
    preg_match('/<script src="([^"]+)" defer><\/script>/', $html, $script);
    preg_match_all('/<a href="([^"]+)" data-route="([^"]+)"/', $html, $links, PREG_SET_ORDER);
    return [
        'html' => $html,
        'base' => html_entity_decode($base[1], ENT_QUOTES, 'UTF-8'),
        'styles' => $styles[1],
        'script' => $script[1] ?? null,
        'links' => array_column($links, 1, 2),
    ];
}

// Starts the development Admin server exactly as the launchers do (admin/ is the document root).
function adminBasePathStartServer(string $root, array $environment): array
{
    // Stay below the kernel's ephemeral range: probing an unbound ephemeral port
    // can self-connect and then occupy the port the server needs.
    $port = null;
    foreach (range(18400 + random_int(0, 50) * 10, 18999) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    adminBasePathAssert($port !== null, 'Unable to reserve a router test port.');
    $server = proc_open(
        [...adminBasePathPhpCommand(), '-S', "127.0.0.1:{$port}", '-t', $root . '/admin', $root . '/admin/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root,
        adminBasePathEnvironment($environment)
    );
    adminBasePathAssert(is_resource($server), 'Unable to start the development Admin server.');
    $deadline = microtime(true) + 10;
    while (!($probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2)) && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (!is_resource($probe)) {
        adminBasePathStopServer($server);
        throw new RuntimeException('Development Admin server did not start.');
    }
    fclose($probe);
    return [$server, $port];
}

function adminBasePathStopServer($server): void
{
    proc_terminate($server);
    proc_close($server);
}

function adminBasePathHttp(int $port, string $method, string $path, string $body = ''): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $responseBody = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    adminBasePathAssert($responseBody !== false && $headers !== [], "Development Admin server did not answer {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $headers[0], $status);
    $named = [];
    foreach (array_slice($headers, 1) as $header) {
        [$name, $value] = array_map('trim', explode(':', $header, 2)) + [1 => ''];
        $named[strtolower($name)] = $value;
    }
    return ['status' => (int)$status[1], 'headers' => $named, 'body' => $responseBody];
}

$root = dirname(__DIR__);
$routes = ['health', 'info', 'configuration', 'users', 'api-keys', 'backup-recovery'];

// Server-side base path resolution.
foreach ([
    'PHP built-in server' => [['SCRIPT_NAME' => '/index.php'], null, ''],
    'Windows separators' => [['SCRIPT_NAME' => '\\index.php'], null, ''],
    'IIS application /admin' => [['SCRIPT_NAME' => '/admin/index.php'], null, '/admin'],
    'IIS backslash path' => [['SCRIPT_NAME' => '\\admin\\index.php'], null, '/admin'],
    'other sub-path' => [['SCRIPT_NAME' => '/internal-admin/index.php'], null, '/internal-admin'],
    'nested sub-path' => [['SCRIPT_NAME' => '/tools/internal-admin/index.php'], null, '/tools/internal-admin'],
    'encoded segment' => [['SCRIPT_NAME' => '/team admin/index.php'], null, '/team%20admin'],
    'missing SCRIPT_NAME' => [[], null, ''],
    'non-entry SCRIPT_NAME' => [['SCRIPT_NAME' => '/admin/health'], null, ''],
    'parent segment' => [['SCRIPT_NAME' => '/admin/../index.php'], null, ''],
    'network-path SCRIPT_NAME' => [['SCRIPT_NAME' => '//evil.example/index.php'], null, ''],
    'stripping proxy override' => [['SCRIPT_NAME' => '/index.php'], '/internal-admin/', '/internal-admin'],
    'override wins' => [['SCRIPT_NAME' => '/admin/index.php'], '/ops/console', '/ops/console'],
    'root override' => [['SCRIPT_NAME' => '/admin/index.php'], '/', ''],
    'absolute URL override ignored' => [['SCRIPT_NAME' => '/admin/index.php'], 'https://evil.example/admin', '/admin'],
    'network-path override ignored' => [['SCRIPT_NAME' => '/admin/index.php'], '//evil.example', '/admin'],
    'traversal override ignored' => [['SCRIPT_NAME' => '/admin/index.php'], '/a/../b', '/admin'],
    'query override ignored' => [['SCRIPT_NAME' => '/admin/index.php'], '/admin?x=1', '/admin'],
    'empty override ignored' => [['SCRIPT_NAME' => '/admin/index.php'], '', '/admin'],
    'unset override' => [['SCRIPT_NAME' => '/admin/index.php'], false, '/admin'],
] as $scenario => [$server, $override, $expected]) {
    $actual = AdminBasePath::resolve($server, $override);
    adminBasePathAssert($actual === $expected, "Admin base path for {$scenario} was '{$actual}', expected '{$expected}'.");
}

// Rendered Admin page for each deployment shape.
$deployments = [
    'development document root' => ['/index.php', null, '', 'http://127.0.0.1:8090'],
    'IIS mounted at /admin/' => ['/admin/index.php', null, '/admin', 'https://example.com'],
    'sub-path deployment' => ['/internal-admin/index.php', null, '/internal-admin', 'https://example.com'],
    'prefix-stripping reverse proxy' => ['/index.php', '/internal-admin', '/internal-admin', 'https://example.com'],
];
$rendered = [];
foreach ($deployments as $deployment => [$scriptName, $override, $base]) {
    $page = adminBasePathRender($scriptName, $override);
    $rendered[$deployment] = $page;
    adminBasePathAssert($page['base'] === $base, "{$deployment} rendered base '{$page['base']}'.");
    adminBasePathAssert(
        $page['styles'] === ["{$base}/assets/admin.css", "{$base}/assets/service-controls.css"]
            && $page['script'] === "{$base}/assets/admin.js",
        "{$deployment} does not load assets from its own mount path."
    );
    adminBasePathAssert(array_keys($page['links']) === $routes, "{$deployment} navigation routes changed.");
    foreach ($page['links'] as $route => $href) {
        adminBasePathAssert($href === "{$base}/{$route}", "{$deployment} navigation for {$route} is {$href}.");
    }
    adminBasePathAssert(!str_contains($page['html'], $root) && !str_contains($page['html'], 'index.php'), "{$deployment} exposes server paths.");
}
adminBasePathAssert(
    str_contains($rendered['development document root']['html'], 'data-admin-base=""'),
    'Development Admin page does not render an empty base path.'
);

// Browser-side URL and route resolution, using the exact base each deployment renders.
$javaScript = (string)file_get_contents($root . '/admin/assets/admin.js');
preg_match('/^  const adminRoutes = \[.*?\];$/m', $javaScript, $routeDeclaration);
adminBasePathAssert(isset($routeDeclaration[0]), 'Admin route list is missing from admin.js.');
$helpers = [$routeDeclaration[0]];
foreach (['adminBaseUrl', 'adminRouteFromPath', 'adminRouteUrl'] as $helper) {
    adminBasePathAssert(
        preg_match('/^  function ' . $helper . '\(.*?^  }$/ms', $javaScript, $match) === 1,
        "admin.js does not declare {$helper}()."
    );
    $helpers[] = $match[0];
}
adminBasePathAssert(
    str_contains($javaScript, 'adminBase = adminBaseUrl(document.body.dataset.adminBase, location.origin)')
        && str_contains($javaScript, 'apiUrl = new URL("api.php", adminBase).href')
        && str_contains($javaScript, 'return adminRouteFromPath(location.pathname, adminBase);')
        && str_contains($javaScript, 'history.replaceState({}, "", adminRouteUrl("health", adminBase))')
        && str_contains($javaScript, 'link.href = adminRouteUrl(link.dataset.route, adminBase)')
        && str_contains($javaScript, 'history.pushState({}, "", link.href)')
        && str_contains($javaScript, 'addEventListener("popstate"'),
    'admin.js does not route API calls, navigation, and history through the resolved Admin base.'
);
adminBasePathAssert(
    preg_match('#["\'`]/admin\b#', $javaScript) !== 1 && !str_contains($javaScript, 'window.location.origin}'),
    'admin.js hard-codes an Admin public path or concatenates URLs by hand.'
);

$cases = [];
$expected = [];
foreach ($deployments as $deployment => [, , , $origin]) {
    $base = $rendered[$deployment]['base'];
    foreach ([
        '' => 'health', '/' => 'health', '/health' => 'health', '/health/' => 'health', '/info' => 'info',
        '/configuration' => 'configuration', '/users' => 'users', '/api-keys' => 'api-keys',
        '/backup-recovery/' => 'backup-recovery', '/unknown' => 'health', '/users/extra' => 'health',
    ] as $suffix => $route) {
        $cases[] = ['origin' => $origin, 'base' => $base, 'pathname' => ($base . $suffix) === '' ? '/' : $base . $suffix];
        $expected[] = [
            'apiUrl' => "{$origin}{$base}/api.php",
            'route' => $route,
            'routeUrls' => array_map(fn (string $name): string => "{$origin}{$base}/{$name}", $routes),
        ];
    }
}
foreach ([
    // A page outside the mount path, or one sharing only a name prefix, is never treated as a route.
    ['https://example.com', '/admin', '/admin-tools/info', 'health', 'https://example.com/admin/'],
    ['https://example.com', '/admin', '/info', 'health', 'https://example.com/admin/'],
    ['https://example.com', '/internal-admin', '/admin/users', 'health', 'https://example.com/internal-admin/'],
    ['https://example.com', '/team%20admin', '/team%20admin/users', 'users', 'https://example.com/team%20admin/'],
    // Malformed bases can never leave the current origin.
    ['http://127.0.0.1:8090', '\\', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', '\\/admin', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', '//api.php', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', '//evil.example/admin', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', 'https://evil.example/admin', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', 'admin', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', '/', '/users', 'users', 'http://127.0.0.1:8090/'],
    ['http://127.0.0.1:8090', null, '/users', 'users', 'http://127.0.0.1:8090/'],
] as [$origin, $base, $pathname, $route, $baseUrl]) {
    $cases[] = ['origin' => $origin, 'base' => $base, 'pathname' => $pathname];
    $expected[] = [
        'apiUrl' => $baseUrl . 'api.php',
        'route' => $route,
        'routeUrls' => array_map(fn (string $name): string => $baseUrl . $name, $routes),
    ];
}

[$nodeStatus] = adminBasePathRun(['node', '--version']);
if ($nodeStatus !== 0) {
    fwrite(STDERR, "WARNING: node is unavailable; Admin JavaScript URL resolution was not executed.\n");
} else {
    [$checkStatus, , $checkError] = adminBasePathRun(['node', '--check', $root . '/admin/assets/admin.js']);
    adminBasePathAssert($checkStatus === 0, "admin.js has a syntax error: {$checkError}");
    $program = '"use strict";' . "\n" . implode("\n", $helpers) . "\n"
        . 'const cases = JSON.parse(require("fs").readFileSync(0, "utf8"));' . "\n"
        . 'process.stdout.write(JSON.stringify(cases.map(({ origin, base, pathname }) => {'
        . ' const adminBase = adminBaseUrl(base, origin);'
        . ' return { apiUrl: new URL("api.php", adminBase).href, route: adminRouteFromPath(pathname, adminBase),'
        . ' routeUrls: adminRoutes.map((route) => adminRouteUrl(route, adminBase)) }; })));';
    [$status, $output, $error] = adminBasePathRun(['node', '-e', $program], [], json_encode($cases, JSON_THROW_ON_ERROR));
    adminBasePathAssert($status === 0, "Admin JavaScript URL resolution failed: {$error}");
    $actual = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    foreach ($cases as $index => $case) {
        adminBasePathAssert(
            $actual[$index] === $expected[$index],
            'Admin JavaScript resolved ' . json_encode($case) . ' to ' . json_encode($actual[$index])
                . ', expected ' . json_encode($expected[$index]) . '.'
        );
    }
}

// Route lists stay aligned across the development router, page navigation, and client.
preg_match('/\$adminRoutes = \[(.*?)\];/s', (string)file_get_contents($root . '/admin/router.php'), $routerRoutes);
preg_match_all("/'\/([a-z-]+)'/", $routerRoutes[1] ?? '', $routerRouteNames);
preg_match_all('/"([a-z-]+)"/', $routeDeclaration[0], $clientRouteNames);
adminBasePathAssert(
    $routerRouteNames[1] === $routes && $clientRouteNames[1] === $routes,
    'Admin routes differ between router.php, index.php, and admin.js.'
);
adminBasePathAssert(
    !preg_match('#(?:href|src)="/admin|["\']/admin/#', (string)file_get_contents($root . '/admin/index.php')),
    'Admin page hard-codes the /admin public path.'
);

// Development router: loopback only, console at the document root, legacy /admin bookmarks redirected.
$router = adminBasePathExecute('router.php', ['REMOTE_ADDR' => '192.0.2.10', 'REQUEST_URI' => '/health']);
adminBasePathAssert($router === 'Not found.', 'Development Admin router served a non-loopback client.');

[$server, $port] = adminBasePathStartServer($root, ['GENERIC_ADMIN_ENABLED' => '1']);
try {
    foreach (['/', '/health', '/health/', '/info', '/configuration', '/users', '/api-keys', '/backup-recovery'] as $path) {
        $response = adminBasePathHttp($port, 'GET', $path);
        adminBasePathAssert(
            $response['status'] === 200
                && str_contains($response['body'], 'data-admin-base=""')
                && str_contains($response['body'], 'src="/assets/admin.js"')
                && str_contains($response['body'], 'href="/users" data-route="users"')
                && str_contains($response['headers']['content-security-policy'] ?? '', "connect-src 'self'"),
            "Development Admin router did not render the console at {$path}."
        );
    }
    foreach (['/assets/admin.js', '/assets/admin.css', '/assets/service-controls.css'] as $asset) {
        $response = adminBasePathHttp($port, 'GET', $asset);
        adminBasePathAssert(
            $response['status'] === 200 && $response['body'] === file_get_contents($root . '/admin' . $asset),
            "Development Admin router did not serve {$asset}."
        );
    }
    $api = adminBasePathHttp($port, 'GET', '/api.php');
    adminBasePathAssert(
        $api['status'] === 405 && str_contains($api['headers']['content-type'] ?? '', 'application/json'),
        'Development Admin router did not dispatch /api.php to the Admin API.'
    );
    $enabled = adminBasePathHttp($port, 'POST', '/api.php', '{"action":"admin.status"}');
    adminBasePathAssert(
        $enabled['status'] === 401 && str_contains($enabled['body'], '"AUTHENTICATION_REQUIRED"'),
        'Admin API did not reach authentication for an admin action on the enabled Admin boundary.'
    );
    foreach (['/admin' => '/', '/admin/' => '/', '/admin/health' => '/health', '/admin/api-keys/' => '/api-keys'] as $legacy => $location) {
        $response = adminBasePathHttp($port, 'GET', $legacy);
        adminBasePathAssert(
            $response['status'] === 302 && ($response['headers']['location'] ?? null) === $location,
            "Legacy development URL {$legacy} did not redirect to {$location}."
        );
    }
    foreach (['/unknown', '/admin/unknown', '/admin/api.php', '/assets/missing.js', '/health/extra'] as $path) {
        adminBasePathAssert(adminBasePathHttp($port, 'GET', $path)['status'] === 404, "Development Admin router served {$path}.");
    }
} finally {
    adminBasePathStopServer($server);
}

// GENERIC_ADMIN_ENABLED is owned by launchers and web-server configuration, not application code.
$adminApi = (string)file_get_contents($root . '/admin/api.php');
adminBasePathAssert(
    !str_contains($adminApi, 'putenv') && str_contains($adminApi, 'LocalAdminMiddleware'),
    'Admin API overrides the deployment-owned GENERIC_ADMIN_ENABLED boundary.'
);
[$disabledServer, $disabledPort] = adminBasePathStartServer($root, []);
try {
    $disabled = adminBasePathHttp($disabledPort, 'POST', '/api.php', '{"action":"admin.status"}');
    adminBasePathAssert(
        $disabled['status'] === 404 && str_contains($disabled['body'], '"NOT_FOUND"'),
        'Admin API accepted an admin action without GENERIC_ADMIN_ENABLED=1.'
    );
} finally {
    adminBasePathStopServer($disabledServer);
}

// Development launchers advertise the document-root URL they actually serve.
$linuxLauncher = (string)file_get_contents($root . '/start-linux.sh');
$windowsLauncher = (string)file_get_contents($root . '/start-windows.bat');
adminBasePathAssert(
    str_contains($linuxLauncher, 'ADMIN_URL="http://127.0.0.1:$ADMIN_PORT/"')
        && str_contains($linuxLauncher, 'export GENERIC_ADMIN_ENABLED=1')
        && str_contains($linuxLauncher, '-t "$ADMIN_PATH"')
        && str_contains($linuxLauncher, '"$ADMIN_PATH/router.php"'),
    'Linux launcher does not advertise the Admin document-root URL.'
);
adminBasePathAssert(
    str_contains($windowsLauncher, 'set "ADMIN_URL=http://127.0.0.1:%ADMIN_PORT%/"')
        && str_contains($windowsLauncher, 'set "GENERIC_ADMIN_ENABLED=1"')
        && str_contains($windowsLauncher, '-t "%ADMIN%"')
        && str_contains($windowsLauncher, '"%ADMIN%\\router.php"'),
    'Windows launcher does not advertise the Admin document-root URL.'
);
foreach ([$linuxLauncher, $windowsLauncher] as $launcher) {
    adminBasePathAssert(!preg_match('#127\.0\.0\.1:\S*/admin#', $launcher), 'Launcher advertises a /admin development URL.');
}

// IIS resolves every Admin rule relative to the application, whatever its virtual path.
$iis = (string)file_get_contents($root . '/deployment/iis/admin.web.config.example');
preg_match_all('/<match url="([^"]*)"/', $iis, $iisMatches);
preg_match_all('/<action type="Rewrite" url="([^"]*)"/', $iis, $iisRewrites);
adminBasePathAssert(
    $iisMatches[1] === ['^api\\.php$', '^assets/(?:admin\\.css|admin\\.js|service-controls\\.css)$', '.*']
        && $iisRewrites[1] === ['index.php']
        && !preg_match('/url="\/|type="Redirect"/', $iis),
    'IIS Admin rules depend on a fixed public path.'
);
adminBasePathAssert(
    str_contains($iis, 'scriptProcessor="C:\\PHP\\php-cgi.exe|-d generic_sql_api.boundary=admin"')
        && str_contains($iis, "arguments='-d generic_sql_api.boundary=admin'].environmentVariables.[name='GENERIC_ADMIN_ENABLED',value='1']")
        && str_contains($iis, '<ipSecurity allowUnlisted="false">')
        && str_contains($iis, "connect-src 'self'"),
    'IIS Admin boundary lost its dedicated FastCGI environment, loopback restriction, or CSP.'
);

// The Nginx example serves pages, assets, and the API below one Admin prefix.
$nginx = (string)file_get_contents($root . '/deployment/nginx/generic-sql-api.linux.example.conf');
$adminServer = substr($nginx, strpos($nginx, 'root /srv/generic-reporting/Backend/admin;'));
$adminServer = substr($adminServer, 0, strpos($adminServer, "\n}\n"));
preg_match_all('/fastcgi_param SCRIPT_NAME (\S+);/', $adminServer, $scriptNames);
preg_match_all('/location\s+(?:=|\^~)\s+(\S+)/', $adminServer, $locations);
adminBasePathAssert(
    $scriptNames[1] === ['/admin/api.php', '/admin/index.php']
        && in_array('/admin/api.php', $locations[1], true)
        && in_array('/admin/', $locations[1], true)
        && in_array('/admin/assets/admin.js', $locations[1], true)
        && in_array('/admin/assets/admin.css', $locations[1], true)
        && in_array('/admin/assets/service-controls.css', $locations[1], true)
        && !preg_match('/location = \/(?:api\.php|assets\/)/', $adminServer)
        && str_contains($adminServer, 'absolute_redirect off;')
        && str_contains($adminServer, 'deny all;'),
    'Nginx Admin pages, assets, and API do not share one mount path.'
);

echo "Admin base path tests passed.\n";
