<?php

require_once __DIR__ . '/DatabaseReference.php';
require_once __DIR__ . '/DatabaseReferenceSet.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Resources/SqlResourceDiscovery.php';
require_once __DIR__ . '/../Resources/SqlResourceStatement.php';

/**
 * Finds the database references of a validated public request: the top-level
 * `database`, `source.database` on the base source, joins, filter subqueries,
 * CTE branches, and set-operation branches, and the `{{database:id}}`
 * placeholders of a SQL Resource.
 *
 * Purely structural: nothing is resolved, decrypted, or queried here; a SQL
 * Resource is read from its server-side file. A source without a database
 * belongs to the request's primary database.
 */
final class DatabaseReferenceCollector
{
    private const SELECT_ACTIONS = ['select', 'union', 'unionAll'];
    private const ROUTINE_ACTIONS = ['procedure', 'function', 'tableFunction'];

    /** @var callable(string): string SQL Resource id → authored SQL */
    private $resourceSql;

    /** @param null|callable(string): string $resourceSql reads a SQL Resource's authored SQL */
    public function __construct(?callable $resourceSql = null)
    {
        $this->resourceSql = $resourceSql ?? [self::class, 'readResource'];
    }

    /** The authored SQL of a discovered SQL Resource. */
    public static function readResource(string $resource): string
    {
        return (string)file_get_contents((new SqlResourceDiscovery())->resolve($resource)['file']);
    }

    public function collect(array $request): DatabaseReferenceSet
    {
        $found = [];
        $requestDatabase = array_key_exists('database', $request)
            ? DatabaseReference::fromRequest($request['database'], 'database')
            : null;
        if ($requestDatabase !== null) $found[$requestDatabase->id] = $requestDatabase;

        $base = null;
        $action = $request['action'] ?? null;
        if ($action === 'select') {
            $base = $this->select($request, '', [], $found);
        } elseif (($action === 'union' || $action === 'unionAll') && is_array($request['queries'] ?? null)) {
            foreach (array_values($request['queries']) as $index => $query) {
                if (!is_array($query)) continue;
                // The first branch is the base source of a set operation.
                $branchBase = $this->select($query, "queries.{$index}.", [], $found);
                if ($index === 0) $base = $branchBase;
            }
        } elseif ($action === 'sql' && is_string($request['resource'] ?? null)) {
            $base = $this->resource($request['resource'], $requestDatabase, $found);
        }
        return new DatabaseReferenceSet($requestDatabase, $base, array_values($found));
    }

    /**
     * The `{{database:id}}` placeholders of a SQL Resource, first use first.
     * The first is the resource's primary database. A resource that names its
     * databases cannot also be pointed at a database by the request.
     *
     * @param array<string, DatabaseReference> $found
     */
    private function resource(string $resource, ?DatabaseReference $requestDatabase, array &$found): ?DatabaseReference
    {
        $ids = self::resourceDatabaseIds(($this->resourceSql)($resource));
        if ($ids !== [] && $requestDatabase !== null) {
            throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', [
                ['path' => 'database', 'message' => 'This SQL resource names its databases; omit database.'],
            ]);
        }
        $base = null;
        foreach ($ids as $id) {
            $reference = DatabaseReference::fromRequest($id, 'resource');
            $base ??= $reference;
            $found[$reference->id] ??= $reference;
        }
        return $base;
    }

    /** @return list<string> placeholder database ids of authored SQL; rejects unsafe addressing */
    public static function resourceDatabaseIds(string $sql): array
    {
        return SqlResourceStatement::analyze(rtrim(trim($sql), "; \t\n\r\0\x0B"))->databaseIds();
    }

    /**
     * The database a request selects before it is validated, for the early
     * availability gate: the top-level database, else the base source
     * database or a SQL Resource's first placeholder database. Null when the
     * request names none, or cannot name one.
     */
    public static function declaredPrimaryId(array $request): ?string
    {
        $action = $request['action'] ?? null;
        if (is_string($action) && (str_starts_with($action, 'metadata.') || in_array($action, self::ROUTINE_ACTIONS, true))) {
            return is_string($request['database'] ?? null) ? $request['database'] : null;
        }
        if ($action === 'sql') {
            if (is_string($request['database'] ?? null)) return $request['database'];
            try {
                return is_string($request['resource'] ?? null)
                    ? (self::resourceDatabaseIds(self::readResource($request['resource']))[0] ?? null)
                    : null;
            } catch (Throwable $exception) {
                // Invalid resources are reported by validation and execution.
                return null;
            }
        }
        if (!in_array($action, self::SELECT_ACTIONS, true)) return null;
        if (is_string($request['database'] ?? null)) return $request['database'];
        $base = $action === 'select' ? $request : (is_array($request['queries'] ?? null) ? (array_values($request['queries'])[0] ?? null) : null);
        $database = is_array($base) && is_array($base['source'] ?? null) ? ($base['source']['database'] ?? null) : null;
        return is_string($database) ? $database : null;
    }

    /**
     * @param list<string> $cteNames request-local CTE names in scope (lower case)
     * @param array<string, DatabaseReference> $found
     */
    private function select(array $body, string $prefix, array $cteNames, array &$found): ?DatabaseReference
    {
        $with = is_array($body['with'] ?? null) ? $body['with'] : null;
        if (is_string($with['name'] ?? null)) $cteNames[] = strtolower($with['name']);

        $base = $this->source($body['source'] ?? null, $prefix . 'source', $cteNames, $found);
        foreach (is_array($body['joins'] ?? null) ? $body['joins'] : [] as $index => $join) {
            if (is_array($join)) $this->source($join['source'] ?? null, $prefix . "joins.{$index}.source", $cteNames, $found);
        }
        foreach (is_array($body['filters'] ?? null) ? $body['filters'] : [] as $index => $filter) {
            if (is_array($filter) && is_array($filter['query'] ?? null)) {
                $this->select($filter['query'], $prefix . "filters.{$index}.query.", $cteNames, $found);
            }
        }
        foreach (['query', 'anchor', 'recursive'] as $branch) {
            if (is_array($with[$branch] ?? null)) {
                $this->select($with[$branch], $prefix . "with.{$branch}.", $cteNames, $found);
            }
        }
        return $base;
    }

    /** @param array<string, DatabaseReference> $found */
    private function source($source, string $path, array $cteNames, array &$found): ?DatabaseReference
    {
        if (!is_array($source)) return null;
        if (is_string($source['table'] ?? null) && in_array(strtolower($source['table']), $cteNames, true)) {
            foreach (['database', 'schema'] as $key) {
                if (array_key_exists($key, $source)) {
                    throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', [
                        ['path' => $path . '.' . $key, 'message' => "A CTE reference is request-local and cannot name a {$key}."],
                    ]);
                }
            }
        }
        if (!array_key_exists('database', $source)) return null;
        $reference = DatabaseReference::fromRequest($source['database'], $path . '.database');
        $found[$reference->id] ??= $reference;
        return $reference;
    }
}
