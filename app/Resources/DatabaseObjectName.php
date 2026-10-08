<?php

require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Database/QualifiedObject.php';

/**
 * Rules for client-supplied object names. QualifiedObject is the one
 * structured representation of a physical object; this class only validates
 * client text and turns it into parts or into a QualifiedObject.
 *
 * `parse` accepts `Name` (schema `dbo`) or `Schema.Name` for writes and
 * routines. `qualify` takes a separately supplied schema and object for
 * SELECT sources. Three-part and cross-database names are rejected, as are
 * SQL Server system schemas, so clients can only address user objects; the
 * database part always comes from a resolved DatabaseContext.
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
        if (count($parts) !== 2 || !self::isSchema($parts[0]) || !self::isName($parts[1])) {
            throw self::invalid($path, $code, $message);
        }
        return ['schema' => $parts[0], 'name' => $parts[1]];
    }

    /** A schema supplied on its own: one identifier, not a system schema. */
    public static function isSchema($value): bool
    {
        return self::isName($value) && !in_array(strtolower($value), self::SYSTEM_SCHEMAS, true);
    }

    /** One object name part, never a multi-part name. */
    public static function isName($value): bool
    {
        return is_string($value) && preg_match(self::IDENTIFIER, $value) === 1;
    }

    /**
     * A client schema (null: none given) and object name in a resolved database.
     *
     * @throws ApiRequestException INVALID_REQUEST at `{path}.schema` or `{path}.table`
     */
    public static function qualify(DatabaseContext $database, ?string $schema, $object, string $path = 'source'): QualifiedObject
    {
        if ($schema !== null && !self::isSchema($schema)) {
            throw self::invalid($path . '.schema', 'INVALID_REQUEST', 'Schema must be a single non-system identifier.');
        }
        if (!self::isName($object)) {
            throw self::invalid($path . '.table', 'INVALID_REQUEST', 'Table must be a single identifier.');
        }
        return QualifiedObject::in($database, $schema, $object);
    }

    public static function invalid(string $path, string $code, string $message): ApiRequestException
    {
        return new ApiRequestException($code === 'INVALID_REQUEST' ? 'Invalid request.' : $message, $code, [['path' => $path, 'message' => $message]]);
    }
}
