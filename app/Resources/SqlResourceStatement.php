<?php

require_once __DIR__ . '/../Requests/ApiRequestException.php';

class SqlResourceStatement
{
    /** Rowset functions that reach other servers or files. */
    private const REMOTE_ROWSETS = ['OPENQUERY', 'OPENROWSET', 'OPENDATASOURCE'];
    /** `{{database:id}}`: a registered logical database, rendered from the registry. */
    private const DATABASE_PLACEHOLDER = '/^\{\{database:([a-z][a-z0-9_-]{0,63})\}\}$/D';

    private string $prefix;
    private string $body;
    private string $suffix;
    private bool $authoredPagination;
    /** The authored body, with placeholders, that source analysis reads. */
    private string $analysisBody;
    /** @var list<string> logical database ids of the placeholders, in order */
    private array $databaseIds = [];

    private function __construct(string $prefix, string $body, string $suffix, bool $authoredPagination, ?string $analysisBody = null)
    {
        $this->prefix = $prefix;
        $this->body = $body;
        $this->suffix = $suffix;
        $this->authoredPagination = $authoredPagination;
        $this->analysisBody = $analysisBody ?? $body;
    }

    public static function analyze(string $sql): self
    {
        $databaseIds = self::assertDatabaseAddressing($sql);
        $tokens = self::topLevelTokens($sql);
        if ($tokens === []) {
            throw new RuntimeException('Approved SQL resources must be read-only queries.');
        }

        $first = $tokens[0];
        if ($first['value'] === 'SELECT') {
            $bodyStart = $first['start'];
        } elseif ($first['value'] === 'WITH') {
            $bodyStart = null;
            foreach (array_slice($tokens, 1) as $token) {
                if ($token['value'] === 'SELECT') {
                    $bodyStart = $token['start'];
                    break;
                }
            }
            if ($bodyStart === null) {
                throw new RuntimeException('Approved SQL resources must be read-only queries.');
            }
        } else {
            throw new RuntimeException('Approved SQL resources must be read-only queries.');
        }

        self::scan($sql, function (string $type, int $start, string $value): void {
            if ($type === 'word' && in_array(strtoupper($value), self::REMOTE_ROWSETS, true)) {
                throw new RuntimeException('Approved SQL resources cannot read remote or ad hoc data sources.');
            }
        }, true);

        $prefix = substr($sql, 0, $bodyStart);
        $body = substr($sql, $bodyStart);
        $bodyTokens = self::topLevelTokens($body);
        foreach ($bodyTokens as $token) {
            if ($token['value'] === 'INTO') {
                throw new RuntimeException('Approved SQL resources must be read-only queries.');
            }
        }
        if (self::hasTopLevelStatementSeparator($body)) {
            throw new RuntimeException('Approved SQL resources must contain one query statement.');
        }

        $suffix = '';
        foreach ($bodyTokens as $token) {
            if ($token['value'] === 'OPTION'
                && preg_match('/^OPTION\s*\(/i', substr($body, $token['start'])) === 1) {
                $suffix = ' ' . ltrim(substr($body, $token['start']));
                $body = rtrim(substr($body, 0, $token['start']));
                $bodyTokens = self::topLevelTokens($body);
                break;
            }
        }

        $authoredPagination = false;
        foreach ($bodyTokens as $token) {
            if (in_array($token['value'], ['OFFSET', 'FETCH'], true)) {
                $authoredPagination = true;
                break;
            }
        }

        $statement = new self($prefix, $body, $suffix, $authoredPagination);
        $statement->databaseIds = $databaseIds;
        foreach ($statement->topLevelSources() as $source) {
            if ($source['server'] !== null) {
                throw new RuntimeException('Approved SQL resources cannot reference linked-server objects.');
            }
        }
        return $statement;
    }

    /** Logical database ids named by `{{database:id}}` placeholders, first use first. */
    public function databaseIds(): array
    {
        return $this->databaseIds;
    }

