<?php

require_once __DIR__ . '/../MetadataRepository.php';

/**
 * Adds request-local CTE output metadata without weakening physical-table
 * validation: names that are not CTEs of this request are checked against the
 * database catalog by the delegate. Source names resolved to physical objects
 * are looked up by object (schema-aware); other names by name, as before.
 */
class ScopedMetadataRepository extends MetadataRepository
{
    private MetadataRepository $delegate;
    private array $virtualTables = [];
    /** @var array<string, QualifiedObject> lower-case source name → object */
    private array $physicalSources = [];

    public function __construct(MetadataRepository $delegate)
    {
        $this->delegate = $delegate;
    }

    public function setVirtualTables(array $tables): void
    {
        $this->virtualTables = $tables;
    }

    public function getVirtualTables(): array
    {
        return $this->virtualTables;
    }

    /** @param array<string, QualifiedObject> $sources lower-case source name → object */
    public function setPhysicalSources(array $sources): void
    {
        $this->physicalSources = $sources;
    }

    public function getPhysicalSources(): array
    {
        return $this->physicalSources;
    }

    public function tableExists($table)
    {
        if ($this->findVirtualTable((string)$table) !== null) {
            return true;
        }
        $object = $this->physicalSource($table);
        return $object !== null ? $this->delegate->objectExists($object) : $this->delegate->tableExists($table);
    }

    public function columnExists($table, $column)
    {
        $virtualTable = $this->findVirtualTable((string)$table);
        if ($virtualTable === null) {
            $object = $this->physicalSource($table);
            return $object !== null
                ? $this->delegate->objectColumnExists($object, $column)
                : $this->delegate->columnExists($table, $column);
        }
        foreach ($this->virtualTables[$virtualTable] as $virtualColumn) {
            if (strcasecmp($virtualColumn, (string)$column) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getColumnDataType($table, $column)
    {
        if ($this->findVirtualTable((string)$table) !== null) {
            return null;
        }
        $object = $this->physicalSource($table);
        return $object !== null
            ? $this->delegate->objectColumnDataType($object, $column)
            : $this->delegate->getColumnDataType($table, $column);
    }

    public function getColumns($table)
    {
        $virtualTable = $this->findVirtualTable((string)$table);
        if ($virtualTable === null) {
            $object = $this->physicalSource($table);
            return $object !== null ? $this->delegate->objectColumns($object) : $this->delegate->getColumns($table);
        }
        return [
            'data' => array_map(
                fn (string $column): array => ['COLUMN_NAME' => $column],
                $this->virtualTables[$virtualTable]
            ),
        ];
    }

    /** Only schema-qualified sources need object lookups; others keep name lookups. */
    private function physicalSource($table): ?QualifiedObject
    {
        $object = $this->physicalSources[strtolower((string)$table)] ?? null;
        return $object !== null && $object->schemaName() !== null ? $object : null;
    }

    private function findVirtualTable(string $table): ?string
    {
        foreach (array_keys($this->virtualTables) as $candidate) {
            if (strcasecmp($candidate, $table) === 0) {
                return $candidate;
            }
        }
        return null;
    }
}
