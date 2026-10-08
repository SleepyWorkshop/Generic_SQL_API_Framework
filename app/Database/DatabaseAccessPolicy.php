<?php

require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/../Authorization/Principal.php';

/**
 * Whether a principal may use a resolved database. Runs after the permission
 * authorization of the action, for every database a request references.
 * Policies see only registry-resolved contexts, never client configuration.
 */
interface DatabaseAccessPolicy
{
    /** @throws ApiRequestException when the database may not be used */
    public function assertAllowed(DatabaseContext $database, ?Principal $principal): void;
}
