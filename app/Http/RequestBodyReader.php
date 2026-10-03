<?php

require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class RequestBodyReader
{
    public static function read(?int $contentLength = null, $stream = null, ?int $maximumOverride = null): string
    {
        $maximum = $maximumOverride ?? SecurityConfiguration::requestOptions()['maxBodyBytes'];
        if ($maximum < 1 || $maximum > 30 * 1024 * 1024) throw new InvalidArgumentException('Request body limit is invalid.');
        $contentLength ??= isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
            ? (int)$_SERVER['CONTENT_LENGTH']
            : null;
        if ($contentLength !== null && $contentLength > $maximum) self::tooLarge();

        $opened = false;
        if ($stream === null) {
            $stream = fopen('php://input', 'rb');
            $opened = true;
        }
        if (!is_resource($stream)) {
            throw new RuntimeException('Request body is unavailable.');
        }
        try {
            $body = stream_get_contents($stream, $maximum + 1);
        } finally {
            if ($opened) fclose($stream);
        }
        if ($body === false) throw new RuntimeException('Request body is unavailable.');
        if (strlen($body) > $maximum) self::tooLarge();
        return $body;
    }

    private static function tooLarge(): never
    {
        throw new ApiRequestException('Request body is too large.', 'REQUEST_TOO_LARGE', [], 413);
    }
}
