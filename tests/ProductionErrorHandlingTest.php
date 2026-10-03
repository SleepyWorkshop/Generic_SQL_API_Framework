<?php

require_once __DIR__ . '/../core/ExceptionHandler.php';
require_once __DIR__ . '/../app/Http/RequestBodyReader.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';

function errorHandlingAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function errorMapping(Throwable $exception, int $status, string $code): array
{
    [$actualStatus, $payload] = ExceptionHandler::responseFor($exception);
    errorHandlingAssert($actualStatus === $status && $payload['error']['code'] === $code,
        "Incorrect error mapping for {$code}.");
    return $payload;
}

$requestId = RequestId::get();
$canonical = Response::errorPayload('Invalid request.', 'INVALID_REQUEST', [['path' => 'field', 'message' => 'Invalid.']]);
errorHandlingAssert($canonical === [
    'success' => false,
    'message' => 'Invalid request.',
    'error' => ['code' => 'INVALID_REQUEST', 'details' => [['path' => 'field', 'message' => 'Invalid.']]],
    'data' => [],
    'meta' => ['requestId' => $requestId],
], 'Canonical error envelope is inconsistent.');

$mappings = [
    [new ApiRequestException('Invalid JSON request.', 'INVALID_JSON', [], 400), 400, 'INVALID_JSON'],
    [new ApiRequestException('Validation failed.', 'INVALID_REQUEST', [], 422), 422, 'INVALID_REQUEST'],
    [new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401), 401, 'AUTHENTICATION_REQUIRED'],
    [new ApiRequestException('Forbidden.', 'AUTHORIZATION_DENIED', [], 403), 403, 'AUTHORIZATION_DENIED'],
    [new ApiRequestException('Invalid CSRF token.', 'CSRF_VALIDATION_FAILED', [], 403), 403, 'CSRF_VALIDATION_FAILED'],
    [new ApiRequestException('Origin is not allowed.', 'CORS_ORIGIN_DENIED', [], 403), 403, 'CORS_ORIGIN_DENIED'],
    [new ApiRequestException('Method not allowed.', 'METHOD_NOT_ALLOWED', [], 405), 405, 'METHOD_NOT_ALLOWED'],
    [new ApiRequestException('Not found.', 'NOT_FOUND', [], 404), 404, 'NOT_FOUND'],
    [new ApiRequestException('Request body is too large.', 'REQUEST_TOO_LARGE', [], 413), 413, 'REQUEST_TOO_LARGE'],
    [new ApiRequestException('Rate limit exceeded.', 'RATE_LIMIT_EXCEEDED', [], 429), 429, 'RATE_LIMIT_EXCEEDED'],
    [new ApiRequestException('Conflict.', 'CONFLICT', [], 409), 409, 'CONFLICT'],
    [new ApiRequestException('Database unavailable.', 'DATABASE_UNAVAILABLE', [], 503), 503, 'DATABASE_UNAVAILABLE'],
];
foreach ($mappings as [$exception, $status, $code]) errorMapping($exception, $status, $code);
errorMapping(new QueryTimeoutException('secret SQL timeout details'), 504, 'QUERY_ERROR');
errorMapping(new DatabaseCredentialException('fake encryption key is wrong'), 503, 'DATABASE_CONFIGURATION_ERROR');
errorMapping(new RuntimeException('[SQLSTATE 28000] Login failed for fake-password'), 503, 'DATABASE_AUTHENTICATION_FAILED');
errorMapping(new RuntimeException('[ODBC Driver 18] server unavailable'), 503, 'DATABASE_UNAVAILABLE');

$fakeSecrets = [
    'fake-password', 'fake-api-key', 'fake-encryption-key', 'fake-csrf-token',
    'fake-session-id', 'Cookie: secret', 'Authorization: Bearer secret',
    'SELECT Secret FROM Hidden', '/private/application/path',
    'GENERIC_SQL_API_ENCRYPTION_KEY', 'Stack trace:', 'php.ini', '--secret-command',
];
$unexpected = errorMapping(new RuntimeException(implode(' ', $fakeSecrets)), 500, 'INTERNAL_ERROR');
$encoded = json_encode($unexpected, JSON_THROW_ON_ERROR);
foreach ($fakeSecrets as $secret) {
    errorHandlingAssert(!str_contains($encoded, $secret), "Unexpected error leaked {$secret}.");
}
$databaseLeak = errorMapping(new RuntimeException('[ODBC Driver 18] secret connection string fake-password'), 503, 'DATABASE_UNAVAILABLE');
errorHandlingAssert(!str_contains(json_encode($databaseLeak, JSON_THROW_ON_ERROR), 'ODBC Driver'), 'ODBC diagnostics leaked to the client.');
errorHandlingAssert($unexpected['message'] === 'An unexpected application error occurred.'
    && $unexpected['meta']['requestId'] === $requestId, 'Unexpected exception response is not safe or correlated.');

