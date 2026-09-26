<?php

$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit('Not found.');
}
header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (strtolower((string)(getenv('GENERIC_APP_ENV') ?: 'development')) !== 'production') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
}
$application = require __DIR__ . '/../config/app.php';
$applicationVersion = is_array($application) && is_string($application['version'] ?? null)
    ? $application['version']
    : 'unknown';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generic SQL API · Administration</title>
    <link rel="stylesheet" href="/assets/admin.css">
    <link rel="stylesheet" href="/assets/service-controls.css">
</head>
<body class="pre-auth" data-app-version="<?= htmlspecialchars($applicationVersion, ENT_QUOTES, 'UTF-8') ?>">
<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Administration navigation">
        <div class="sidebar-header">
            <span class="sidebar-brand">Generic SQL API</span>
            <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse navigation" aria-expanded="true" aria-controls="navigation"><span aria-hidden="true">☰</span></button>
        </div>
        <nav id="navigation" hidden>
            <a href="/admin/health" data-route="health" title="System Health"><span class="nav-icon" aria-hidden="true">H</span><span class="nav-label">System Health</span></a>
            <a href="/admin/info" data-route="info" title="System Info"><span class="nav-icon" aria-hidden="true">I</span><span class="nav-label">System Info</span></a>
            <a href="/admin/configuration" data-route="configuration" title="Configuration"><span class="nav-icon" aria-hidden="true">C</span><span class="nav-label">Configuration</span></a>
            <a href="/admin/users" data-route="users" title="Users"><span class="nav-icon" aria-hidden="true">U</span><span class="nav-label">Users</span></a>
            <a href="/admin/api-keys" data-route="api-keys" title="API Keys"><span class="nav-icon" aria-hidden="true">K</span><span class="nav-label">API Keys</span></a>
            <a href="/admin/backup-recovery" data-route="backup-recovery" title="Backup &amp; Recovery"><span class="nav-icon" aria-hidden="true">B</span><span class="nav-label">Backup &amp; Recovery</span></a>
        </nav>
        <div class="sidebar-footer">
            <button id="logout" class="quiet" type="button" hidden title="Logout"><span class="nav-icon" aria-hidden="true">↪</span><span class="nav-label">Logout</span></button>
            <span class="app-version" id="sidebar-version" title="Application version"></span>
        </div>
    </aside>
    <button id="sidebar-backdrop" class="sidebar-backdrop" type="button" aria-label="Close navigation" aria-hidden="true" tabindex="-1"></button>
    <main>
        <header><div><p class="eyebrow">Administration</p><h1 id="page-title">Starting…</h1></div></header>
        <div id="toast" class="toast" role="status" aria-live="polite"></div>
        <section id="content" class="panel loading"><div class="skeleton"></div><div class="skeleton short"></div></section>
    </main>
</div>
<dialog id="confirmation"><form method="dialog"><h2 id="confirm-title">Confirm action</h2><p id="confirm-message"></p><div class="actions"><button value="cancel" class="secondary">Cancel</button><button value="confirm" class="danger">Confirm</button></div></form></dialog>
<dialog id="user-dialog" class="user-dialog" aria-labelledby="user-dialog-title"><div id="user-dialog-content"></div></dialog>
<script src="/assets/admin.js" defer></script>
</body>
</html>
