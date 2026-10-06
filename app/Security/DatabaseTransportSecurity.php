<?php

/**
 * SQL Server transport-security rules shared by the driver, System Health, and
 * production validation.
 */
final class DatabaseTransportSecurity
{
    /** Production automatic driver selection is limited to modern drivers. */
    public const PRODUCTION_AUTO_DRIVERS = [
        'ODBC Driver 18 for SQL Server',
        'ODBC Driver 17 for SQL Server',
    ];

    /** Safe warning codes for a resolved database configuration. */
    public static function warnings(array $configuration): array
    {
        $options = is_array($configuration['options'] ?? null) ? $configuration['options'] : [];
        $warnings = [];
        if (empty($options['encrypt'])) {
            $warnings[] = 'encrypt_disabled';
        }
        if (!empty($options['trustServerCertificate'])) {
            $warnings[] = 'trust_server_certificate_enabled';
        }
        $driver = trim((string)($configuration['driver'] ?? 'auto'));
        if (strtolower($driver) !== 'auto' && !in_array($driver, self::PRODUCTION_AUTO_DRIVERS, true)) {
            $warnings[] = 'legacy_driver_configured';
        }
        return $warnings;
    }

    /** Whether a connection failure was caused by TLS or certificate validation. */
    public static function isTlsFailure(string $message): bool
    {
        return preg_match(
            '/\b(?:SSL|TLS)\b|certificate|certificat|handshake|secure channel|encryption not supported|trust chain/i',
            $message
        ) === 1;
    }
}
