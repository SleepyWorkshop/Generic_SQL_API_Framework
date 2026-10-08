<?php

/**
 * One SQL Server identifier of a known kind, rendered as a delimited
 * identifier: `[name]`, with `]` doubled. An identifier is a single name part:
 * multi-part names are built from separate parts by QualifiedObject.
 *
 * Schema, object, column, and alias names come from requests, so they are
 * limited to letters, digits, spaces, `_ @ # $ -`, and `]`; dots, semicolons,
 * comment markers, quotes, and other SQL punctuation are rejected. Database
 * names are physical catalog names from the registry, never from a request;
 * SQL Server allows punctuation such as `.` in them, which delimiting keeps
 * inside the one part, so only control characters, `;`, and comment markers
 * are rejected for them.
 */
final class MssqlIdentifier
{
    public const DATABASE = 'database';
    public const SCHEMA = 'schema';
    public const OBJECT = 'object';
    public const COLUMN = 'column';
    public const ALIAS = 'alias';
    private const MAX_LENGTH = 128;
    private const NAME = '/^[\p{L}\p{M}\p{N}_ @#$\-\]]+$/u';

    private function __construct(public readonly string $kind, public readonly string $name) {}

    public static function database(string $name): self { return self::of(self::DATABASE, $name); }
    public static function schema(string $name): self { return self::of(self::SCHEMA, $name); }
    public static function object(string $name): self { return self::of(self::OBJECT, $name); }
    public static function column(string $name): self { return self::of(self::COLUMN, $name); }
    public static function alias(string $name): self { return self::of(self::ALIAS, $name); }

    /** @throws InvalidArgumentException naming only the kind, never the value */
    public static function of(string $kind, $name): self
    {
        if (!self::isValid($kind, $name)) {
            throw new InvalidArgumentException("Invalid SQL Server {$kind} identifier.");
        }
        return new self($kind, $name);
    }

    public static function isValid(string $kind, $name): bool
    {
        if (!in_array($kind, [self::DATABASE, self::SCHEMA, self::OBJECT, self::COLUMN, self::ALIAS], true)
            || !is_string($name) || $name === '' || preg_match('//u', $name) !== 1
            || preg_match_all('/./su', $name) > self::MAX_LENGTH
            || trim($name) !== $name
            || preg_match('/[\x00-\x1F\x7F]|;|--|\/\*|\*\//', $name) === 1) {
            return false;
        }
        return $kind === self::DATABASE || preg_match(self::NAME, $name) === 1;
    }

    /** The delimited identifier, e.g. `[Order Details]` or `[A]]B]`. */
    public function quoted(): string
    {
        return '[' . str_replace(']', ']]', $this->name) . ']';
    }
}
