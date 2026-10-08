<?php

require_once __DIR__ . '/../../config/constants.php';

/**
 * Base directory of security state such as rate-limit counters:
 * `storage/security`, or `GENERIC_SECURITY_STORAGE_DIR` when set (like
 * `GENERIC_LOG_DIR` for logs).
 */
final class SecurityStorage
{
    public const ENVIRONMENT_VARIABLE = 'GENERIC_SECURITY_STORAGE_DIR';

    public static function directory(string $name): string
    {
        $configured = getenv(self::ENVIRONMENT_VARIABLE);
        $base = is_string($configured) && trim($configured) !== ''
            ? rtrim($configured, '/\\')
            : ROOT_PATH . '/storage/security';
        return $base . '/' . $name;
    }
}
