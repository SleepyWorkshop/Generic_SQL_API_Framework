<?php

require_once __DIR__ . '/../Repositories/MetadataRepository.php';
require_once __DIR__ . '/../Resources/QuerySourcePolicy.php';
require_once __DIR__ . '/../Resources/RoutineResourceRegistry.php';

/**
 * Metadata listings expose only what the caller could query: registered query
 * sources for tables, views, columns, and schema rows, and registered
 * procedures for procedure listings.
 */
class MetadataService
{
    private MetadataRepository $metadataRepository;
    private QuerySourcePolicy $sourcePolicy;
    private RoutineResourceRegistry $routines;

    public function __construct(
        ?MetadataRepository $metadataRepository = null,
        ?QuerySourcePolicy $sourcePolicy = null,
        ?RoutineResourceRegistry $routines = null
    ) {
        $this->metadataRepository = $metadataRepository ?? new MetadataRepository();
        $this->sourcePolicy = $sourcePolicy ?? new QuerySourcePolicy();
        $this->routines = $routines ?? new RoutineResourceRegistry();
    }

    /**
     * Get Tables
     */
    public function getTables()
    {
        return $this->filterRows($this->metadataRepository->getTables(), 'TABLE_NAME',
            fn (string $table): bool => $this->sourcePolicy->allows($table));
    }

    /**
     * Get Columns
     */
    public function getColumns($tableName)
    {
        $this->sourcePolicy->assertAllowed((string)$tableName);
        return $this->metadataRepository->getColumns(
            $tableName
        );
    }

    /**
    * Get Views
    */
    public function getViews()
    {
        return $this->filterRows($this->metadataRepository->getViews(), 'TABLE_NAME',
            fn (string $view): bool => $this->sourcePolicy->allows($view));
    }

    /**
    * Get Stored Procedures
    */
    public function getProcedures()
    {
        $registered = [];
        foreach ($this->routines->definitions('procedure') as $routine) {
            $registered[strtolower($routine['name'])] = true;
        }
        return $this->filterRows($this->metadataRepository->getProcedures(), 'ROUTINE_NAME',
            fn (string $procedure): bool => isset($registered[strtolower($procedure)]));
    }

    /**
    * Check Table Exists
    */
    public function tableExists($table)
    {
        return $this->sourcePolicy->allows((string)$table)
            && $this->metadataRepository->tableExists($table);
    }

    /**
    * Check Column Exists
    */
    public function columnExists($table, $column)
    {
        return $this->sourcePolicy->allows((string)$table)
            && $this->metadataRepository->columnExists($table, $column);
    }

    /**
    * Get Schema
    */
    public function schema()
    {
        return $this->filterRows($this->metadataRepository->schema(), 'TABLE_NAME',
            fn (string $table): bool => $this->sourcePolicy->allows($table));
    }

    private function filterRows(array $result, string $column, callable $allowed): array
    {
        $rows = [];
        foreach ($result['data'] ?? [] as $row) {
            $value = null;
            foreach (is_array($row) ? $row : [] as $name => $item) {
                if (strcasecmp((string)$name, $column) === 0) $value = $item;
            }
            if (is_string($value) && $allowed($value)) $rows[] = $row;
        }
        $result['data'] = $rows;
        if (array_key_exists('rowsReturned', $result)) $result['rowsReturned'] = count($rows);
        return $result;
    }
}
