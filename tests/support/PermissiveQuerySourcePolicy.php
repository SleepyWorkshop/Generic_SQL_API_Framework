<?php

require_once __DIR__ . '/../../app/Resources/QuerySourcePolicy.php';

/**
 * Test-only policy for builder tests that exercise SQL generation rather than
 * source authorization. Query-source enforcement has its own suite.
 */
final class PermissiveQuerySourcePolicy extends QuerySourcePolicy
{
    public function __construct() {}

    public function allows(string $source): bool
    {
        return true;
    }
}
