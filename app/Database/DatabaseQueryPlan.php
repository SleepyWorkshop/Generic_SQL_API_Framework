<?php

require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/DatabaseReference.php';

/**
 * The database topology of one request: its primary database, every database
 * it references (primary first), and their shared server profile. Contains no
 * SQL and opens no connection.
 */
final class DatabaseQueryPlan
{
    public readonly string $serverProfileId;
    public readonly bool $isCrossDatabase;
    /** @var list<DatabaseContext> */
    public readonly array $referencedDatabases;

    /**
     * @param list<DatabaseContext> $referencedDatabases
     * @param list<DatabaseReference> $references the references as the request named them
     */
    public function __construct(
        public readonly DatabaseContext $primaryDatabase,
        array $referencedDatabases,
        public readonly array $references
    ) {
        $unique = [];
        foreach ($referencedDatabases as $context) $unique[$context->id] ??= $context;
        if (array_key_first($unique) !== $primaryDatabase->id) {
            throw new LogicException('The primary database must be the first referenced database.');
        }
        foreach ($unique as $context) {
            if (!$context->sharesServerProfileWith($primaryDatabase)) {
                throw new LogicException('Every database of a query plan must belong to one server profile.');
            }
        }
        $this->referencedDatabases = array_values($unique);
        $this->serverProfileId = $primaryDatabase->serverProfileId();
        $this->isCrossDatabase = count($this->referencedDatabases) > 1;
    }

    /** @return list<string> */
    public function databaseIds(): array
    {
        return array_map(static fn (DatabaseContext $context): string => $context->id, $this->referencedDatabases);
    }

    /** The context of a referenced database id, or null. */
    public function database(string $id): ?DatabaseContext
    {
        foreach ($this->referencedDatabases as $context) {
            if ($context->id === $id) return $context;
        }
        return null;
    }

    public function __debugInfo(): array
    {
        return ['primaryDatabase' => $this->primaryDatabase->id, 'referencedDatabases' => $this->databaseIds(),
            'serverProfileId' => $this->serverProfileId, 'isCrossDatabase' => $this->isCrossDatabase];
    }

    public function __serialize(): array
    {
        throw new LogicException('Database query plans cannot be serialized.');
    }
}
