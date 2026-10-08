<?php

require_once __DIR__ . '/DatabaseContextResolver.php';
require_once __DIR__ . '/DatabaseReferenceCollector.php';
require_once __DIR__ . '/DatabaseAccessPolicy.php';
require_once __DIR__ . '/AllowEnabledDatabasesPolicy.php';
require_once __DIR__ . '/DatabaseQueryPlan.php';
require_once __DIR__ . '/../Authorization/Principal.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * Request → references → contexts → access policy → plan.
 *
 * The primary database is the top-level `database`, else the base source
 * database, else the registry default. Sources without a database belong to
 * the primary database. Every referenced database must be configured,
 * enabled, allowed by the access policy, available, and on the primary
 * database's server profile; the server profile id is the boundary, never a
 * hostname. Nothing here generates SQL or opens a connection.
 */
final class DatabaseQueryPlanner
{
    private DatabaseContextResolver $resolver;
    private DatabaseAccessPolicy $policy;
    private DatabaseReferenceCollector $collector;

    public function __construct(
        ?DatabaseContextResolver $resolver = null,
        ?DatabaseAccessPolicy $policy = null,
        ?DatabaseReferenceCollector $collector = null
    ) {
        $this->resolver = $resolver ?? new DatabaseContextResolver();
        $this->policy = $policy ?? new AllowEnabledDatabasesPolicy();
        $this->collector = $collector ?? new DatabaseReferenceCollector();
    }

    /** Plan a validated public request. */
    public function plan(array $request, ?Principal $principal = null): DatabaseQueryPlan
    {
        return $this->planReferences($this->collector->collect($request), $principal);
    }

    public function planReferences(DatabaseReferenceSet $references, ?Principal $principal = null): DatabaseQueryPlan
    {
        $primary = $references->explicitPrimary();
        if ($primary === null) {
            $defaultId = $this->resolver->defaultDatabaseId();
            // Nothing configured: as in V2, the database is unavailable.
            if ($defaultId === null) throw DatabaseContextResolver::unavailable();
            $primary = DatabaseReference::defaultDatabase($defaultId);
        }
        $ordered = [$primary->id => $primary];
        foreach ($references->references as $reference) $ordered[$reference->id] ??= $reference;

        $contexts = [];
        foreach ($ordered as $id => $reference) {
            $context = $reference->resolve($this->resolver);
            try {
                $this->policy->assertAllowed($context, $principal);
            } catch (ApiRequestException $exception) {
                throw $reference->atPath($exception);
            }
            $contexts[$id] = $context;
        }

        $primaryContext = $contexts[$primary->id];
        foreach ($ordered as $id => $reference) {
            if (!$contexts[$id]->sharesServerProfileWith($primaryContext)) {
                throw new ApiRequestException('Cross-server queries are not supported.', 'CROSS_SERVER_QUERY_NOT_SUPPORTED', [
                    ['path' => $reference->path, 'message' => 'Every database in a request must belong to the server profile of the primary database.'],
                ]);
            }
        }
        foreach ($contexts as $context) $this->resolver->assertAvailable($context);

        return new DatabaseQueryPlan($primaryContext, array_values($contexts), array_values($ordered));
    }
}
