<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * Maps each public data action to the permissions it requires. The decision
 * depends only on the authenticated Principal's roles, so session, API-key,
 * legacy-key, and anonymous principals follow exactly the same rules.
 */
final class AuthorizationMiddleware extends Middleware
{
    public function __construct(private ?AuthorizationService $authorization = null)
    {
        $this->authorization ??= new AuthorizationService();
    }

    public function handle(array $request): void
    {
        $principal = PrincipalContext::current();
        if ($principal === null) throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        $action = (string)($request['action'] ?? '');
        match (true) {
            in_array($action, ['select', 'union', 'unionAll'], true) => $this->authorization->authorizeAny($principal, ['data.read', 'frontend.read']),
            $action === 'sql' => $this->authorization->authorizeAny($principal, ['sql.execute', 'frontend.read']),
            str_starts_with($action, 'metadata.') => $this->authorization->authorizeAny($principal, ['metadata.read', 'frontend.read']),
            in_array($action, ['insert', 'update', 'delete', 'upsert'], true) => $this->authorization->authorize($principal, 'data.write'),
            // SQL Server functions cannot modify data; stored procedures can, so
            // they additionally require data.write.
            in_array($action, ['function', 'tableFunction'], true) => $this->authorization->authorize($principal, 'routine.execute'),
            $action === 'procedure' => $this->authorization->authorizeAll($principal, ['routine.execute', 'data.write']),
            default => $this->authorization->authorize($principal, 'admin.manage'),
        };
    }
}
