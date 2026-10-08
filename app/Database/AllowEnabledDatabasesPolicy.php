<?php

require_once __DIR__ . '/DatabaseAccessPolicy.php';
require_once __DIR__ . '/DatabaseContextResolver.php';

/**
 * V3.0 policy: every enabled database on an enabled server profile may be
 * used by any principal the action's permissions already allow. There is no
 * per-database permission or role restriction.
 */
final class AllowEnabledDatabasesPolicy implements DatabaseAccessPolicy
{
    public function assertAllowed(DatabaseContext $database, ?Principal $principal): void
    {
        if (!$database->enabled) throw DatabaseContextResolver::disabled();
        if (!$database->serverProfile->enabled) throw DatabaseContextResolver::serverProfileDisabled();
    }
}
