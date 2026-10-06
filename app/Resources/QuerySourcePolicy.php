<?php

require_once __DIR__ . '/QuerySourceRegistry.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * Decides whether the current principal may read a physical table or view.
 * Request-local CTE names are not physical sources and are never checked here.
 */
class QuerySourcePolicy
{
    private QuerySourceRegistry $registry;

    public function __construct(?QuerySourceRegistry $registry = null)
    {
        $this->registry = $registry ?? new QuerySourceRegistry();
    }

    public function allows(string $source): bool
    {
        $entry = $this->registry->find($source);
        if ($entry === null) {
            return false;
        }
        if ($entry['roles'] === null) {
            return true;
        }
        $principal = PrincipalContext::current();
        if ($principal === null) {
            return false;
        }
        return array_intersect($principal->roles(), $entry['roles']) !== []
            || ($principal->frontendAccess && in_array(QuerySourceRegistry::FRONTEND_ACCESS, $entry['roles'], true));
    }

    public function assertAllowed(string $source): void
    {
        if (!$this->allows($source)) {
            throw new ApiRequestException(
                'Resource access is denied.',
                'RESOURCE_ACCESS_DENIED',
                [['path' => 'source', 'message' => 'Query source is not available.']],
                403
            );
        }
    }
}
