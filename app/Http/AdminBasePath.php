<?php

/**
 * Resolves the public URL path at which the Admin Console is mounted.
 *
 * The result is '' when Admin is served from the origin root (the development
 * document-root layout) or a path-absolute, percent-encoded prefix without a
 * trailing slash such as '/admin' or '/internal-admin'. It is derived from the
 * executing entry script's SCRIPT_NAME, which the web server sets from its own
 * URL mapping, or from an explicit deployment override for reverse proxies that
 * strip the public prefix. Host and forwarded headers are never consulted.
 */
final class AdminBasePath
{
    public const ENVIRONMENT_VARIABLE = 'GENERIC_ADMIN_BASE_PATH';

    public static function resolve(array $server, string|false|null $override = null, string $entryScript = 'index.php'): string
    {
        if (is_string($override) && trim($override) !== '') {
            $configured = self::normalize($override);
            if ($configured !== null) return $configured;
        }

        // dirname() is platform dependent ('\' for '/index.php' on Windows), so
        // split the URL path explicitly instead.
        $scriptName = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? ''));
        $separator = strrpos($scriptName, '/');
        if ($separator === false || substr($scriptName, $separator + 1) !== $entryScript) return '';

        return self::normalize(substr($scriptName, 0, $separator)) ?? '';
    }

    public static function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || $path === '/') return '';
        if ($path[0] !== '/') return null;

        $segments = [];
        foreach (explode('/', substr(rtrim($path, '/'), 1)) as $segment) {
            $decoded = rawurldecode($segment);
            if ($segment === '' || $decoded === '.' || $decoded === '..'
                || preg_match('/[\x00-\x1F\x7F\/\\\\?#]/', $decoded) === 1) {
                return null;
            }
            $segments[] = preg_replace_callback(
                "/[^A-Za-z0-9\\-._~!$&'()*+,;=:@]/",
                static fn (array $match): string => rawurlencode($match[0]),
                $decoded
            );
        }

        return '/' . implode('/', $segments);
    }
}
