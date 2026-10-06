<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
header_remove('X-Powered-By');

if ($path === '/health') {
    $application = require __DIR__ . '/../config/app.php';
    $port = (int)(getenv('GENERIC_SQLPARSER_PORT') ?: ($_SERVER['SERVER_PORT'] ?? 0));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'sqlparser',
        'version' => (string)($application['version'] ?? 'unknown'),
        'port' => $port,
        'startedAt' => getenv('GENERIC_SQLPARSER_STARTED_AT') ?: null,
    ], JSON_UNESCAPED_SLASHES);
    return true;
}

// Serve only the public assets, matching the production IIS/Nginx allowlist;
// parser sources and this router are never reachable as scripts.
if (in_array($path, ['/assets/css/app.css', '/assets/js/app.js'], true)) return false;
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
require_once __DIR__ . '/../core/Response.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(Response::errorPayload('Not found.', 'NOT_FOUND'), JSON_UNESCAPED_SLASHES);
return true;
