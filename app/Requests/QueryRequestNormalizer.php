<?php

require_once __DIR__ . '/../Security/SecurityConfiguration.php';

class QueryRequestNormalizer
{
    public function normalize(array $request): array
    {
        $action = $request['action'];
        if ($action === 'sql') {
            return [
                'controller' => 'SQL',
                'action' => 'execute',
                'resource' => $request['resource'],
                ...isset($request['execution']) ? ['execution' => $request['execution']] : [],
                ...isset($request['filters']) ? ['filters' => $request['filters']] : [],
                ...isset($request['sort']) ? ['sort' => $request['sort']] : [],
                ...isset($request['pagination']) ? ['pagination' => $this->pagination($request['pagination'])] : [],
                ...isset($request['filterLogic']) ? ['filterLogic' => strtoupper($request['filterLogic'])] : [],
            ];
        }
        if ($action === 'select') {
            return ['controller' => 'Query', 'action' => 'select'] + $this->normalizeSelect($request);
        }
        if (in_array($action, ['insert', 'update', 'delete', 'upsert'], true)) {
            $normalized = [
                'controller' => 'Write',
                'action' => $action,
                'table' => $request['table'],
            ];
            if (isset($request['data'])) $normalized['data'] = $request['data'];
            if (isset($request['filters'])) {
                $normalized['filters'] = array_map(fn (array $filter): array => [
                    'field' => $filter['field'],
                    'operator' => strtoupper($filter['operator']),
                    ...array_key_exists('value', $filter) ? ['value' => $filter['value']] : [],
                ], $request['filters']);
            }
            if (isset($request['filterLogic'])) $normalized['filterLogic'] = strtoupper($request['filterLogic']);
            if (isset($request['keys'])) $normalized['keys'] = $request['keys'];
            return $normalized;
        }
        if ($action === 'union' || $action === 'unionAll') {
            return [
                'controller' => 'Query',
                'action' => 'union',
                'type' => $action === 'unionAll' ? 'UNION ALL' : 'UNION',
                'queries' => array_map(fn (array $query) => $this->normalizeSelect($query), $request['queries'])
            ];
        }
        if (in_array($action, ['procedure', 'function', 'tableFunction'], true)) {
            $key = $action === 'procedure' ? 'procedure' : 'function';
            return [
                'controller' => 'Query',
                'action' => $action,
                $key => $request['source'][$key],
                'params' => $request['parameters'] ?? []
            ];
        }

        $metadataAction = substr($action, strlen('metadata.'));
        $normalized = ['controller' => 'Metadata', 'action' => $metadataAction];
        if ($metadataAction === 'columns') {
            $normalized['table'] = $request['source']['table'];
        }
        return $normalized;
    }