    /**
     * The statement to execute: each placeholder replaced, at its token
     * position, by the delimited physical name `$quotedDatabase($id)` returns.
     * Source analysis keeps reading the authored text.
     *
     * @param callable(string): string $quotedDatabase
     */
    public function withDatabases(callable $quotedDatabase): self
    {
        if ($this->databaseIds === []) return $this;
        $render = static function (string $sql) use ($quotedDatabase): string {
            $placeholders = [];
            self::scan($sql, function (string $type, int $start, string $value) use (&$placeholders): void {
                if ($type === 'placeholder') $placeholders[] = [$start, $value];
            }, true);
            foreach (array_reverse($placeholders) as [$start, $value]) {
                preg_match(self::DATABASE_PLACEHOLDER, $value, $match);
                $sql = substr($sql, 0, $start) . $quotedDatabase($match[1]) . substr($sql, $start + strlen($value));
            }
            return $sql;
        };
        $statement = new self($render($this->prefix), $render($this->body), $render($this->suffix), $this->authoredPagination, $this->analysisBody);
        $statement->databaseIds = $this->databaseIds;
        return $statement;
    }

    /**
     * Databases are addressed only through `{{database:id}}.schema.object`
     * (or `{{database:id}}..object`). Any other name of three or more parts,
     * such as `Db.dbo.Table`, `[Db].[dbo].[Table]`, `Server.Db.dbo.Table`, or a
     * three-part column reference, is rejected, as is a placeholder used in
     * any other way. Returns the placeholder ids, first use first.
     *
     * @return list<string>
     */
    private static function assertDatabaseAddressing(string $sql): array
    {
        $tokens = [];
        self::scan($sql, function (string $type, int $start, string $value) use (&$tokens): void {
            if (in_array($type, ['word', 'quoted', 'dot', 'placeholder'], true)) {
                $tokens[] = ['type' => $type, 'start' => $start, 'end' => $start + strlen($value), 'value' => $value];
            }
        }, true);
        $ids = [];
        $chain = [];
        $flush = function () use (&$chain, &$ids): void {
            $parts = [];
            $afterDot = false;
            foreach ($chain as $token) {
                if ($token['type'] !== 'dot') {
                    $parts[] = $token;
                    $afterDot = false;
                } elseif ($parts !== []) {
                    if ($afterDot) $parts[] = null;
                    $afterDot = true;
                }
            }
            $chain = [];
            foreach ($parts as $index => $part) {
                if ($part === null || $part['type'] !== 'placeholder') continue;
                if (preg_match(self::DATABASE_PLACEHOLDER, $part['value'], $match) !== 1) {
                    throw new RuntimeException('Approved SQL resources contain an invalid database placeholder.');
                }
                if ($index !== 0 || count($parts) !== 3 || $parts[2] === null) {
                    throw new RuntimeException('A database placeholder must qualify an object as {{database:id}}.schema.object.');
                }
                if (!in_array($match[1], $ids, true)) $ids[] = $match[1];
            }
            if (count($parts) >= 3 && ($parts[0] === null || $parts[0]['type'] !== 'placeholder')) {
                throw new RuntimeException('Approved SQL resources address other databases only through {{database:id}} placeholders.');
            }
        };
        $previous = null;
        foreach ($tokens as $token) {
            $adjacent = $previous !== null
                && ($previous['type'] === 'dot' || $token['type'] === 'dot')
                && trim(substr($sql, $previous['end'], $token['start'] - $previous['end'])) === '';
            if (!$adjacent) $flush();
            $chain[] = $token;
            $previous = $token;
        }
        $flush();
        return $ids;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function suffix(): string
    {
        return $this->suffix;
    }

    public function hasAuthoredPagination(): bool
    {
        return $this->authoredPagination;
    }

    /**
     * Return conservative physical-column candidates from the main SELECT's
     * top-level FROM/JOIN sources. Callers must confirm column existence via
     * database metadata and reject ambiguous matches.
     */
    public function sourceCandidates(string $column, bool $requireDirectProjection = false): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            return [];
        }

