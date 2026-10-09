<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../Database/DatabaseDirectory.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Requests/QueryRequestValidator.php';

/**
 * Data requests are served only by a configured, enabled database whose
 * availability gate is open: the database the request selects, else the
 * registry default. This early gate checks the primary database only; the
 * query planner checks every referenced database after validation. Nothing is
 * decrypted and no connection is opened here. A request that names no data
 * action is not a data request: validation rejects it whatever the database
 * state, so its answer never depends on which databases exist.
 */
final class DatabaseAvailabilityMiddleware extends Middleware
{
    private DatabaseContextResolver $resolver;

    public function __construct(?DatabaseAvailabilityManager $manager = null, ?DatabaseContextResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new DatabaseContextResolver(null, $manager);
    }

    public function handle(array $request): void
    {
        // The database listing reads the registry only and must stay usable
        // while a database is disconnected.
        if (!QueryRequestValidator::isAction($request['action'] ?? null)) return;
        if (DatabaseDirectory::isRegistryOnly($request['action'] ?? null)) return;
        $this->resolver->assertRequestable(DatabaseReferenceCollector::declaredPrimaryId($request));
    }
}
