<?php

require_once __DIR__ . '/DatabaseReference.php';

/**
 * The database references found in one request: the top-level database, the
 * database of the base source, and every distinct database named anywhere,
 * in order of first appearance.
 */
final class DatabaseReferenceSet
{
    /** @param list<DatabaseReference> $references */
    public function __construct(
        public readonly ?DatabaseReference $requestDatabase,
        public readonly ?DatabaseReference $baseSourceDatabase,
        public readonly array $references
    ) {}

    /**
     * The database a request explicitly selects, by priority: the top-level
     * database, then the base source database. Null means the default.
     */
    public function explicitPrimary(): ?DatabaseReference
    {
        return $this->requestDatabase ?? $this->baseSourceDatabase;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (DatabaseReference $reference): string => $reference->id, $this->references);
    }
}
