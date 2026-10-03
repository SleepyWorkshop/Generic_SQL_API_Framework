<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Runtime/ApplicationRuntimeManager.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class ApplicationRuntimeMiddleware extends Middleware
{
    public function __construct(
        private string $service,
        private ?ApplicationRuntimeManager $manager = null
    ) {
        $this->manager ??= new ApplicationRuntimeManager();
    }

    public function handle(array $request): void
    {
        if (!SecurityConfiguration::isProduction()) {
            return;
        }
        try {
            if ($this->manager->enabled($this->service)) {
                return;
            }
        } catch (Throwable $exception) {
            // Invalid or unavailable state fails closed without exposing storage details.
        }
        throw new ApiRequestException(
            'Application service is currently unavailable.',
            'SERVICE_UNAVAILABLE',
            [],
            503
        );
    }
}
