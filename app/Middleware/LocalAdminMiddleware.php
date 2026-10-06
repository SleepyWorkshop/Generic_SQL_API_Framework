<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class LocalAdminMiddleware extends Middleware
{
    /**
     * Admin API actions that only establish or describe a session. Every other
     * Admin API action (admin.*, first-run setup, backend identities, API keys,
     * and backend roles) requires the enabled loopback Admin boundary.
     */
    private const SESSION_ACTIONS = ['setup.status', 'auth.csrf', 'auth.login', 'auth.session', 'auth.logout'];

    public function handle(array $request): void
    {
        if (in_array($request['action'] ?? null, self::SESSION_ACTIONS, true)) {
            return;
        }
        $enabled = getenv('GENERIC_ADMIN_ENABLED');
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($enabled !== '1' || !in_array($address, ['127.0.0.1', '::1'], true)) {
            throw new ApiRequestException('Resource not found.', 'NOT_FOUND', [], 404);
        }
    }
}
