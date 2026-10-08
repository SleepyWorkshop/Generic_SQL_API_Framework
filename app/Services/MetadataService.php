<?php

require_once __DIR__ . '/../Repositories/MetadataRepository.php';
require_once __DIR__ . '/../Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../Database/SourceResolver.php';

/**
 * Catalog listings for callers holding metadata.read (or frontend access).
 * Results come from the INFORMATION_SCHEMA views of the request's database
 * (its `database`, else the registry default), which is the database the
 * request's connection opened, and so contain only objects the database
 * login can see.
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

    /**
     * Columns of a table; with a schema, of that schema's table only, as a
     * QualifiedObject of the planned database.
     */
    public function getColumns($tableName, ?string $schema = null)
    {
        if ($schema === null) return $this->metadataRepository->getColumns($tableName);
        $plan = DatabaseQueryPlanContext::current();
        if ($plan === null) throw new LogicException('Schema-qualified metadata requires a database query plan.');
        return $this->metadataRepository->objectColumns((new SourceResolver($plan))->qualify(null, $schema, $tableName));
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
