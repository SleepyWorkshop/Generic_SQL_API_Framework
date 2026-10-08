<?php

// Development router for `php -S 127.0.0.1:<port> -t admin admin/router.php`.
// Here the admin/ directory is the document root, so the console is served at
// "/" and index.php derives an empty base path from SCRIPT_NAME. Production
// web servers do not use this file; they map their own public path (for
// example /admin/) to the admin directory.

$path = rawurldecode(
    parse_url(
        $_SERVER['REQUEST_URI'] ?? '/',
        PHP_URL_PATH
    ) ?: '/'
);

$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

$path = '/' . ltrim($path, '/');

$candidate = __DIR__ . $path;

if ($path !== '/' && is_file($candidate)) {
    return false;
}

if ($path === '/api.php') {
    require __DIR__ . '/api.php';
    return true;
}

$adminRoutes = [
    '/health',
    '/info',
    '/configuration',
    '/databases',
    '/users',
    '/api-keys',
    '/backup-recovery'
];

$route = $path === '/' ? '/' : rtrim($path, '/');

if ($route === '/' || in_array($route, $adminRoutes, true)) {
    require __DIR__ . '/index.php';
    return true;
}

// Earlier development launchers opened the console at /admin; send those
// bookmarks to the equivalent document-root page. The Location is a
// path-only reference, so the request Host header is never reflected.
$legacyRoute = $route === '/admin' ? '/' : (str_starts_with($route, '/admin/') ? substr($route, 6) : null);

if ($legacyRoute !== null && ($legacyRoute === '/' || in_array($legacyRoute, $adminRoutes, true))) {
    header('Cache-Control: no-store');
    header('Location: ' . $legacyRoute, true, 302);
    return true;
}

http_response_code(404);

header('Content-Type: text/plain; charset=utf-8');

echo 'Not found.';

return true;
