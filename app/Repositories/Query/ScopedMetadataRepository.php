<?php

require_once __DIR__ . '/../MetadataRepository.php';
require_once __DIR__ . '/../../Resources/QuerySourcePolicy.php';

/**
 * Adds request-local CTE output metadata without weakening physical-table
 * validation. Every physical table or view must also pass the query-source
 * policy; CTE names stay local to the request and are not registry sources.
 */
class ScopedMetadataRepository extends MetadataRepository
{
    private MetadataRepository $delegate;
    private QuerySourcePolicy $sourcePolicy;
    private array $virtualTables = [];

    public function __construct(MetadataRepository $delegate, ?QuerySourcePolicy $sourcePolicy = null)
    {
        $this->delegate = $delegate;
        $this->sourcePolicy = $sourcePolicy ?? new QuerySourcePolicy();
    }

    public function setVirtualTables(array $tables): void
    {
        $this->virtualTables = $tables;
    }

    public function getVirtualTables(): array
    {
        return $this->virtualTables;
    }

    public function tableExists($table)
    {
        if ($this->findVirtualTable((string)$table) !== null) {
            return true;
        }
        $this->sourcePolicy->assertAllowed((string)$table);
        return $this->delegate->tableExists($table);
    }

    public function columnExists($table, $column)
    {
        $virtualTable = $this->findVirtualTable((string)$table);
        if ($virtualTable === null) {
            $this->sourcePolicy->assertAllowed((string)$table);
            return $this->delegate->columnExists($table, $column);
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
        $this->sourcePolicy->assertAllowed((string)$table);
        return $this->delegate->getColumnDataType($table, $column);
    }

    public function getColumns($table)
    {
        $virtualTable = $this->findVirtualTable((string)$table);
        if ($virtualTable === null) {
            $this->sourcePolicy->assertAllowed((string)$table);
            return $this->delegate->getColumns($table);
        }
        return [
            'data' => array_map(
                fn (string $column): array => ['COLUMN_NAME' => $column],
                $this->virtualTables[$virtualTable]
            ),
        ];
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
