<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Security/CsrfTokenService.php';
require_once __DIR__ . '/../Resources/RoutineResourceRegistry.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class CsrfProtectionMiddleware extends Middleware
{
    private const PROTECTED_ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.users.create',
        'auth.users.update',
        'auth.users.enable',
        'auth.users.disable',
        'auth.users.delete',
        'auth.users.changePassword',
        'auth.users.assignAuthorization',
        'auth.frontendUsers.create',
        'auth.frontendUsers.update',
        'auth.frontendUsers.enable',
        'auth.frontendUsers.disable',
        'auth.frontendUsers.delete',
        'auth.frontendUsers.changePassword',
        'auth.frontendUsers.assignRole',
        'auth.apiKeys.create',
        'auth.apiKeys.enable',
        'auth.apiKeys.disable',
        'auth.apiKeys.revoke',
        'setup.createAdmin',
        'admin.database.test',
        'admin.database.save',
        'admin.database.connect',
        'admin.database.disconnect',
        'admin.database.restart',
        'admin.server.save',
        'admin.console.restart',
        'admin.api.start',
        'admin.api.stop',
        'admin.api.restart',
        'admin.sqlParser.start',
        'admin.sqlParser.stop',
        'admin.sqlParser.restart',
        'admin.cors.save',
        'admin.authentication.save',
        'admin.runtime.save',
        'admin.backup.create',
        'admin.backup.download',
        'admin.backup.preview',
        'admin.backup.restore',
        'admin.backup.schedule.save',
        'admin.operational.event',
        'insert',
        'update',
        'delete',
        'upsert',
    ];

    private CsrfTokenService $tokens;
    private ?RoutineResourceRegistry $routines;

    public function __construct(?CsrfTokenService $tokens = null, ?RoutineResourceRegistry $routines = null)
    {
        $this->tokens = $tokens ?? new CsrfTokenService();
        $this->routines = $routines;
    }

    public function handle(array $request): void
    {
        if (in_array($_SERVER['GENERIC_AUTH_PROVIDER'] ?? null, ['api_key', 'none'], true)) {
            return;
        }
        if (!in_array($request['action'] ?? null, self::PROTECTED_ACTIONS, true) && !$this->isWriteRoutine($request)) {
            return;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $this->tokens->validate(is_string($header) ? $header : null);
    }

    /**
     * Registered write routines change data like insert/update/delete, so they
     * need CSRF for session callers. Read routines stay unprotected like
     * select; unregistered routines are rejected by AuthorizationMiddleware.
     */
    private function isWriteRoutine(array $request): bool
    {
        $action = $request['action'] ?? null;
        if (!in_array($action, RoutineResourceRegistry::TYPES, true)) {
            return false;
        }
        $key = $action === 'procedure' ? 'procedure' : 'function';
        $source = is_array($request['source'] ?? null) ? $request['source'] : [];
        try {
            $routine = ($this->routines ??= new RoutineResourceRegistry())->resolve($source[$key] ?? null, $action);
        } catch (ApiRequestException $exception) {
            return false;
        }
        return $routine['access'] === 'write';
    }
}
