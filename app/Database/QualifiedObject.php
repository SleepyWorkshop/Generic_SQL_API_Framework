<?php

require_once __DIR__ . '/MssqlIdentifier.php';
require_once __DIR__ . '/DatabaseContext.php';

/**
 * A physical SQL Server object: a registered database, an optional schema,
 * and an object name, kept as separate parts. The database comes only from a
 * resolved DatabaseContext. There is no server part: four-part (linked-server)
 * names cannot be represented.
 *
 * A null schema means SQL Server's default schema resolution for the login,
 * which is how unqualified SELECT sources have always resolved.
 */
final class QualifiedObject
{
    private function __construct(
        public readonly string $databaseId,
        private MssqlIdentifier $database,
        private ?MssqlIdentifier $schema,
        private MssqlIdentifier $object
    ) {}

    /** @throws InvalidArgumentException when a part is not a valid identifier */
    public static function in(DatabaseContext $database, ?string $schema, string $object): self
    {
        return new self(
            $database->id,
            MssqlIdentifier::database($database->physicalName()),
            $schema === null ? null : MssqlIdentifier::schema($schema),
            MssqlIdentifier::object($object)
        );
    }

    public function databaseName(): string { return $this->database->name; }
    public function schemaName(): ?string { return $this->schema?->name; }
    public function objectName(): string { return $this->object->name; }

    public function isIn(DatabaseContext $database): bool
    {
        return $this->databaseId === $database->id;
    }

    /** `[Database].[schema].[object]`, or `[Database]..[object]` without a schema. */
    public function render(): string
    {
        return $this->database->quoted() . '.' . ($this->schema?->quoted() ?? '') . '.' . $this->object->quoted();
    }

    /** `[schema].[object]` or `[object]`, for the connection's own database. */
    public function renderLocal(): string
    {
        return ($this->schema === null ? '' : $this->schema->quoted() . '.') . $this->object->quoted();
    }

    public function equals(self $other): bool
    {
        return $this->databaseId === $other->databaseId
            && $this->database->name === $other->database->name
            && $this->schemaName() === $other->schemaName()
            && $this->object->name === $other->object->name;
    }

    public function __debugInfo(): array
    {
        return ['database' => $this->databaseId, 'schema' => $this->schemaName(), 'object' => $this->objectName()];
    }

    public function __serialize(): array
    {
        throw new LogicException('Qualified objects cannot be serialized.');
    }
}
