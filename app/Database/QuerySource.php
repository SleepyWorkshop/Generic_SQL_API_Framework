<?php

require_once __DIR__ . '/MssqlIdentifier.php';
require_once __DIR__ . '/QualifiedObject.php';

/**
 * One FROM/JOIN source of a query: the name the request used, its alias, and
 * the physical object it resolves to. A request-local CTE has no physical
 * object. The alias is a query concern and is never part of the object.
 */
final class QuerySource
{
    private const REGULAR_IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private function __construct(
        public readonly string $name,
        public readonly ?string $alias,
        public readonly ?QualifiedObject $object
    ) {
        if ($alias !== null) MssqlIdentifier::alias($alias);
    }

    public static function physical(string $name, ?string $alias, QualifiedObject $object): self
    {
        return new self($name, $alias, $object);
    }

    /** A request-local CTE reference. */
    public static function virtual(string $name, ?string $alias): self
    {
        MssqlIdentifier::object($name);
        return new self($name, $alias, null);
    }

    public function isVirtual(): bool { return $this->object === null; }

    /** The name expressions use for this source: its alias, else its name. */
    public function reference(): string { return $this->alias ?? $this->name; }

    /** A column of this source, e.g. `[p].[ProductID]`. */
    public function column(string $column): string
    {
        return MssqlIdentifier::alias($this->reference())->quoted() . '.' . MssqlIdentifier::column($column)->quoted();
    }

    /**
     * The FROM/JOIN text. Database-qualified: `[Db].[schema].[object] AS [p]`.
     * Otherwise sources without a schema keep their established unqualified
     * form (`Product p`), and schema-qualified sources render `[schema].[object] p`.
     */
    public function renderFrom(bool $databaseQualified = false): string
    {
        if ($databaseQualified && $this->object !== null) {
            return $this->object->render() . ($this->alias === null ? '' : ' AS ' . MssqlIdentifier::alias($this->alias)->quoted());
        }
        $name = $this->object !== null && $this->object->schemaName() !== null
            ? $this->object->renderLocal()
            : $this->localName($this->name, MssqlIdentifier::OBJECT);
        return $name . ($this->alias === null ? '' : ' ' . $this->localName($this->alias, MssqlIdentifier::ALIAS));
    }

    /** Regular identifiers stay bare, as they always rendered; anything else is delimited. */
    private function localName(string $name, string $kind): string
    {
        return preg_match(self::REGULAR_IDENTIFIER, $name) === 1 ? $name : MssqlIdentifier::of($kind, $name)->quoted();
    }

    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'alias' => $this->alias, 'object' => $this->object?->__debugInfo()];
    }
}
