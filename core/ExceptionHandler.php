<?php

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/../app/Requests/ApiRequestException.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialException.php';
require_once __DIR__ . '/QueryTimeoutException.php';
require_once __DIR__ . '/OperationalLogger.php';

final class ExceptionHandler
{
    private static bool $registered = false;
    private static bool $handling = false;

    public static function register(): void
    {
        if (self::$registered) return;
        self::$registered = true;
        if (self::isProduction()) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
            set_error_handler([self::class, 'handlePhpError'], E_WARNING | E_NOTICE | E_USER_WARNING
                | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);
        }
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handlePhpError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) return false;
        self::safeLog('php.runtime_error', 'PHPError', $message . " at {$file}:{$line}");
        return true;
    }

    public static function handleException(Throwable $exception): never
    {
        if (self::$handling) {
            Response::emitErrorPayload(Response::errorPayload(
                'An unexpected application error occurred.', 'INTERNAL_ERROR'
            ), 500);
            exit;
        }
        self::$handling = true;
        self::safeLog('application.exception', get_class($exception), self::formatExceptionForLog($exception));
        [$status, $payload] = self::responseFor($exception);
        Response::emitErrorPayload($payload, $status);
        exit;
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if (!is_array($error) || !in_array($error['type'] ?? null,
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        if (self::$handling) return;
        self::$handling = true;
        $timeout = stripos((string)($error['message'] ?? ''), 'Maximum execution time') !== false;
        self::safeLog($timeout ? 'application.timeout' : 'application.fatal',
            $timeout ? 'PHPExecutionTimeout' : 'PHPFatalError',
            (string)($error['message'] ?? 'Fatal PHP error.') . ' at '
                . (string)($error['file'] ?? 'unknown') . ':' . (int)($error['line'] ?? 0));
        [$status, $payload] = self::fatalResponse($timeout);
        Response::emitErrorPayload($payload, $status);
    }

    public static function responseFor(Throwable $exception): array
    {
        if ($exception instanceof ApiRequestException) {
            return [$exception->getStatusCode(), Response::errorPayload(
                $exception->getMessage(), $exception->getErrorCode(), $exception->getDetails()
            )];
        }
        if ($exception instanceof QueryTimeoutException) {
            return [504, Response::errorPayload('Query execution timed out.', 'QUERY_ERROR')];
        }
        if ($exception instanceof DatabaseCredentialException) {
            return [503, Response::errorPayload(
                'Database configuration is unavailable.', 'DATABASE_CONFIGURATION_ERROR'
            )];
        }
        $message = strtoupper($exception->getMessage());
        if (str_contains($message, '28000') || str_contains($message, 'LOGIN FAILED')) {
            return [503, Response::errorPayload(
                'Database authentication failed.', 'DATABASE_AUTHENTICATION_FAILED'
            )];
        }
        if (str_contains($message, 'ODBC') || str_contains($message, 'SQLSTATE')) {
            return [503, Response::errorPayload('Database is unavailable.', 'DATABASE_UNAVAILABLE')];
        }
        return [500, Response::errorPayload(
            'An unexpected application error occurred.', 'INTERNAL_ERROR'
        )];
    }

    public static function fatalResponse(bool $timeout = false): array
    {
        return $timeout
            ? [504, Response::errorPayload('Query execution timed out.', 'QUERY_ERROR')]
            : [500, Response::errorPayload('An unexpected application error occurred.', 'INTERNAL_ERROR')];
    }

    public static function report(Throwable $exception, string $event = 'application.exception'): void
    {
        self::safeLog($event, get_class($exception), self::formatExceptionForLog($exception));
    }

    private static function safeLog(string $event, string $category, string $details): void
    {
        try {
            $logger = new Logger();
            $logger->audit($event, 'failure', 'ERROR', [
                'component' => 'error_handler',
                'errorCategory' => $category,
                'reason' => 'request_failed',
            ]);
            $logger->error('Application failure', [], $details);
            (new OperationalLogger())->error('api', 'API request failed', [
                'error_code' => $event,
                'exception_category' => $category,
                'duration_ms' => defined('API_REQUEST_STARTED')
                    ? round((microtime(true) - API_REQUEST_STARTED) * 1000, 2) : null,
            ]);
        } catch (Throwable $loggingFailure) {
            // Error reporting must never replace the primary safe response.
        }
    }

    private static function formatExceptionForLog(Throwable $exception): string
    {
        return get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL
            . $exception->getTraceAsString();
    }

    private static function isProduction(): bool
    {
        return strtolower((string)(getenv('GENERIC_APP_ENV') ?: 'development')) === 'production';
    }
}