    private function normalizeSelect(array $request): array
    {
        $normalized = [
            'table' => $request['source']['table'],
            'columns' => array_map(fn ($field) => $this->normalizeField($field), $request['fields'])
        ];
        if (!empty($request['source']['alias'])) { $normalized['alias'] = $request['source']['alias']; }
        $normalized += $this->sourceLocation($request['source']);
        if (array_key_exists('distinct', $request)) { $normalized['distinct'] = $request['distinct']; }
        if (isset($request['limit'])) { $normalized['top'] = $request['limit']; }
        if (isset($request['filterLogic'])) { $normalized['condition'] = $request['filterLogic']; }
        if (isset($request['filters'])) {
            $normalized['where'] = array_map(fn (array $filter) => $this->normalizeFilter($filter), $request['filters']);
        }
        if (isset($request['joins'])) {
            $normalized['joins'] = array_map(function (array $join): array {
                $item = [
                    'type' => strtoupper($join['type']),
                    'table' => $join['source']['table'],
                    'left' => $join['on']['left'],
                    'right' => $join['on']['right']
                ];
                if (!empty($join['source']['alias'])) { $item['alias'] = $join['source']['alias']; }
                return $item + $this->sourceLocation($join['source']);
            }, $request['joins']);
        }
        if (isset($request['groupBy'])) {
            $normalized['groupBy'] = array_map(
                fn ($item) => is_array($item) ? $this->normalizeExpressionNode($item) : $item,
                $request['groupBy']
            );
        }
        if (isset($request['having'])) {
            $normalized['having'] = array_map(function (array $item): array {
                if (isset($item['expression'])) {
                    return [
                        'expression' => $this->normalizeExpressionNode($item['expression']),
                        'operator' => strtoupper($item['operator']),
                        'value' => $item['value'],
                    ];
                }
                return [
                    'function' => $item['function'], 'column' => $item['field'],
                    'operator' => $item['operator'], 'value' => $item['value']
                ];
            }, $request['having']);
        }
        if (isset($request['sort'])) { $normalized['sort'] = $this->normalizeSort($request['sort']); }
        if (isset($request['pagination'])) {
            $normalized['page'] = $request['pagination']['page'];
            $normalized['pageSize'] = $request['pagination']['pageSize']
                ?? SecurityConfiguration::requestOptions()['defaultPageSize'];
        }
        if (isset($request['with'])) {
            $with = $request['with'];
            if (isset($with['anchor'], $with['recursive'])) {
                $normalized['recursiveCte'] = [
                    'name' => $with['name'],
                    'anchor' => $this->normalizeSelect($with['anchor']),
                    'recursive' => $this->normalizeSelect($with['recursive'])
                ];
            } else {
                $normalized['cte'] = [
                    'name' => $with['name'],
                    'query' => $this->normalizeSelect($with['query'])
                ];
            }
        }
        return $normalized;
    }

    /** A source's database id and schema, only when the request names them. */
    private function sourceLocation(array $source): array
    {
        return array_intersect_key($source, ['database' => true, 'schema' => true]);
    }

    private function normalizeField($field)
    {
        if (is_string($field)) { return $field; }
        if ($this->usesRecursiveContract($field)) {
            $node = $field;
            $alias = $node['alias'] ?? null;
            unset($node['alias']);
            return array_filter([
                'node' => $this->normalizeExpressionNode($node),
                'alias' => $alias,
            ], fn ($value) => $value !== null);
        }
        $normalized = $this->normalizeExpressionKeys($field);
        if (isset($normalized['case']['when'])) {
            if (!empty($normalized['alias']) && empty($normalized['case']['alias'])) {
                $normalized['case']['alias'] = $normalized['alias'];
            }
        }
        return $normalized;
    }