        $tokens = self::topLevelTokens($this->analysisBody);
        $fromIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token['value'] === 'FROM') {
                $fromIndex = $index;
                break;
            }
        }
        if ($fromIndex === null) {
            return [];
        }

        $projection = $requireDirectProjection
            ? $this->directProjection($column, $tokens[$fromIndex]['start'])
            : null;
        if ($requireDirectProjection && $projection === null) {
            return [];
        }
        $physicalColumn = $projection['column'] ?? $column;
        $qualifiers = $projection['qualifiers'] ?? null;
        $sources = $this->parseTopLevelSources($tokens, $fromIndex);

        if ($qualifiers !== null && $qualifiers !== []) {
            $sources = array_filter(
                $sources,
                fn (array $source): bool => in_array(strtolower($source['qualifier']), $qualifiers, true)
            );
        }

        return array_values(array_map(
            fn (array $source): array => [
                'table' => $source['table'],
                'schema' => $source['schema'],
                'database' => $source['database'],
                'column' => $physicalColumn,
                'expression' => $source['qualifier'] . '.' . $physicalColumn,
            ],
            $sources
        ));
    }

    /**
     * Physical-or-CTE sources named in the main SELECT's top-level FROM/JOIN
     * clauses: the object name (`table`), its `schema`, `database`, and
     * `server` parts when written (else null), and the effective qualifier.
     */
    public function topLevelSources(): array
    {
        $tokens = self::topLevelTokens($this->analysisBody);
        foreach ($tokens as $index => $token) {
            if ($token['value'] === 'FROM') {
                return array_values($this->parseTopLevelSources($tokens, $index));
            }
        }
        return [];
    }

    private function parseTopLevelSources(array $tokens, int $fromIndex): array
    {
        $from = $tokens[$fromIndex];
        $start = $from['start'] + strlen('FROM');
        $end = strlen($this->analysisBody);
        foreach (array_slice($tokens, $fromIndex + 1) as $token) {
            if (in_array($token['value'], ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'OFFSET', 'FETCH', 'FOR'], true)) {
                $end = $token['start'];
                break;
            }
        }
        $segment = substr($this->analysisBody, $start, $end - $start);
        $starts = [0];
        self::scan($segment, function (string $type, int $position, string $value) use (&$starts): void {
            if ($type === 'comma') {
                $starts[] = $position + 1;
            } elseif ($type === 'word' && strtoupper($value) === 'JOIN') {
                $starts[] = $position + strlen($value);
            }
        });

        $sources = [];
        foreach ($starts as $position) {
            $source = $this->parseSource(substr($segment, $position));
            if ($source === null) {
                continue;
            }
            $key = strtolower($source['table'] . '|' . $source['qualifier']);
            $sources[$key] = $source;
        }
        return $sources;
    }

    private function directProjection(string $field, int $fromPosition): ?array
    {
        $selectEnd = stripos($this->analysisBody, 'SELECT') + strlen('SELECT');
        $projection = substr($this->analysisBody, $selectEnd, $fromPosition - $selectEnd);
        $starts = [0];
        self::scan($projection, function (string $type, int $position) use (&$starts): void {
            if ($type === 'comma') {
                $starts[] = $position + 1;
            }
        });
        $ends = array_map(fn (int $start): int => $start - 1, array_slice($starts, 1));
        $ends[] = strlen($projection);
        $identifier = '(?:[A-Za-z_][A-Za-z0-9_]*|\[[A-Za-z_][A-Za-z0-9_]*\])';

        foreach ($starts as $index => $start) {
            $item = trim(substr($projection, $start, $ends[$index] - $start));
            if ($index === 0) {
                $item = preg_replace('/^(?:DISTINCT\s+)?(?:TOP\s*(?:\(\s*\d+\s*\)|\d+)\s+)?/i', '', $item);
            }
            if (preg_match(
                '/^((?:' . $identifier . '\s*\.\s*)?' . $identifier . ')'
                    . '(?:\s+(?:AS\s+)?(' . $identifier . '))?$/i',
                $item,
                $matches
            ) !== 1) {
                continue;
            }
            $parts = preg_split('/\s*\.\s*/', $matches[1]);
            $parts = array_map(fn (string $part): string => trim($part, '[]'), $parts ?: []);
            $column = end($parts);
            $alias = isset($matches[2]) ? trim($matches[2], '[]') : $column;
            if (!is_string($column) || strcasecmp($alias, $field) !== 0) {
                continue;
            }
            return [
                'column' => $column,
                'qualifiers' => count($parts) === 2 ? [strtolower($parts[0])] : [],
            ];
        }

        return null;
    }

    public function injectMappedFilters(?string $whereCondition, ?string $havingCondition): string
    {
        if ($whereCondition === null && $havingCondition === null) {
            return $this->body;
        }

        $tokens = self::topLevelTokens($this->body);
        foreach ($tokens as $token) {
            if (in_array($token['value'], ['UNION', 'INTERSECT', 'EXCEPT'], true)) {
                throw new ApiRequestException(
                    'Runtime filter placement is ambiguous for this SQL resource.',
                    'INVALID_SQL_RUNTIME_FILTER',
                    [['path' => 'filters', 'message' => 'Use an output filter or a dedicated resource for set-operation branches.']]
                );
            }
        }

        $insertions = [];
        if ($whereCondition !== null) {
            $boundary = $this->firstTokenPosition($tokens, ['GROUP', 'HAVING', 'ORDER', 'OFFSET', 'FETCH', 'FOR']);
            $where = $this->firstTokenPosition($tokens, ['WHERE']);
            $position = $boundary ?? strlen($this->body);
            $insertions[] = [
                'position' => $position,
                'priority' => 0,
                'sql' => $where !== null && $where < $position
                    ? " AND ({$whereCondition}) "
                    : " WHERE {$whereCondition} ",
            ];
        }
        if ($havingCondition !== null) {
            $boundary = $this->firstTokenPosition($tokens, ['ORDER', 'OFFSET', 'FETCH', 'FOR']);
            $having = $this->firstTokenPosition($tokens, ['HAVING']);
            $position = $boundary ?? strlen($this->body);
            $insertions[] = [
                'position' => $position,
                'priority' => 1,
                'sql' => $having !== null && $having < $position
                    ? " AND ({$havingCondition}) "
                    : " HAVING {$havingCondition} ",
            ];
        }

        usort($insertions, function (array $left, array $right): int {
            $positionOrder = $right['position'] <=> $left['position'];
            return $positionOrder !== 0
                ? $positionOrder
                : $right['priority'] <=> $left['priority'];
        });
        $body = $this->body;
        foreach ($insertions as $insertion) {
            $body = substr($body, 0, $insertion['position'])
                . $insertion['sql']
                . substr($body, $insertion['position']);
        }
        return $body;
    }

    private function firstTokenPosition(array $tokens, array $values): ?int
    {
        foreach ($tokens as $index => $token) {
            if (in_array($token['value'], $values, true)) {
                if ($token['value'] === 'FOR'
                    && !in_array($tokens[$index + 1]['value'] ?? null, ['JSON', 'XML', 'BROWSE'], true)) {
                    continue;
                }
                return $token['start'];
            }
        }
        return null;
    }

    private function parseSource(string $sql): ?array
    {
        $identifier = '(?:[A-Za-z_][A-Za-z0-9_]*|\[[A-Za-z_][A-Za-z0-9_]*\]|\{\{database:[a-z][a-z0-9_-]{0,63}\}\})';
        if (preg_match(
            '/^\s*((?:' . $identifier . '\s*\.\s*){0,3}' . $identifier . ')'
                . '(?:\s+(?:AS\s+)?(' . $identifier . '))?/i',
            $sql,
            $matches
        ) !== 1) {
            return null;
        }

        $parts = preg_split('/\s*\.\s*/', $matches[1]);
        // A placeholder part becomes its logical database id.
        $parts = array_map(fn (string $part): string => preg_match(self::DATABASE_PLACEHOLDER, $part, $placeholder) === 1
            ? $placeholder[1] : trim($part, '[]'), $parts ?: []);
        $table = end($parts);
        $alias = isset($matches[2]) ? trim($matches[2], '[]') : null;
        if ($alias !== null && in_array(strtoupper($alias), [
            'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'JOIN', 'ON',
            'WHERE', 'GROUP', 'HAVING', 'ORDER', 'OFFSET', 'FETCH', 'FOR',
        ], true)) {
            $alias = null;
        }
        if (!is_string($table)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1
            || ($alias !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1)) {
            return null;
        }

        // Keep every written part: a database- or server-qualified source must
        // never be mistaken for a table of the connected database.
        $count = count($parts);
        return [
            'table' => $table,
            'schema' => $parts[$count - 2] ?? null,
            'database' => $parts[$count - 3] ?? null,
            'server' => $parts[$count - 4] ?? null,
            'qualifier' => $alias ?? $table,
        ];
    }

    private static function topLevelTokens(string $sql): array
    {
        $tokens = [];
        self::scan($sql, function (string $type, int $start, string $value) use (&$tokens): void {
            if ($type === 'word') {
                $tokens[] = ['value' => strtoupper($value), 'start' => $start];
            }
        });
        return $tokens;
    }

    private static function hasTopLevelStatementSeparator(string $sql): bool
    {
        $found = false;
        self::scan($sql, function (string $type) use (&$found): void {
            if ($type === 'semicolon') {
                $found = true;
            }
        });
        return $found;
    }

    /** Visit top-level tokens, or with $allDepths words inside parentheses too. */
    private static function scan(string $sql, callable $visitor, bool $allDepths = false): void
    {
        $length = strlen($sql);
        $depth = 0;
        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($character === "'") {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== "'") continue;
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                continue;
            }
            if ($character === '"' || $character === '[') {
                $closing = $character === '[' ? ']' : '"';
                $start = $index;
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== $closing) continue;
                    if ($index + 1 < $length && $sql[$index + 1] === $closing) {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                if ($depth === 0 || $allDepths) $visitor('quoted', $start, substr($sql, $start, $index - $start));
                continue;
            }
            if ($character === '{' && $next === '{') {
                // One opaque token, so a placeholder's id is never read as SQL.
                $end = strpos($sql, '}}', $index + 2);
                $start = $index;
                $index = $end === false ? $length : $end + 2;
                if ($depth === 0 || $allDepths) $visitor('placeholder', $start, substr($sql, $start, $index - $start));
                continue;
            }
            if ($character === '.') {
                if ($depth === 0 || $allDepths) $visitor('dot', $index, '.');
                $index++;
                continue;
            }
            if ($character === '-' && $next === '-') {
                $newline = strpos($sql, "\n", $index + 2);
                $index = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;
                continue;
            }
            if ($character === '(') {
                $depth++;
                $index++;
                continue;
            }
            if ($character === ')') {
                $depth = max(0, $depth - 1);
                $index++;
                continue;
            }
            if ($depth === 0 && $character === ';') {
                $visitor('semicolon', $index, ';');
                $index++;
                continue;
            }
            if ($depth === 0 && $character === ',') {
                $visitor('comma', $index, ',');
                $index++;
                continue;
            }
            if (($depth === 0 || $allDepths) && preg_match('/[A-Za-z_]/', $character) === 1) {
                $start = $index;
                while ($index < $length && preg_match('/[A-Za-z0-9_]/', $sql[$index]) === 1) {
                    $index++;
                }
                $visitor('word', $start, substr($sql, $start, $index - $start));
                continue;
            }
            $index++;
        }
    }
}
