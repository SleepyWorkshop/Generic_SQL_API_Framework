<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Security/ApiRateLimiter.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

final class ApiRateLimitMiddleware extends Middleware
{
    public function __construct(private ?ApiRateLimiter $limiter = null, private ?Logger $logger = null)
    {
        $this->limiter ??= new ApiRateLimiter();
        $this->logger ??= new Logger();
    }

    public function handle(array $request): void
    {
        $identity = $this->identity();
        try {
            $this->limiter->consume($identity);
        } catch (ApiRequestException $exception) {
            if ($exception->getErrorCode() === 'RATE_LIMIT_EXCEEDED') {
                (new OperationalLogger())->warning('api', 'API rate limit rejected', [
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                ]);
                $this->logger->audit('rate_limit.api', 'rejected', 'WARNING', [
                    'identityType' => strstr($identity, ':', true) ?: 'anonymous',
                    'identityHash' => substr(hash('sha256', $identity), 0, 16),
                    'component' => 'api_protection',
                ]);
            }
            throw $exception;
        }
    }

    private function identity(): string
    {
        $principal = PrincipalContext::current();
        if ($principal !== null && $principal->authenticationType === 'session' && $principal->userId !== null) {
            return 'session:' . $principal->userId;
        }
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if ($principal !== null && $principal->authenticationType === 'api_key' && is_string($apiKey) && $apiKey !== '') {
            return 'api-key:' . hash('sha256', $apiKey);
        }
        return 'anonymous:' . SecurityConfiguration::clientIp();
    }
}