    private function normalizeExpressionKeys(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if ($key === 'sort' && is_array($item)) {
                $normalized['orderBy'] = $this->normalizeSort($item);
                continue;
            }
            if ($key === 'partitionBy' && is_array($item)) {
                $normalized['partitionBy'] = array_map(
                    fn ($child) => is_array($child) ? $this->normalizeExpressionNode($child) : $child,
                    $item
                );
                continue;
            }
            $normalizedKey = $key === 'field' ? 'column' : ($key === 'fields' ? 'columns' : $key);
            if (is_array($item)) {
                $item = array_is_list($item)
                    ? array_map(fn ($child) => is_array($child) ? $this->normalizeExpressionKeys($child) : $child, $item)
                    : $this->normalizeExpressionKeys($item);
            }
            $normalized[$normalizedKey] = $item;
        }
        return $normalized;
    }

    private function normalizeFilter(array $filter): array
    {
        $normalized = ['operator' => strtoupper($filter['operator'])];
        if (isset($filter['field'])) { $normalized['column'] = $filter['field']; }
        if (array_key_exists('value', $filter)) { $normalized['value'] = $filter['value']; }
        if (isset($filter['query'])) { $normalized['subquery'] = $this->normalizeSelect($filter['query']); }
        return $normalized;
    }

    private function normalizeSort(array $sort): array
    {
        return array_map(function (array $item): array {
            if (isset($item['expression'])) {
                return [
                    'expression' => $this->normalizeExpressionNode($item['expression']),
                    'direction' => strtoupper($item['direction'] ?? 'ASC'),
                ];
            }
            return [
                'column' => $item['field'],
                'direction' => strtoupper($item['direction'] ?? 'ASC')
            ];
        }, $sort);
    }

    private function usesRecursiveContract(array $field): bool
    {
        if (array_key_exists('literal', $field) || isset($field['unary'])) {
            return true;
        }
        if (isset($field['function']) && is_array($field['field'] ?? null)) {
            return true;
        }
        if (isset($field['expression']) && is_array($field['expression'])) {
            return true;
        }
        if (isset($field['case']['when']) && is_array($field['case']['when'])) {
            return true;
        }
        return false;
    }

    private function normalizeExpressionNode(array $node): array
    {
        if (isset($node['function'])) {
            $normalized = [
                'type' => 'function',
                'name' => strtoupper($node['function']),
                'options' => [],
            ];
            if (array_key_exists('field', $node)) {
                $normalized['input'] = is_array($node['field'])
                    ? $this->normalizeExpressionNode($node['field'])
                    : ['type' => 'field', 'name' => $node['field']];
            }
            foreach ($node as $key => $value) {
                if (in_array($key, ['function', 'field', 'alias'], true)) {
                    continue;
                }
                if ($key === 'sort') {
                    $normalized['orderBy'] = $this->normalizeSort($value);
                } elseif ($key === 'partitionBy') {
                    $normalized['partitionBy'] = array_map(
                        fn ($item) => is_array($item)
                            ? $this->normalizeExpressionNode($item)
                            : ['type' => 'field', 'name' => $item],
                        $value
                    );
                } else {
                    $normalized['options'][$key] = $value;
                }
            }
            return $normalized;
        }
        if (array_key_exists('field', $node)) {
            return ['type' => 'field', 'name' => $node['field']];
        }
        if (array_key_exists('literal', $node)) {
            return ['type' => 'literal', 'value' => $node['literal']];
        }
        if (isset($node['expression'])) {
            return [
                'type' => 'binary',
                'operator' => $node['expression']['operator'],
                'left' => $this->normalizeOperand($node['expression']['left']),
                'right' => $this->normalizeOperand($node['expression']['right']),
            ];
        }
        if (isset($node['unary'])) {
            return [
                'type' => 'unary',
                'operator' => $node['unary']['operator'],
                'operand' => $this->normalizeExpressionNode($node['unary']['operand']),
            ];
        }
        if (isset($node['case'])) {
            $branches = [];
            foreach ($node['case']['when'] as $when) {
                $condition = $when['condition'];
                if (isset($condition['field'])) {
                    $predicate = [
                        'type' => 'predicate',
                        'operator' => strtoupper($condition['operator']),
                        'left' => ['type' => 'field', 'name' => $condition['field']],
                        'right' => ['type' => 'literal', 'value' => $condition['value']],
                    ];
                    $then = ['type' => 'literal', 'value' => $when['then']];
                } else {
                    $predicate = [
                        'type' => 'predicate',
                        'operator' => strtoupper($condition['operator']),
                        'left' => $this->normalizeExpressionNode($condition['left']),
                        'right' => $this->normalizeExpressionNode($condition['right']),
                    ];
                    $then = $this->normalizeExpressionNode($when['then']);
                }
                $branches[] = ['when' => $predicate, 'then' => $then];
            }
            $else = null;
            if (array_key_exists('else', $node['case'])) {
                $else = is_array($node['case']['else'])
                    ? $this->normalizeExpressionNode($node['case']['else'])
                    : ['type' => 'literal', 'value' => $node['case']['else']];
            }
            return ['type' => 'case', 'branches' => $branches, 'else' => $else];
        }
        throw new InvalidArgumentException('Unsupported expression node.');
    }

    private function normalizeOperand($value): array
    {
        if (is_array($value)) {
            return $this->normalizeExpressionNode($value);
        }
        if (is_string($value)) {
            return ['type' => 'field', 'name' => $value];
        }
        return ['type' => 'literal', 'value' => $value];
    }

    private function pagination(array $pagination): array
    {
        return [
            'page' => $pagination['page'],
            'pageSize' => $pagination['pageSize'] ?? SecurityConfiguration::requestOptions()['defaultPageSize'],
        ];
    }
}