[$fatalStatus, $fatal] = ExceptionHandler::fatalResponse(false);
[$timeoutStatus, $timeout] = ExceptionHandler::fatalResponse(true);
errorHandlingAssert($fatalStatus === 500 && $fatal['error']['code'] === 'INTERNAL_ERROR', 'Fatal errors are not safely mapped.');
errorHandlingAssert($timeoutStatus === 504 && $timeout['error']['code'] === 'QUERY_ERROR', 'Execution timeout mapping changed.');
errorHandlingAssert(ExceptionHandler::handlePhpError(E_WARNING, 'fake warning /private/path', __FILE__, __LINE__), 'Production warning interception failed.');

$directory = sys_get_temp_dir() . '/generic-error-handling-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
try {
    $logger = new Logger($directory);
    $logger->audit('test.error', 'failure', 'ERROR', ['component' => 'test', 'errorCategory' => 'simulated']);
    $log = (string)file_get_contents($directory . '/' . date('Y-m-d') . '.log');
    errorHandlingAssert(str_contains($log, '"requestId":"' . $requestId . '"'), 'Server log request ID does not match the client response.');
    $blockedPath = $directory . '/not-a-directory';
    file_put_contents($blockedPath, 'blocked');
    (new Logger($blockedPath . '/logs'))->audit('test.logger_failure', 'failure');
    errorHandlingAssert(Response::errorPayload('Still safe.', 'INTERNAL_ERROR')['success'] === false,
        'Logging failure replaced the primary response.');

    $child = $directory . '/output-buffer.php';
    file_put_contents($child, '<?php require ' . var_export(__DIR__ . '/../core/Response.php', true)
        . '; ob_start(); echo "contaminating-warning"; Response::emitErrorPayload(Response::errorPayload("Safe error.", "INTERNAL_ERROR"), 500);');
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child), $output, $code);
    $body = implode("\n", $output);
    $decoded = json_decode($body, true);
    errorHandlingAssert($code === 0 && is_array($decoded) && !str_contains($body, 'contaminating-warning'),
        'Buffered accidental output corrupted the JSON error response.');

    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, str_repeat('x', 32)); rewind($stream);
    try { RequestBodyReader::read(32, $stream); }
    catch (Throwable $exception) { errorHandlingAssert(false, 'Valid request body was rejected.'); }
    fclose($stream);

    for ($index = 0; $index < 100; $index++) {
        $payload = ExceptionHandler::responseFor(new RuntimeException('concurrent-fake-secret'))[1];
        errorHandlingAssert($payload['meta']['requestId'] === $requestId, 'Repeated handling generated an unrelated request ID.');
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}

$api = (string)file_get_contents(__DIR__ . '/../api/index.php');
$admin = (string)file_get_contents(__DIR__ . '/../admin/api.php');
$parser = (string)file_get_contents(__DIR__ . '/../sqlparser/src/SqlParserRequestHandler.php');
$health = (string)file_get_contents(__DIR__ . '/../api/health.php');
errorHandlingAssert(str_contains($api, 'CORS_ORIGIN_DENIED') && str_contains($api, "ExceptionHandler::register('api')"), 'API boundary error protections are incomplete.');
errorHandlingAssert(str_contains($admin, "ExceptionHandler::register('admin')"), 'Admin boundary is not centrally protected.');
errorHandlingAssert(!str_contains($parser, "'fragment'=>") && str_contains($parser, 'Response::errorPayload'), 'Parser error response leaks SQL or bypasses the canonical envelope.');
errorHandlingAssert(str_contains($health, "503") && str_contains($health, "'/health/ready'"), 'Health readiness compatibility changed.');
errorHandlingAssert(str_contains((string)file_get_contents(__DIR__ . '/../core/ExceptionHandler.php'), 'if (self::$handling)'),
    'Recursive handler failure guard is missing.');

echo "Production error handling tests passed.\n";
