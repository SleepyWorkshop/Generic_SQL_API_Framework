<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

class LoggingMiddleware extends Middleware
{
    public function handle(array $request): void
    {
        $action = is_string($request['action'] ?? null) ? $request['action'] : null;
        (new OperationalLogger())->info('api', 'API request started', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'action' => $action,
            'authentication' => $_SERVER['GENERIC_AUTH_PROVIDER'] ?? 'pending',
        ]);
        if (is_string($action) && str_starts_with($action, 'admin.')) {
            (new OperationalLogger())->info('admin', 'Admin request received', ['action' => $action]);
        }
    }
}
