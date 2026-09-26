<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/RequestId.php';
require_once __DIR__ . '/OperationalLogger.php';

class Response
{
    private static array $requestContext = [];

    public static function setRequestContext(array $request): void
    {
        self::$requestContext = $request;
    }

    public static function successPayload($data = [], string $message = 'Success'): array
    {
        $rows = is_array($data) && isset($data['data']) && is_array($data['data'])
            ? $data['data']
            : (is_array($data) ? $data : []);
        $pagination = self::$requestContext['pagination'] ?? (
            isset(self::$requestContext['page'], self::$requestContext['pageSize'])
                ? ['page' => self::$requestContext['page'], 'pageSize' => self::$requestContext['pageSize']]
                : []
        );
        $rowsReturned = is_array($data) && isset($data['rowsReturned'])
            ? (int)$data['rowsReturned']
            : count($rows);

        return [
            'success' => true,
            'message' => $message,
            'data' => $rows,
            'meta' => [
                'requestId' => RequestId::get(),
                'page' => $pagination['page'] ?? null,
                'pageSize' => $pagination['pageSize'] ?? null,
                'totalRows' => is_array($data) && isset($data['totalRows'])
                    ? (int)$data['totalRows']
                    : $rowsReturned,
                'rowsReturned' => $rowsReturned,
                'executionTime' => is_array($data) && isset($data['executionTime'])
                    ? $data['executionTime']
                    : null,
                ...is_array($data) && array_key_exists('affectedRows', $data)
                    ? ['affectedRows' => (int)$data['affectedRows']]
                    : [],
            ]
        ];
    }

    public static function errorPayload(
        string $message,
        string $errorCode = 'INTERNAL_ERROR',
        array $details = []
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'error' => ['code' => $errorCode, 'details' => $details],
            'data' => [],
            'meta' => ['requestId' => RequestId::get()],
        ];
    }

    public static function success($data = [], $message = 'Success', $code = 200)
    {
        $started = microtime(true);
        $payload = self::successPayload($data, (string)$message);
        $json = self::encodePayload($payload);
        self::logResponseTiming($started, true, null, (int)$code);
        self::discardBufferedOutput();
        http_response_code($code);
        header('X-Request-ID: ' . RequestId::get());
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    public static function error(
        $message = 'Something went wrong',
        $code = 500,
        string $errorCode = 'INTERNAL_ERROR',
        array $details = []
    ) {
        $started = microtime(true);
        $payload = self::errorPayload((string)$message, $errorCode, $details);
        self::logResponseTiming($started, false, $errorCode, (int)$code);
        self::emitErrorPayload($payload, $code);
        exit;
    }

    public static function emitErrorPayload(array $payload, int $code): void
    {
        try { $json = self::encodePayload($payload); }
        catch (Throwable $exception) {
            $json = '{"success":false,"message":"An unexpected application error occurred.","error":{"code":"INTERNAL_ERROR","details":[]},"data":[],"meta":{"requestId":"'
                . RequestId::get() . '"}}';
        }
        self::discardBufferedOutput();
        if (!headers_sent()) {
            http_response_code($code);
            header('X-Request-ID: ' . RequestId::get());
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $json;
    }

    private static function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    private static function logResponseTiming(float $started, bool $success, ?string $errorCode = null, int $status = 200): void
    {
        if (!defined('API_REQUEST_STARTED')) return;
        $logger = new Logger();
        $logger->timing('response_construction', (microtime(true) - $started) * 1000, [
            'success' => $success,
            'errorCode' => $errorCode,
        ]);
        $logger->timing('request_total', (microtime(true) - API_REQUEST_STARTED) * 1000, [
            'success' => $success,
            'errorCode' => $errorCode,
        ]);
        $duration = round((microtime(true) - API_REQUEST_STARTED) * 1000, 2);
        $operational = new OperationalLogger();
        $context = [
            'action' => self::$requestContext['action'] ?? null,
            'status' => $status,
            'duration_ms' => $duration,
            'error_code' => $errorCode,
        ];
        if ($success) $operational->info('api', 'API request completed', $context);
        else $operational->error('api', 'API request failed', $context);
    }

    private static function discardBufferedOutput(): void
    {
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) break;
        }
    }
}
