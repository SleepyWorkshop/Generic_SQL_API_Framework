<?php

require_once __DIR__ . '/QueryFunctionRegistry.php';
require_once __DIR__ . '/../../Database/QuerySource.php';

class SqlExpressionBuilder
{
    /** @var array<string, string> name or alias → table key used for metadata lookups */
    private array $tableMap = [];
    /** @var array<string, QuerySource> source reference (alias, else name) → source */
    private array $sources = [];

    public function resetTables(): void
    {
        $this->tableMap = [];
        $this->sources = [];
    }

    public function registerSource(string $reference, QuerySource $source): void
    {
        $this->sources[$reference] = $source;
    }

    /** The planned source a reference names, or null without a database plan. */
    public function source(string $reference): ?QuerySource
    {
        return $this->sources[$reference] ?? null;
    }

    public function getSources(): array
    {
        return $this->sources;
    }

    public function setSources(array $sources): void
    {
        $this->sources = $sources;
    }

    public function getTables(): array
    {
        return $this->tableMap;
    }

    public function setTables(array $tables): void
    {
        $this->tableMap = $tables;
    }

    public function registerTable(string $name, string $table): void
    {
        $this->tableMap[$name] = $table;
    }

    public function resolveColumn(string $column): array
    {
        if (strpos($column, '.') === false) {
            return ['table' => null, 'column' => $column];
        }
        [$alias, $columnName] = explode('.', $column, 2);
        if (!isset($this->tableMap[$alias])) {
            throw new Exception("Unknown table alias: {$alias}");
        }
        return ['table' => $this->tableMap[$alias], 'column' => $columnName];
    }

    public function buildValue($value): string
    {
        if (is_numeric($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return $this->buildExpression($value);
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function buildExpression($expression): string
    {
        if (is_numeric($expression) || is_string($expression)
            || is_bool($expression) || $expression === null) {
            return $this->buildValue($expression);
        }
        if (isset($expression['column'])) {
            $resolved = $this->resolveColumn($expression['column']);
            return !empty($resolved['table'])
                ? $resolved['table'] . '.' . $resolved['column']
                : $resolved['column'];
        }
        if (isset($expression['expression'])) {
            $binary = $expression['expression'];
            if (!array_key_exists('left', $binary)
                || !array_key_exists('right', $binary)
                || !in_array($binary['operator'] ?? null, ['+', '-', '*', '/', '%'], true)) {
                throw new Exception('Invalid arithmetic expression.');
            }
            return '(' . $this->buildExpression($binary['left'])
                . ' ' . $binary['operator'] . ' '
                . $this->buildExpression($binary['right']) . ')';
        }
        throw new Exception('Unsupported expression.');
    }

    public function buildCondition(array $condition): string
    {
        foreach (['left', 'operator', 'right'] as $field) {
            if (!array_key_exists($field, $condition)) {
                throw new Exception("Condition requires {$field}.");
            }
        }
        $allowed = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];
        $operator = strtoupper($condition['operator']);
        if (!in_array($operator, $allowed)) {
            throw new Exception('Invalid condition operator.');
        }
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($condition['right']) || empty($condition['right'])) {
                throw new Exception("{$operator} requires an array.");
            }
            $values = [];
            foreach ($condition['right'] as $value) {
                $values[] = $this->buildValue($value);
            }
            return $this->buildExpression($condition['left']) . " {$operator} ("
                . implode(', ', $values) . ')';
        }
        return $this->buildExpression($condition['left']) . " {$operator} "
            . $this->buildExpression($condition['right']);
    }

    /** Render one validated canonical expression node and append bound values. */
    public function renderNode(array $node, array &$params): string
    {
        return match ($node['type'] ?? null) {
            'field' => $this->renderFieldNode($node),
            'literal' => $this->renderLiteralNode($node, $params),
            'binary' => $this->renderBinaryNode($node, $params),
            'unary' => $this->renderUnaryNode($node, $params),
            'function' => $this->renderFunctionNode($node, $params),
            'case' => $this->renderCaseNode($node, $params),
            default => throw new Exception('Unsupported canonical expression node.'),
        };
    }

