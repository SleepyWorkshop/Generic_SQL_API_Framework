<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Resources/RoutineResourceRegistry.php';

final class AuthorizationMiddleware extends Middleware
{
    public function __construct(private ?AuthorizationService $authorization = null, private ?RoutineResourceRegistry $routines = null)
    {
        $this->authorization ??= new AuthorizationService();
    }
    public function handle(array $request): void
    {
        $principal = PrincipalContext::current();
        if ($principal === null) throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        $action = (string)($request['action'] ?? '');
        if (str_starts_with($action, 'metadata.')) { $this->authorization->authorizeAny($principal, ['metadata.read', 'frontend.read']); return; }
        if ($action === 'sql') { $this->authorization->authorizeAny($principal, ['sql.execute', 'frontend.read'], is_string($request['resource'] ?? null) ? $request['resource'] : '', 'sql'); return; }
        if (in_array($action, ['insert', 'update', 'delete', 'upsert'], true)) { $this->authorization->authorize($principal, 'data.write', is_string($request['resource'] ?? null) ? $request['resource'] : '', 'write'); return; }
        if (in_array($action, RoutineResourceRegistry::TYPES, true)) {
            $key = $action === 'procedure' ? 'procedure' : 'function';
            $routine = ($this->routines ??= new RoutineResourceRegistry())->resolve($request['source'][$key] ?? null, $action);
            $this->authorization->authorizeRoutine($principal, $routine);
            return;
        }
        if (in_array($action, ['select', 'union', 'unionAll'], true)) { $this->authorization->authorizeAny($principal, ['data.read', 'frontend.read']); return; }
        $this->authorization->authorize($principal, 'admin.manage');
    }
}
