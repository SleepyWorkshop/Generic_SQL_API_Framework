<?php

require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * Parses a client-supplied table or routine name into a schema and object name.
 *
 * Accepts `Name` (schema `dbo`) or `Schema.Name`. Three-part and cross-database
 * names are rejected, as are SQL Server system schemas, so the data API can only
 * address user objects in the configured database.
 */
final class DatabaseObjectName
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const SYSTEM_SCHEMAS = ['sys', 'information_schema'];

    /** @return array{schema: string, name: string} */
    public static function parse($value, string $path, string $code, string $message): array
    {
        $parts = is_string($value) ? explode('.', $value) : [];
        if (count($parts) === 1) {
            array_unshift($parts, 'dbo');
        }
        if (count($parts) !== 2
            || preg_match(self::IDENTIFIER, $parts[0]) !== 1
            || preg_match(self::IDENTIFIER, $parts[1]) !== 1
            || in_array(strtolower($parts[0]), self::SYSTEM_SCHEMAS, true)) {
            throw new ApiRequestException($message, $code, [['path' => $path, 'message' => $message]]);
        }
        return ['schema' => $parts[0], 'name' => $parts[1]];
    }
}
