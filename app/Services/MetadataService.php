<?php

require_once __DIR__ . '/../Repositories/MetadataRepository.php';

/**
 * Catalog listings for callers holding metadata.read (or frontend access).
 * Results come from the configured database's INFORMATION_SCHEMA views and so
 * contain only objects the database login can see.
 */
class MetadataService
{
    private MetadataRepository $metadataRepository;

    public function __construct(?MetadataRepository $metadataRepository = null)
    {
        $this->metadataRepository = $metadataRepository ?? new MetadataRepository();
    }

    public function getTables()
    {
        return $this->metadataRepository->getTables();
    }

    public function getColumns($tableName)
    {
        return $this->metadataRepository->getColumns($tableName);
    }

    public function getViews()
    {
        return $this->metadataRepository->getViews();
    }

    public function getProcedures()
    {
        return $this->metadataRepository->getProcedures();
    }

    public function tableExists($table)
    {
        return $this->metadataRepository->tableExists($table);
    }

    public function columnExists($table, $column)
    {
        return $this->metadataRepository->columnExists($table, $column);
    }

    public function schema()
    {
        return $this->metadataRepository->schema();
    }
}
