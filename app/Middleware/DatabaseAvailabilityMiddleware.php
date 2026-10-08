<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * Data requests are served only by a configured, enabled database whose
 * availability gate is open. Requests do not select a database yet, so this is
 * the registry default. Nothing is decrypted and no connection is opened here.
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
        $this->resolver->assertRequestable();
    }
}
