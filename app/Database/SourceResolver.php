<?php

require_once __DIR__ . '/DatabaseQueryPlan.php';
require_once __DIR__ . '/QualifiedObject.php';
require_once __DIR__ . '/QuerySource.php';
require_once __DIR__ . '/../Resources/DatabaseObjectName.php';
require_once __DIR__ . '/../Security/DatabaseCredentialException.php';

/**
 * Logical request source → physical QualifiedObject, within one query plan.
 *
 * The database is the source's `database`, else the plan's primary database,
 * and must be a database of the plan: the plan holds the only resolved
 * contexts, so a source can never introduce an unplanned database, a
 * physical name, or a server. The schema is the source's `schema`, else none
 * (SQL Server's default schema resolution, as for V2 SELECT sources). Nothing
 * here connects, reads metadata, or builds SQL beyond quoted identifiers.
 */
final class SourceResolver
{
    public function __construct(private DatabaseQueryPlan $plan) {}

    public function plan(): DatabaseQueryPlan { return $this->plan; }

    /**
     * @param array{table: string, alias?: ?string, database?: ?string, schema?: ?string} $source
     * @throws ApiRequestException for an invalid schema, table, or alias
     */
    public function resolve(array $source, string $path = 'source'): QuerySource
    {
        $alias = $source['alias'] ?? null;
        if ($alias !== null && !MssqlIdentifier::isValid(MssqlIdentifier::ALIAS, $alias)) {
            throw DatabaseObjectName::invalid($path . '.alias', 'INVALID_REQUEST', 'Alias must be a single identifier.');
        }
        $object = $this->qualify($source['database'] ?? null, $source['schema'] ?? null, $source['table'] ?? null, $path);
        return QuerySource::physical($source['table'], $alias, $object);
    }

    public function qualify(?string $databaseId, ?string $schema, $object, string $path = 'source'): QualifiedObject
    {
        $database = $databaseId === null ? $this->plan->primaryDatabase : $this->plan->database($databaseId);
        if ($database === null) {
            throw new LogicException('A source database must belong to the query plan.');
        }
        try {
            return DatabaseObjectName::qualify($database, $schema, $object, $path);
        } catch (InvalidArgumentException $exception) {
            // Schema and object are validated above; only the registry's
            // physical database name can fail here.
            throw new DatabaseCredentialException('Invalid database configuration.');
        }
    }
}
