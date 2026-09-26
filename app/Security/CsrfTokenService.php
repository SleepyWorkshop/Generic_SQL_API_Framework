<?php

require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/SecurityConfiguration.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

final class CsrfTokenService
{
    private const SESSION_KEY = 'generic_reporting_csrf_token';

    private AuthSessionService $session;

    public function __construct(?AuthSessionService $session = null)
    {
        $this->session = $session ?? new AuthSessionService();
    }

    public function token(): string
    {
        $this->session->start();
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }
        return $token;
    }

    public function rotate(): string
    {
        $this->session->start();
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    public function validate(?string $providedToken): void
    {
        $expectedToken = $this->token();
        if (!is_string($providedToken)
            || strlen($providedToken) !== 64
            || !hash_equals($expectedToken, $providedToken)) {
            (new Logger())->security('csrf_rejected', [
                'sourceIp' => SecurityConfiguration::clientIp(),
                'result' => 'rejected',
            ]);
            (new OperationalLogger())->warning('api', 'CSRF validation rejected', [
                'error_code' => 'CSRF_VALIDATION_FAILED',
            ]);
            throw new ApiRequestException(
                'The security token is invalid or expired. Refresh the page and try again.',
                'CSRF_VALIDATION_FAILED',
                [],
                403
            );
        }
    }
}
