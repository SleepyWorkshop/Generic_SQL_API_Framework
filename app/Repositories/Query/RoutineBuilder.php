<?php

require_once __DIR__ . '/../../Database/MssqlIdentifier.php';

/**
 * Builds routine calls from routines resolved by RoutineResolver. The schema
 * and name are validated identifiers confirmed against database metadata;
 * arguments are always bound.
 */
class RoutineBuilder
{
    public function buildProcedure(array $routine, array $params): array
    {
        $sql = 'EXEC ' . $this->qualifiedName($routine);
        $placeholders = $this->buildPlaceholders($params);
        if ($placeholders !== '') {
            $sql .= ' ' . $placeholders;
        }
        return ['sql' => $sql, 'params' => array_values($params)];
    }

    public function buildFunction(array $routine, array $params): array
    {
        return [
            'sql' => 'SELECT ' . $this->qualifiedName($routine) . '(' . $this->buildPlaceholders($params) . ') AS Result',
            'params' => array_values($params),
        ];
    }

    public function buildTableFunction(array $routine, array $params): array
    {
        return [
            'sql' => 'SELECT * FROM ' . $this->qualifiedName($routine) . '(' . $this->buildPlaceholders($params) . ')',
            'params' => array_values($params),
        ];
    }

    private function qualifiedName(array $routine): string
    {
        foreach (['schema', 'name'] as $part) {
            if (!is_string($routine[$part] ?? null) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $routine[$part]) !== 1) {
                throw new RuntimeException('Invalid routine identifier.');
            }
        }
        return MssqlIdentifier::schema($routine['schema'])->quoted() . '.' . MssqlIdentifier::object($routine['name'])->quoted();
    }

    private function buildPlaceholders(array $params): string
    {
        return count($params) > 0
            ? implode(', ', array_fill(0, count($params), '?'))
            : '';
    }
}