    public function fieldNames(array $node): array
    {
        $fields = [];
        $walk = function ($value) use (&$walk, &$fields): void {
            if (!is_array($value)) {
                return;
            }
            if (($value['type'] ?? null) === 'field' && is_string($value['name'] ?? null)) {
                $fields[] = $value['name'];
                return;
            }
            if (isset($value['column']) && is_string($value['column'])) {
                $fields[] = $value['column'];
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($node);
        return array_values(array_unique($fields));
    }

    private function renderFieldNode(array $node): string
    {
        $name = $node['name'] ?? null;
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
            throw new Exception('Invalid expression field.');
        }
        // Metadata resolution is performed by the owning clause builder. Keep
        // the validated logical qualifier here: replacing an alias with the
        // physical table would be invalid once FROM declares that alias.
        return $name;
    }

    private function renderLiteralNode(array $node, array &$params): string
    {
        if (!array_key_exists('value', $node)
            || !(is_int($node['value']) || is_float($node['value'])
                || is_string($node['value']) || is_bool($node['value'])
                || $node['value'] === null)) {
            throw new Exception('Invalid expression literal.');
        }
        $params[] = $node['value'];
        return '?';
    }

    private function renderBinaryNode(array $node, array &$params): string
    {
        if (!in_array($node['operator'] ?? null, ['+', '-', '*', '/', '%'], true)
            || !is_array($node['left'] ?? null) || !is_array($node['right'] ?? null)) {
            throw new Exception('Invalid canonical binary expression.');
        }
        $left = $this->renderNode($node['left'], $params);
        $rightNode = $node['right'];
        // A numeric literal divisor is an expression constant, not a runtime
        // filter/HAVING value. Leaving it untyped can prevent SQL Server/ODBC
        // from inferring the types in aggregate-expression comparisons.
        // Do not inline strings (even numeric strings), booleans, or NULL.
        if ($node['operator'] === '/' && ($rightNode['type'] ?? null) === 'literal'
            && (is_int($rightNode['value'] ?? null) || is_float($rightNode['value'] ?? null))) {
            $value = $rightNode['value'];
            if (is_float($value) && !is_finite($value)) {
                throw new Exception('Numeric expression divisor must be finite.');
            }
            $right = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } else {
            $right = $this->renderNode($rightNode, $params);
        }
        return '(' . $left . ' ' . $node['operator'] . ' ' . $right . ')';
    }

    private function renderUnaryNode(array $node, array &$params): string
    {
        if (!in_array($node['operator'] ?? null, ['+', '-'], true)
            || !is_array($node['operand'] ?? null)) {
            throw new Exception('Invalid canonical unary expression.');
        }
        return '(' . $node['operator'] . $this->renderNode($node['operand'], $params) . ')';
    }

    private function renderCaseNode(array $node, array &$params): string
    {
        if (empty($node['branches']) || !is_array($node['branches'])) {
            throw new Exception('Canonical CASE requires branches.');
        }
        $sql = 'CASE ';
        foreach ($node['branches'] as $branch) {
            $predicate = $branch['when'] ?? null;
            if (!is_array($predicate)
                || ($predicate['type'] ?? null) !== 'predicate'
                || !in_array($predicate['operator'] ?? null, ['=', '!=', '<>', '>', '<', '>=', '<='], true)) {
                throw new Exception('Invalid canonical CASE predicate.');
            }
            $sql .= 'WHEN ' . $this->renderNode($predicate['left'], $params)
                . ' ' . $predicate['operator'] . ' '
                . $this->renderNode($predicate['right'], $params)
                . ' THEN ' . $this->renderNode($branch['then'], $params) . ' ';
        }
        if (is_array($node['else'] ?? null)) {
            $sql .= 'ELSE ' . $this->renderNode($node['else'], $params) . ' ';
        }
        return $sql . 'END';
    }

    private function renderFunctionNode(array $node, array &$params): string
    {
        $name = strtoupper((string)($node['name'] ?? ''));
        if (!QueryFunctionRegistry::supports($name) || QueryFunctionRegistry::isWindow($name)) {
            throw new Exception("Unsupported scalar expression function: {$name}");
        }
        $options = is_array($node['options'] ?? null) ? $node['options'] : [];
        if (in_array($name, ['CHARINDEX', 'PATINDEX'], true)) {
            $key = $name === 'CHARINDEX' ? 'search' : 'pattern';
            $params[] = $options[$key];
            $input = isset($node['input'])
                ? $this->renderNode($node['input'], $params)
                : null;
            if ($name === 'CHARINDEX') {
                return 'CHARINDEX(?, ' . $this->requireInput($name, $input) . ')';
            }
            return 'PATINDEX(?, CAST(' . $this->requireInput($name, $input) . ' AS NVARCHAR(MAX)))';
        }
        $input = isset($node['input']) ? $this->renderNode($node['input'], $params) : null;

        if (in_array($name, ['GETDATE', 'SYSDATETIME'], true)) {
            return $name . '()';
        }
        if ($name === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        if (in_array($name, [
            'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'UPPER', 'LOWER', 'LTRIM',
            'RTRIM', 'TRIM', 'LEN', 'YEAR', 'MONTH', 'DAY', 'ISDATE', 'ABS',
            'CEILING', 'FLOOR', 'SQRT', 'EXP', 'LOG'
        ], true)) {
            return $name . '(' . $this->requireInput($name, $input) . ')';
        }
        if ($name === 'ROUND') {
            return 'ROUND(' . $this->requireInput($name, $input) . ', '
                . (int)($options['precision'] ?? 0) . ')';
        }
        if ($name === 'POWER') {
            return 'POWER(' . $this->requireInput($name, $input) . ', '
                . (float)$options['power'] . ')';
        }
        if (in_array($name, ['LEFT', 'RIGHT'], true)) {
            return $name . '(' . $this->requireInput($name, $input) . ', '
                . (int)$options['length'] . ')';
        }
        if ($name === 'SUBSTRING') {
            return 'SUBSTRING(' . $this->requireInput($name, $input) . ', '
                . (int)$options['start'] . ', ' . (int)$options['length'] . ')';
        }
        if ($name === 'CAST') {
            return 'CAST(' . $this->requireInput($name, $input) . ' AS '
                . strtoupper((string)$options['datatype']) . ')';
        }
        if ($name === 'CONVERT') {
            $sql = 'CONVERT(' . strtoupper((string)$options['datatype']) . ', '
                . $this->requireInput($name, $input);
            if (isset($options['style'])) {
                $sql .= ', ' . (int)$options['style'];
            }
            return $sql . ')';
        }
        if (in_array($name, ['DATEPART', 'DATENAME'], true)) {
            return $name . '(' . strtoupper((string)$options['part']) . ', '
                . $this->requireInput($name, $input) . ')';
        }
        if ($name === 'DATEADD') {
            return 'DATEADD(' . strtoupper((string)$options['datepart']) . ', '
                . (int)$options['number'] . ', ' . $this->requireInput($name, $input) . ')';
        }
        if ($name === 'NULLIF' || $name === 'ISNULL') {
            $key = $name === 'NULLIF' ? 'value' : 'default';
            $params[] = $options[$key];
            return $name . '(' . $this->requireInput($name, $input) . ', ?)';
        }
        if ($name === 'REPLACE') {
            $params[] = $options['search'];
            $params[] = $options['replace'];
            return 'REPLACE(' . $this->requireInput($name, $input) . ', ?, ?)';
        }
        if ($name === 'FORMAT') {
            $params[] = $options['format'];
            return 'FORMAT(' . $this->requireInput($name, $input) . ', ?)';
        }
        if ($name === 'STRING_AGG') {
            $params[] = $options['separator'];
            $sql = 'STRING_AGG(' . $this->requireInput($name, $input) . ', ?)';
            if (!empty($node['orderBy'])) {
                $orders = [];
                foreach ($node['orderBy'] as $order) {
                    $direction = strtoupper($order['direction'] ?? 'ASC');
                    $orderSql = isset($order['expression'])
                        ? $this->renderNode($order['expression'], $params)
                        : (string)$order['column'];
                    $orders[] = $orderSql . ' ' . $direction;
                }
                $sql .= ' WITHIN GROUP (ORDER BY ' . implode(', ', $orders) . ')';
            }
            return $sql;
        }

        throw new Exception("Function {$name} is not expression-renderable.");
    }

    private function requireInput(string $function, ?string $input): string
    {
        if ($input === null) {
            throw new Exception("Function {$function} requires an expression input.");
        }
        return $input;
    }
}
