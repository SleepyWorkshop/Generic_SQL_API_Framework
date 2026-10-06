<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/SqlRequestValidator.php';
require_once __DIR__ . '/WriteRequestValidator.php';
require_once __DIR__ . '/../Repositories/Query/QueryFunctionRegistry.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

class QueryRequestValidator
{
    private const MAX_EXPRESSION_DEPTH = 32;
    private const DATATYPES = [
        'BIGINT', 'BINARY', 'BIT', 'CHAR', 'DATE', 'DATETIME', 'DATETIME2',
        'DATETIMEOFFSET', 'DECIMAL', 'FLOAT', 'IMAGE', 'INT', 'MONEY',
        'NCHAR', 'NTEXT', 'NUMERIC', 'NVARCHAR', 'REAL', 'SMALLDATETIME',
        'SMALLINT', 'SMALLMONEY', 'TEXT', 'TIME', 'TINYINT',
        'UNIQUEIDENTIFIER', 'VARBINARY', 'VARCHAR', 'XML',
    ];
    private const ACTIONS = [
        'select', 'sql', 'union', 'unionAll', 'procedure', 'function', 'tableFunction',
        'insert', 'update', 'delete', 'upsert',
        'metadata.tables', 'metadata.columns', 'metadata.views',
        'metadata.procedures', 'metadata.schema'
    ];

    private const OPERATORS = [
        '=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN',
        'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS NULL', 'IS NOT NULL',
        'EXISTS', 'NOT EXISTS'
    ];

    private const FIELD_KEYS = [
        'field', 'fields', 'function', 'alias', 'sort', 'case', 'expression',
        'buckets', 'offset', 'default', 'separator', 'datatype', 'style',
        'value', 'values', 'index', 'datepart', 'number', 'start', 'end',
        'year', 'month', 'day', 'hour', 'minute', 'second', 'millisecond',
        'precision', 'power', 'part', 'length', 'search', 'replace',
        'pattern', 'format', 'condition', 'true', 'false', 'literal', 'unary',
        'partitionBy'
    ];

    public function validate(array $request): void
    {
        $errors = [];
        $action = $request['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            $errors[] = ['path' => 'action', 'message' => 'A supported action is required.'];
        } elseif ($action === 'sql') {
            (new SqlRequestValidator())->validate($request);
            return;
        } elseif (in_array($action, ['insert', 'update', 'delete', 'upsert'], true)) {
            (new WriteRequestValidator())->validate($request);
            return;
        } elseif ($action === 'select') {
            $this->validateSelect($request, '', $errors);
        } elseif ($action === 'union' || $action === 'unionAll') {
            $this->rejectUnknown($request, ['action', 'queries'], '', $errors);
            if (empty($request['queries']) || !is_array($request['queries'])) {
                $errors[] = ['path' => 'queries', 'message' => 'At least one query is required.'];
            } else {
                foreach ($request['queries'] as $index => $query) {
                    if (!is_array($query)) {
                        $errors[] = ['path' => "queries.{$index}", 'message' => 'Query must be an object.'];
                        continue;
                    }
                    $this->validateSelect($query, "queries.{$index}.", $errors, 'set-operation');
                }
                $counts = array_map(
                    fn (array $query): ?int => in_array('*', $query['fields'] ?? [], true)
                        ? null
                        : (is_array($query['fields'] ?? null) ? count($query['fields']) : null),
                    array_filter($request['queries'], 'is_array')
                );
                $knownCounts = array_values(array_filter($counts, fn ($count) => $count !== null));
                if ($knownCounts !== [] && count(array_unique($knownCounts)) > 1) {
                    $errors[] = ['path' => 'queries', 'message' => 'Set-operation branches must return the same number of fields.'];
                }
            }
        } elseif (in_array($action, ['procedure', 'function', 'tableFunction'], true)) {
            $this->rejectUnknown($request, ['action', 'source', 'parameters'], '', $errors);
            $key = $action === 'procedure' ? 'procedure' : 'function';
            $this->validateSourceName($request, $key, $errors);
            if (isset($request['parameters']) && !is_array($request['parameters'])) {
                $errors[] = ['path' => 'parameters', 'message' => 'Parameters must be an array.'];
            }
        } elseif (str_starts_with($action, 'metadata.')) {
            if ($action === 'metadata.columns') {
                $this->rejectUnknown($request, ['action', 'source'], '', $errors);
                $this->validateSourceName($request, 'table', $errors);
            } else {
                $this->rejectUnknown($request, ['action'], '', $errors);
            }
        }

        if ($errors !== []) {
            throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', $errors);
        }
    }

    private function validateSelect(
        array $request,
        string $prefix,
        array &$errors,
        string $context = 'top-level'
    ): void
    {
        $this->rejectUnknown($request, [
            'action', 'source', 'fields', 'filters', 'joins', 'groupBy',
            'having', 'sort', 'pagination', 'distinct', 'limit',
            'filterLogic', 'with'
        ], $prefix, $errors);
        if ($context !== 'top-level') {
            foreach (['action', 'sort', 'pagination', 'with'] as $unsupported) {
                if (array_key_exists($unsupported, $request)) {
                    $errors[] = [
                        'path' => $prefix . $unsupported,
                        'message' => 'This property is not supported in a nested SELECT body.',
                    ];
                }
            }
        }
        $this->validateSourceName($request, 'table', $errors, $prefix);
        if (empty($request['fields']) || !is_array($request['fields'])) {
            $errors[] = ['path' => $prefix . 'fields', 'message' => 'Fields must be a non-empty array.'];
        } else {
            foreach ($request['fields'] as $index => $field) {
                $path = $prefix . "fields.{$index}";
                if (is_string($field)) {
                    if ($field !== '*' && !$this->isIdentifier($field)) {
                        $errors[] = ['path' => $path, 'message' => 'Field must be a valid identifier.'];
                    }
                } elseif (!is_array($field)) {
                    $errors[] = ['path' => $path, 'message' => 'Field must be a string or object.'];
                } else {
                    $this->rejectUnknown($field, self::FIELD_KEYS, $path . '.', $errors);
                    $discriminators = array_values(array_filter(
                        ['field', 'function', 'case', 'expression', 'unary', 'literal'],
                        fn (string $key): bool => array_key_exists($key, $field)
                    ));
                    if (count($discriminators) !== 1
                        && !(isset($field['function']) && array_key_exists('field', $field))) {
                        $errors[] = ['path' => $path, 'message' => 'Field object must contain exactly one expression type.'];
                    }
                    if (!isset($field['function']) && count($discriminators) === 1) {
                        $this->rejectUnknown($field, [$discriminators[0], 'alias'], $path . '.', $errors);
                    }
                    if (isset($field['function'])
                        && (!is_string($field['function'])
                            || !QueryFunctionRegistry::supports($field['function']))) {
                        $errors[] = ['path' => $path . '.function', 'message' => 'Unsupported function.'];
                    } elseif (isset($field['function']) && is_string($field['function'])) {
                        $this->rejectUnknown(
                            $field,
                            array_merge(QueryFunctionRegistry::allowedProperties($field['function']), ['alias']),
                            $path . '.',
                            $errors
                        );
                    }
                    if (isset($field['field']) && $field['field'] !== '*') {
                        if (is_array($field['field']) && isset($field['function'])
                            && is_string($field['function'])
                            && QueryFunctionRegistry::acceptsExpressionInput($field['function'])) {
                            $this->validateExpressionNode(
                                $field['field'],
                                $path . '.field',
                                $errors,
                                1,
                                QueryFunctionRegistry::isAggregate($field['function']) ? 1 : 0
                            );
                        } elseif (!is_string($field['field']) || !$this->isIdentifier($field['field'])) {
                            $errors[] = ['path' => $path . '.field', 'message' => 'Field must be a valid identifier or expression node.'];
                        }
                    }
                    if (isset($field['alias']) && !$this->isIdentifier($field['alias'])) {
                        $errors[] = ['path' => $path . '.alias', 'message' => 'Alias must be a valid identifier.'];
                    }
                    if (!isset($field['field']) && !isset($field['function'])
                        && !isset($field['case']) && !isset($field['expression'])
                        && !array_key_exists('literal', $field) && !isset($field['unary'])) {
                        $errors[] = ['path' => $path, 'message' => 'Field object requires field, function, case, expression, unary, or literal.'];
                    }
                    if (isset($field['fields'])
                        && (!is_array($field['fields'])
                            || array_filter($field['fields'], fn ($item) => !$this->isIdentifier($item)))) {
                        $errors[] = ['path' => $path . '.fields', 'message' => 'Function fields must be valid identifiers.'];
                    }
                    if (isset($field['sort'])) {
                        $this->validateSort($field['sort'], $path . '.sort', $errors);
                    }
                    if (isset($field['partitionBy'])) {
                        $this->validateExpressionList($field['partitionBy'], $path . '.partitionBy', $errors, 'partition');
                    }
                    if (isset($field['function'])
                        && in_array(strtoupper((string)$field['function']), [
                            'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'NTILE', 'LAG',
                            'LEAD', 'FIRST_VALUE', 'LAST_VALUE'
                        ], true)
                        && !isset($field['sort'])) {
                        $errors[] = ['path' => $path . '.sort', 'message' => 'Window functions require sort.'];
                    }
                    if (isset($field['datatype'])
                        && !$this->isDatatype($field['datatype'])) {
                        $errors[] = ['path' => $path . '.datatype', 'message' => 'Datatype is invalid.'];
                    }
                    if (isset($field['expression'])) {
                        $this->validateExpression($field['expression'], $path . '.expression', $errors);
                    }
                    if (isset($field['case'])) {
                        $this->validateCase($field['case'], $path . '.case', $errors);
                    }
                    if (isset($field['unary'])) {
                        $this->validateUnary($field['unary'], $path . '.unary', $errors, 1, 0);
                    }
                    if (array_key_exists('literal', $field) && !$this->isLiteral($field['literal'])) {
                        $errors[] = ['path' => $path . '.literal', 'message' => 'Literal must be a JSON scalar or null.'];
                    }
                    if (isset($field['function']) && is_string($field['function'])
                        && QueryFunctionRegistry::supports($field['function'])) {
                        $this->validateFunctionField($field, $path, $errors);
                    }
                }
            }
        }

        if (isset($request['filters'])) {
            $this->validateFilters($request['filters'], $prefix . 'filters', $errors);
        }
        if (isset($request['sort'])) {
            $this->validateSort($request['sort'], $prefix . 'sort', $errors);
        }
        if (isset($request['pagination'])) {
            $pagination = $request['pagination'];
            if (!is_array($pagination)) {
                $errors[] = ['path' => $prefix . 'pagination', 'message' => 'Pagination must be an object.'];
            } else {
                $this->rejectUnknown($pagination, ['page', 'pageSize'], $prefix . 'pagination.', $errors);
                if (!array_key_exists('page', $pagination) || !is_int($pagination['page']) || $pagination['page'] < 1) {
                    $errors[] = ['path' => $prefix . 'pagination.page', 'message' => 'Must be a positive integer.'];
                }
                if (array_key_exists('pageSize', $pagination)
                    && (!is_int($pagination['pageSize']) || $pagination['pageSize'] < 1)) {
                    $errors[] = ['path' => $prefix . 'pagination.pageSize', 'message' => 'Must be a positive integer.'];
                } elseif (($pagination['pageSize'] ?? 1) > SecurityConfiguration::requestOptions()['maxPageSize']) {
                    $errors[] = ['path' => $prefix . 'pagination.pageSize', 'message' => 'Page size exceeds the configured maximum.'];
                }
            }
        }
        if (isset($request['groupBy'])) {
            $this->validateExpressionList($request['groupBy'], $prefix . 'groupBy', $errors, 'group');
        }
        if (isset($request['joins'])) {
            if (!is_array($request['joins'])) {
                $errors[] = ['path' => $prefix . 'joins', 'message' => 'Joins must be an array.'];
            } else {
                foreach ($request['joins'] as $index => $join) {
                    $path = $prefix . "joins.{$index}";
                    if (!is_array($join) || !in_array(strtoupper((string)($join['type'] ?? '')), ['INNER', 'LEFT', 'RIGHT'], true)) {
                        $errors[] = ['path' => $path . '.type', 'message' => 'Join type must be INNER, LEFT, or RIGHT.'];
                    }
                    if (!is_array($join)) { continue; }
                    $this->rejectUnknown($join, ['type', 'source', 'on'], $path . '.', $errors);
                    $this->validateSourceName($join, 'table', $errors, $path . '.');
                    $on = $join['on'] ?? null;
                    if (!is_array($on) || ($on['operator'] ?? '=') !== '='
                        || !$this->isIdentifier($on['left'] ?? null)
                        || !$this->isIdentifier($on['right'] ?? null)) {
                        $errors[] = ['path' => $path . '.on', 'message' => 'Join on requires valid left/right fields and the = operator.'];
                    } else {
                        $this->rejectUnknown($on, ['left', 'operator', 'right'], $path . '.on.', $errors);
                    }
                }
            }
        }
        if (isset($request['having'])) {
            if (!is_array($request['having'])) {
                $errors[] = ['path' => $prefix . 'having', 'message' => 'Having must be an array.'];
            } else {
                foreach ($request['having'] as $index => $condition) {
                    $path = $prefix . "having.{$index}";
                    if (!is_array($condition)) {
                        $errors[] = ['path' => $path, 'message' => 'Invalid HAVING condition.'];
                        continue;
                    }
                    if (isset($condition['expression'])) {
                        $this->rejectUnknown($condition, ['expression', 'operator', 'value'], $path . '.', $errors);
                        $this->validateExpressionNode($condition['expression'], $path . '.expression', $errors, 1, 0, 'having');
                        if (!$this->containsAggregate($condition['expression'])) {
                            $errors[] = ['path' => $path . '.expression', 'message' => 'HAVING expression must contain an aggregate function.'];
                        }
                        if (!in_array(strtoupper((string)($condition['operator'] ?? '')), ['=', '!=', '<>', '>', '<', '>=', '<='], true)) {
                            $errors[] = ['path' => $path . '.operator', 'message' => 'Unsupported HAVING operator.'];
                        }
                        if (!array_key_exists('value', $condition) || !$this->isLiteral($condition['value'] ?? null)) {
                            $errors[] = ['path' => $path . '.value', 'message' => 'HAVING value must be a JSON scalar or null.'];
                        }
                    } else {
                        $this->rejectUnknown($condition, ['function', 'field', 'operator', 'value'], $path . '.', $errors);
                        if (!in_array(strtoupper((string)($condition['function'] ?? '')), ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG'], true)
                            || (($condition['field'] ?? null) !== '*' && !$this->isIdentifier($condition['field'] ?? null))
                            || !in_array(strtoupper((string)($condition['operator'] ?? '')), ['=', '!=', '<>', '>', '<', '>=', '<='], true)
                            || !array_key_exists('value', $condition)
                            || !$this->isLiteral($condition['value'] ?? null)) {
                            $errors[] = ['path' => $path, 'message' => 'Invalid aggregate HAVING condition.'];
                        }
                    }
                }
            }
        }
        if (isset($request['limit']) && (!is_int($request['limit']) || $request['limit'] < 1)) {
            $errors[] = ['path' => $prefix . 'limit', 'message' => 'Limit must be a positive integer.'];
        }
        if (isset($request['distinct']) && !is_bool($request['distinct'])) {
            $errors[] = ['path' => $prefix . 'distinct', 'message' => 'Distinct must be a boolean.'];
        }
        if (isset($request['filterLogic']) && !in_array($request['filterLogic'], ['AND', 'OR'], true)) {
            $errors[] = ['path' => $prefix . 'filterLogic', 'message' => 'Filter logic must be AND or OR.'];
        }
        if (isset($request['with'])) {
            $this->validateWith($request['with'], $prefix . 'with', $errors);
        }
    }

    private function validateFilters($filters, string $path, array &$errors): void
    {
        if (!is_array($filters)) {
            $errors[] = ['path' => $path, 'message' => 'Filters must be an array.'];
            return;
        }
        foreach ($filters as $index => $filter) {
            $itemPath = "{$path}.{$index}";
            if (!is_array($filter)) {
                $errors[] = ['path' => $itemPath, 'message' => 'Filter must be an object.'];
                continue;
            }
            $this->rejectUnknown($filter, ['field', 'operator', 'value', 'query'], $itemPath . '.', $errors);
            $operator = strtoupper((string)($filter['operator'] ?? ''));
            if (!in_array($operator, self::OPERATORS, true)) {
                $errors[] = ['path' => $itemPath . '.operator', 'message' => 'Unsupported filter operator.'];
                continue;
            }
            if (!in_array($operator, ['EXISTS', 'NOT EXISTS'], true)
                && !$this->isIdentifier($filter['field'] ?? null)) {
                $errors[] = ['path' => $itemPath . '.field', 'message' => 'Field must be a valid identifier.'];
            }
            if (in_array($operator, ['EXISTS', 'NOT EXISTS'], true)) {
                if (!is_array($filter['query'] ?? null)) {
                    $errors[] = ['path' => $itemPath . '.query', 'message' => 'A subquery is required.'];
                } else {
                    $this->validateSelect($filter['query'], $itemPath . '.query.', $errors, 'subquery');
                }
                continue;
            }
            if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                if (isset($filter['query'])) {
                    $errors[] = ['path' => $itemPath . '.query', 'message' => 'This operator does not support a subquery.'];
                }
                continue;
            }
            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                if (isset($filter['query'])) {
                    if (!is_array($filter['query'])) {
                        $errors[] = ['path' => $itemPath . '.query', 'message' => 'Subquery must be an object.'];
                    } else {
                        $this->validateSelect($filter['query'], $itemPath . '.query.', $errors, 'subquery');
                        if (count($filter['query']['fields'] ?? []) !== 1
                            || in_array('*', $filter['query']['fields'] ?? [], true)) {
                            $errors[] = [
                                'path' => $itemPath . '.query.fields',
                                'message' => 'IN subqueries must return exactly one explicit field.',
                            ];
                        }
                    }
                } elseif (!isset($filter['value']) || !is_array($filter['value']) || $filter['value'] === []
                    || array_filter($filter['value'], fn ($value) => !$this->isLiteral($value)) !== []) {
                    $errors[] = ['path' => $itemPath . '.value', 'message' => 'IN requires a non-empty value array or query.'];
                }
                continue;
            }
            if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
                if (!isset($filter['value']) || !is_array($filter['value']) || count($filter['value']) !== 2
                    || array_filter($filter['value'], fn ($value) => !$this->isLiteral($value)) !== []) {
                    $errors[] = ['path' => $itemPath . '.value', 'message' => 'BETWEEN requires exactly two values.'];
                }
                if (isset($filter['query'])) {
                    $errors[] = ['path' => $itemPath . '.query', 'message' => 'BETWEEN does not support a subquery.'];
                }
                continue;
            }
            if (!array_key_exists('value', $filter)) {
                $errors[] = ['path' => $itemPath . '.value', 'message' => 'A value is required.'];
            } elseif (!$this->isLiteral($filter['value'])) {
                $errors[] = ['path' => $itemPath . '.value', 'message' => 'Filter value must be a JSON scalar or null.'];
            }
            if (isset($filter['query'])) {
                $errors[] = ['path' => $itemPath . '.query', 'message' => 'This operator does not support a subquery.'];
            }
        }
    }

    private function validateWith($with, string $path, array &$errors): void
    {
        if (!is_array($with)) {
            $errors[] = ['path' => $path, 'message' => 'With must be an object.'];
            return;
        }
        // CTE names are request-local virtual tables, never qualified objects.
        if (!is_string($with['name'] ?? null) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $with['name']) !== 1) {
            $errors[] = ['path' => $path . '.name', 'message' => 'CTE name must be a valid identifier.'];
        }

        $isRecursive = array_key_exists('anchor', $with) || array_key_exists('recursive', $with);
        $this->rejectUnknown(
            $with,
            $isRecursive ? ['name', 'anchor', 'recursive'] : ['name', 'query'],
            $path . '.',
            $errors
        );
        $branches = $isRecursive ? ['anchor', 'recursive'] : ['query'];
        foreach ($branches as $branch) {
            if (!is_array($with[$branch] ?? null)) {
                $errors[] = ['path' => $path . '.' . $branch, 'message' => 'CTE branch must be a SELECT body.'];
                continue;
            }
            $this->validateSelect($with[$branch], $path . '.' . $branch . '.', $errors, 'cte');
        }
        if ($isRecursive
            && is_array($with['anchor'] ?? null)
            && is_array($with['recursive'] ?? null)
            && !in_array('*', $with['anchor']['fields'] ?? [], true)
            && !in_array('*', $with['recursive']['fields'] ?? [], true)
            && count($with['anchor']['fields'] ?? []) !== count($with['recursive']['fields'] ?? [])) {
            $errors[] = [
                'path' => $path,
                'message' => 'Recursive CTE branches must return the same number of fields.',
            ];
        }
    }

    private function validateFunctionField(array $field, string $path, array &$errors): void
    {
        $function = strtoupper($field['function']);
        $columnFunctions = [
            'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG',
            'UPPER', 'LOWER', 'LTRIM', 'RTRIM', 'TRIM', 'LEN', 'ISNULL',
            'CAST', 'CONVERT', 'NULLIF', 'LEFT', 'RIGHT', 'SUBSTRING',
            'REPLACE', 'CHARINDEX', 'PATINDEX', 'FORMAT', 'YEAR', 'MONTH',
            'DAY', 'DATEPART', 'DATENAME', 'DATEADD', 'ISDATE', 'ABS', 'ROUND',
            'CEILING', 'FLOOR', 'POWER', 'SQRT', 'EXP', 'LOG', 'LAG', 'LEAD',
            'FIRST_VALUE', 'LAST_VALUE',
        ];
        if (in_array($function, $columnFunctions, true)
            && !isset($field['field'])) {
            $errors[] = ['path' => $path . '.field', 'message' => "{$function} requires a field."];
        }
        if ($function !== 'COUNT' && ($field['field'] ?? null) === '*') {
            $errors[] = ['path' => $path . '.field', 'message' => "{$function} does not accept *."];
        }

        if ($function === 'STRING_AGG' && !is_string($field['separator'] ?? null)) {
            $errors[] = ['path' => $path . '.separator', 'message' => 'STRING_AGG requires a string separator.'];
        }
        if ($function === 'COALESCE'
            && (!is_array($field['fields'] ?? null) || $field['fields'] === [])) {
            $errors[] = ['path' => $path . '.fields', 'message' => 'COALESCE requires at least one field.'];
        }
        if ($function === 'CONCAT'
            && (!is_array($field['fields'] ?? null) || count($field['fields']) < 2)) {
            $errors[] = ['path' => $path . '.fields', 'message' => 'CONCAT requires at least two fields.'];
        }
        foreach (['ISNULL' => 'default', 'NULLIF' => 'value'] as $name => $key) {
            if ($function === $name && !array_key_exists($key, $field)) {
                $errors[] = ['path' => $path . '.' . $key, 'message' => "{$name} requires {$key}."];
            } elseif ($function === $name && !$this->isSafeFunctionExpression($field[$key])) {
                $errors[] = ['path' => $path . '.' . $key, 'message' => "{$name} requires a safe {$key} expression."];
            }
        }
        if ($function === 'COALESCE' && isset($field['default'])
            && !$this->isSafeFunctionExpression($field['default'])) {
            $errors[] = ['path' => $path . '.default', 'message' => 'COALESCE requires a safe default expression.'];
        }
        if ($function === 'REPLACE') {
            foreach (['search', 'replace'] as $key) {
                if (!is_string($field[$key] ?? null)) {
                    $errors[] = ['path' => $path . '.' . $key, 'message' => "REPLACE requires a string {$key}."];
                }
            }
        }
        foreach (['CHARINDEX' => 'search', 'PATINDEX' => 'pattern', 'FORMAT' => 'format'] as $name => $key) {
            if ($function === $name && !is_string($field[$key] ?? null)) {
                $errors[] = ['path' => $path . '.' . $key, 'message' => "{$name} requires a string {$key}."];
            }
        }

        if (in_array($function, ['LEFT', 'RIGHT'], true)) {
            $this->requirePositiveInteger($field, 'length', $path, $errors, $function);
        }
        if ($function === 'SUBSTRING') {
            $this->requirePositiveInteger($field, 'start', $path, $errors, $function);
            $this->requirePositiveInteger($field, 'length', $path, $errors, $function, true);
        }
        if (in_array($function, ['CAST', 'CONVERT'], true) && empty($field['datatype'])) {
            $errors[] = ['path' => $path . '.datatype', 'message' => "{$function} requires datatype."];
        }
        if (isset($field['style']) && !is_int($field['style'])) {
            $errors[] = ['path' => $path . '.style', 'message' => 'Style must be an integer.'];
        }

        $dateParts = [
            'YEAR', 'QUARTER', 'MONTH', 'DAYOFYEAR', 'DAY', 'WEEK', 'WEEKDAY',
            'HOUR', 'MINUTE', 'SECOND', 'MILLISECOND',
        ];
        if (in_array($function, ['DATEPART', 'DATENAME'], true)
            && (!is_string($field['part'] ?? null)
                || !in_array(strtoupper($field['part']), $dateParts, true))) {
            $errors[] = ['path' => $path . '.part', 'message' => "{$function} requires a supported date part."];
        }
        if ($function === 'DATEADD') {
            if (!is_string($field['datepart'] ?? null)
                || !in_array(strtoupper($field['datepart']), ['YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND'], true)) {
                $errors[] = ['path' => $path . '.datepart', 'message' => 'DATEADD requires a supported date part.'];
            }
            if (!is_int($field['number'] ?? null)) {
                $errors[] = ['path' => $path . '.number', 'message' => 'DATEADD number must be an integer.'];
            }
        }
        if ($function === 'DATEDIFF') {
            if (!is_string($field['datepart'] ?? null)
                || !in_array(strtoupper($field['datepart']), $dateParts, true)) {
                $errors[] = ['path' => $path . '.datepart', 'message' => 'DATEDIFF requires a supported date part.'];
            }
            foreach (['start', 'end'] as $endpoint) {
                $this->validateDateEndpoint($field[$endpoint] ?? null, $path . '.' . $endpoint, $errors);
            }
        }
        if ($function === 'EOMONTH') {
            $this->validateDateEndpoint($field['start'] ?? null, $path . '.start', $errors);
            if (isset($field['month']) && !is_int($field['month'])) {
                $errors[] = ['path' => $path . '.month', 'message' => 'EOMONTH month must be an integer.'];
            }
        }

        $partsFunctions = [
            'DATEFROMPARTS' => ['year', 'month', 'day'],
            'DATETIMEFROMPARTS' => ['year', 'month', 'day', 'hour', 'minute', 'second', 'millisecond'],
        ];
        foreach ($partsFunctions[$function] ?? [] as $part) {
            if (!array_key_exists($part, $field)
                || !$this->isSafeNumericFunctionExpression($field[$part])) {
                $errors[] = ['path' => $path . '.' . $part, 'message' => "{$function} requires a safe {$part} expression."];
            }
        }

        if ($function === 'IIF') {
            $condition = $field['condition'] ?? null;
            $operator = strtoupper((string)($condition['operator'] ?? ''));
            $rightIsValid = in_array($operator, ['IN', 'NOT IN'], true)
                ? is_array($condition['right'] ?? null)
                    && $condition['right'] !== []
                    && array_filter(
                        $condition['right'],
                        fn ($value) => !$this->isSafeFunctionExpression($value)
                    ) === []
                : $this->isSafeFunctionExpression($condition['right'] ?? null);
            if (!is_array($condition)
                || array_diff(array_keys($condition), ['left', 'operator', 'right']) !== []
                || !in_array($operator, ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'], true)
                || !$this->isSafeFunctionExpression($condition['left'] ?? null)
                || !$rightIsValid) {
                $errors[] = ['path' => $path . '.condition', 'message' => 'IIF requires a valid condition.'];
            }
            foreach (['true', 'false'] as $branch) {
                if (!array_key_exists($branch, $field)
                    || !$this->isSafeFunctionExpression($field[$branch])) {
                    $errors[] = ['path' => $path . '.' . $branch, 'message' => "IIF requires a safe {$branch} expression."];
                }
            }
        }
        if ($function === 'CHOOSE') {
            $this->requirePositiveInteger($field, 'index', $path, $errors, $function);
            if (!is_array($field['values'] ?? null) || count($field['values']) < 2
                || array_filter($field['values'] ?? [], fn ($value) => !$this->isSafeFunctionExpression($value))) {
                $errors[] = ['path' => $path . '.values', 'message' => 'CHOOSE requires at least two safe values.'];
            }
        }

        if ($function === 'NTILE') {
            $this->requirePositiveInteger($field, 'buckets', $path, $errors, $function);
        }
        if (in_array($function, ['LAG', 'LEAD'], true) && isset($field['offset'])) {
            $this->requirePositiveInteger($field, 'offset', $path, $errors, $function);
        }
        if (in_array($function, ['LAG', 'LEAD'], true) && isset($field['default'])
            && !$this->isSafeFunctionExpression($field['default'])) {
            $errors[] = ['path' => $path . '.default', 'message' => "{$function} requires a safe default expression."];
        }
        if ($function === 'ROUND' && isset($field['precision']) && !is_int($field['precision'])) {
            $errors[] = ['path' => $path . '.precision', 'message' => 'ROUND precision must be an integer.'];
        }
        if ($function === 'POWER' && !is_int($field['power'] ?? null) && !is_float($field['power'] ?? null)) {
            $errors[] = ['path' => $path . '.power', 'message' => 'POWER requires a numeric power.'];
        }
    }

    private function validateDateEndpoint($endpoint, string $path, array &$errors): void
    {
        $valid = false;
        if (is_array($endpoint) && isset($endpoint['field'])) {
            $valid = $this->isIdentifier($endpoint['field'])
                && array_diff(array_keys($endpoint), ['field', 'style']) === []
                && (!isset($endpoint['style']) || is_int($endpoint['style']));
        } elseif (is_array($endpoint) && isset($endpoint['function'])) {
            $valid = strtoupper((string)$endpoint['function']) === 'GETDATE'
                && array_keys($endpoint) === ['function'];
        }
        if (!$valid) {
            $errors[] = ['path' => $path, 'message' => 'Date endpoint must contain a field or GETDATE function.'];
        }
    }

    private function isSafeFunctionExpression($value): bool
    {
        if (is_int($value) || is_float($value) || is_string($value)
            || is_bool($value) || $value === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        if (isset($value['field'])) {
            return count($value) === 1 && $this->isIdentifier($value['field']);
        }
        $expression = $value['expression'] ?? null;
        return count($value) === 1 && is_array($expression)
            && in_array($expression['operator'] ?? null, ['+', '-', '*', '/', '%'], true)
            && $this->isSafeFunctionExpression($expression['left'] ?? null)
            && $this->isSafeFunctionExpression($expression['right'] ?? null);
    }

    private function isSafeNumericFunctionExpression($value): bool
    {
        return is_int($value) || is_float($value)
            || (is_array($value) && $this->isSafeFunctionExpression($value));
    }

    private function requirePositiveInteger(
        array $field,
        string $key,
        string $path,
        array &$errors,
        string $function,
        bool $allowZero = false
    ): void {
        $value = $field[$key] ?? null;
        if (!is_int($value) || $value < ($allowZero ? 0 : 1)) {
            $errors[] = [
                'path' => $path . '.' . $key,
                'message' => "{$function} {$key} must be " . ($allowZero ? 'a non-negative' : 'a positive') . ' integer.',
            ];
        }
    }

    private function validateSort($sort, string $path, array &$errors, int $depth = 1): void
    {
        if (!is_array($sort)) {
            $errors[] = ['path' => $path, 'message' => 'Sort must be an array.'];
            return;
        }
        foreach ($sort as $index => $item) {
            if (is_array($item)) {
                $this->rejectUnknown($item, ['field', 'expression', 'direction'], "{$path}.{$index}.", $errors);
            }
            if (!is_array($item)) {
                $errors[] = ['path' => "{$path}.{$index}", 'message' => 'Sort requires a logical field and ASC or DESC direction.'];
                continue;
            }
            $hasField = array_key_exists('field', $item);
            $hasExpression = array_key_exists('expression', $item);
            if ($hasField === $hasExpression) {
                $errors[] = ['path' => "{$path}.{$index}", 'message' => 'Sort requires exactly one field or expression.'];
            } elseif ($hasField && (!$this->isIdentifier($item['field']) || preg_match('/^[0-9]+$/D', (string)$item['field']) === 1)) {
                $errors[] = ['path' => "{$path}.{$index}.field", 'message' => 'Sort field must be a non-positional logical identifier.'];
            } elseif ($hasExpression) {
                $this->validateExpressionNode($item['expression'], "{$path}.{$index}.expression", $errors, $depth + 1, 0, 'order');
            }
            if (!in_array(strtoupper((string)($item['direction'] ?? 'ASC')), ['ASC', 'DESC'], true)) {
                $errors[] = ['path' => "{$path}.{$index}.direction", 'message' => 'Sort direction must be ASC or DESC.'];
            }
        }
    }

    private function validateExpression($expression, string $path, array &$errors): void
    {
        if (!is_array($expression)) {
            $errors[] = ['path' => $path, 'message' => 'Expression must be an object.'];
            return;
        }
        $this->rejectUnknown($expression, ['left', 'operator', 'right'], $path . '.', $errors);
        if (!in_array($expression['operator'] ?? null, ['+', '-', '*', '/', '%'], true)) {
            $errors[] = ['path' => $path . '.operator', 'message' => 'Unsupported expression operator.'];
        }
        $legacy = true;
        foreach (['left', 'right'] as $side) {
            $value = $expression[$side] ?? null;
            if (!is_int($value) && !is_float($value) && !$this->isIdentifier($value)) {
                $legacy = false;
            }
        }
        if ($legacy) {
            return;
        }
        foreach (['left', 'right'] as $side) {
            if (!is_array($expression[$side] ?? null)) {
                $errors[] = ['path' => $path . '.' . $side, 'message' => 'Recursive operands must use explicit expression nodes.'];
                continue;
            }
            $this->validateExpressionNode($expression[$side], $path . '.' . $side, $errors, 1, 0);
        }
    }

    private function validateCase(
        $case,
        string $path,
        array &$errors,
        int $depth = 1,
        int $aggregateDepth = 0,
        string $context = 'case'
    ): void
    {
        if (!is_array($case)) {
            $errors[] = ['path' => $path, 'message' => 'Case must be an object.'];
            return;
        }
        $this->rejectUnknown($case, ['when', 'else'], $path . '.', $errors);
        if (empty($case['when']) || !is_array($case['when'])) {
            $errors[] = ['path' => $path . '.when', 'message' => 'Case requires at least one WHEN clause.'];
            return;
        }
        foreach ($case['when'] as $index => $when) {
            $itemPath = $path . '.when.' . $index;
            if (!is_array($when) || !is_array($when['condition'] ?? null)) {
                $errors[] = ['path' => $itemPath, 'message' => 'Case WHEN requires a condition and then value.'];
                continue;
            }
            $this->rejectUnknown($when, ['condition', 'then'], $itemPath . '.', $errors);
            $condition = $when['condition'];
            if (array_key_exists('field', $condition)) {
                $this->rejectUnknown($condition, ['field', 'operator', 'value'], $itemPath . '.condition.', $errors);
                if (!$this->isIdentifier($condition['field'] ?? null)
                    || !in_array(strtoupper((string)($condition['operator'] ?? '')), ['=', '!=', '<>', '>', '<', '>=', '<='], true)
                    || !array_key_exists('value', $condition)
                    || !array_key_exists('then', $when)
                    || !$this->isLiteral($condition['value'] ?? null)
                    || !$this->isLiteral($when['then'] ?? null)) {
                    $errors[] = ['path' => $itemPath, 'message' => 'Invalid CASE WHEN clause.'];
                }
                continue;
            }
            $this->rejectUnknown($condition, ['left', 'operator', 'right'], $itemPath . '.condition.', $errors);
            if (!in_array(strtoupper((string)($condition['operator'] ?? '')), ['=', '!=', '<>', '>', '<', '>=', '<='], true)) {
                $errors[] = ['path' => $itemPath . '.condition.operator', 'message' => 'Invalid CASE comparison operator.'];
            }
            foreach (['left', 'right'] as $side) {
                $this->validateExpressionNode($condition[$side] ?? null, $itemPath . '.condition.' . $side, $errors, $depth + 1, $aggregateDepth, $context);
            }
            if (!array_key_exists('then', $when)) {
                $errors[] = ['path' => $itemPath . '.then', 'message' => 'CASE WHEN requires a THEN expression.'];
            } else {
                $this->validateExpressionNode($when['then'], $itemPath . '.then', $errors, $depth + 1, $aggregateDepth, $context);
            }
        }
        if (array_key_exists('else', $case) && !$this->isLiteral($case['else'])) {
            $this->validateExpressionNode($case['else'], $path . '.else', $errors, $depth + 1, $aggregateDepth, $context);
        }
    }

    private function validateExpressionList(
        $value,
        string $path,
        array &$errors,
        string $context,
        int $depth = 1
    ): void
    {
        if (!is_array($value)) {
            $errors[] = ['path' => $path, 'message' => 'Expression list must be an array.'];
            return;
        }
        foreach ($value as $index => $item) {
            if (is_string($item)) {
                if (!$this->isIdentifier($item)) {
                    $errors[] = ['path' => "{$path}.{$index}", 'message' => 'Field must be a valid identifier.'];
                }
                continue;
            }
            $this->validateExpressionNode($item, "{$path}.{$index}", $errors, $depth + 1, 0, $context);
        }
    }

    private function validateExpressionNode(
        $node,
        string $path,
        array &$errors,
        int $depth,
        int $aggregateDepth = 0,
        string $context = 'expression'
    ): void {
        if ($depth > self::MAX_EXPRESSION_DEPTH) {
            $errors[] = ['path' => $path, 'message' => 'Expression exceeds maximum depth of ' . self::MAX_EXPRESSION_DEPTH . '.'];
            return;
        }
        if (!is_array($node) || array_is_list($node)) {
            $errors[] = ['path' => $path, 'message' => 'Expression node must be an object.'];
            return;
        }
        $types = array_values(array_filter(
            ['field', 'literal', 'expression', 'unary', 'function', 'case'],
            fn (string $key): bool => array_key_exists($key, $node)
        ));
        if (isset($node['function']) && array_key_exists('field', $node)) {
            $types = array_values(array_diff($types, ['field']));
        }
        if (count($types) !== 1) {
            $errors[] = ['path' => $path, 'message' => 'Expression node must contain exactly one expression type.'];
            return;
        }
        $type = $types[0];
        if ($type === 'field') {
            $this->rejectUnknown($node, ['field'], $path . '.', $errors);
            if (!$this->isIdentifier($node['field'])) {
                $errors[] = ['path' => $path . '.field', 'message' => 'Field must be a valid identifier.'];
            }
            return;
        }
        if ($type === 'literal') {
            $this->rejectUnknown($node, ['literal'], $path . '.', $errors);
            if (!$this->isLiteral($node['literal'])) {
                $errors[] = ['path' => $path . '.literal', 'message' => 'Literal must be a JSON scalar or null.'];
            }
            return;
        }
        if ($type === 'expression') {
            $this->rejectUnknown($node, ['expression'], $path . '.', $errors);
            $binary = $node['expression'];
            if (!is_array($binary)) {
                $errors[] = ['path' => $path . '.expression', 'message' => 'Expression must be an object.'];
                return;
            }
            $this->rejectUnknown($binary, ['left', 'operator', 'right'], $path . '.expression.', $errors);
            if (!in_array($binary['operator'] ?? null, ['+', '-', '*', '/', '%'], true)) {
                $errors[] = ['path' => $path . '.expression.operator', 'message' => 'Unsupported expression operator.'];
            }
            foreach (['left', 'right'] as $side) {
                $this->validateExpressionNode($binary[$side] ?? null, $path . '.expression.' . $side, $errors, $depth + 1, $aggregateDepth, $context);
            }
            return;
        }
        if ($type === 'unary') {
            $this->rejectUnknown($node, ['unary'], $path . '.', $errors);
            $this->validateUnary($node['unary'], $path . '.unary', $errors, $depth + 1, $aggregateDepth, $context);
            return;
        }
        if ($type === 'case') {
            $this->rejectUnknown($node, ['case'], $path . '.', $errors);
            $this->validateCase($node['case'], $path . '.case', $errors, $depth + 1, $aggregateDepth, $context);
            return;
        }

        $name = strtoupper((string)$node['function']);
        if (!QueryFunctionRegistry::supports($name)) {
            $errors[] = ['path' => $path . '.function', 'message' => 'Unsupported function.'];
            return;
        }
        if (!QueryFunctionRegistry::isRecursivelyRenderable($name)) {
            $errors[] = ['path' => $path . '.function', 'message' => "{$name} is not supported in a recursive expression."];
        }
        if (QueryFunctionRegistry::isWindow($name)) {
            $errors[] = ['path' => $path . '.function', 'message' => 'Window functions cannot be nested inside expressions.'];
        }
        if (in_array($context, ['group', 'partition'], true)
            && (QueryFunctionRegistry::isAggregate($name) || QueryFunctionRegistry::isWindow($name))) {
            $errors[] = ['path' => $path . '.function', 'message' => 'Aggregate and window functions are not allowed in this expression context.'];
        }
        if (QueryFunctionRegistry::isAggregate($name) && $aggregateDepth > 0) {
            $errors[] = ['path' => $path . '.function', 'message' => 'Aggregate functions cannot be nested.'];
        }
        if (isset($node['alias'])) {
            $errors[] = ['path' => $path . '.alias', 'message' => 'Aliases are allowed only on selected fields.'];
        }
        $this->rejectUnknown($node, QueryFunctionRegistry::allowedProperties($name), $path . '.', $errors);
        if (isset($node['datatype'])
            && !$this->isDatatype($node['datatype'])) {
            $errors[] = ['path' => $path . '.datatype', 'message' => 'Datatype is invalid.'];
        }
        if (isset($node['fields'])
            && (!is_array($node['fields'])
                || array_filter($node['fields'], fn ($item) => !$this->isIdentifier($item)) !== [])) {
            $errors[] = ['path' => $path . '.fields', 'message' => 'Function fields must be valid identifiers.'];
        }
        if (isset($node['sort'])) {
            $this->validateSort($node['sort'], $path . '.sort', $errors, $depth + 1);
        }
        if (isset($node['partitionBy'])) {
            $this->validateExpressionList($node['partitionBy'], $path . '.partitionBy', $errors, 'partition', $depth + 1);
        }
        if (array_key_exists('field', $node)) {
            if (!QueryFunctionRegistry::acceptsExpressionInput($name)) {
                $errors[] = ['path' => $path . '.field', 'message' => "{$name} does not accept an expression input."];
            } elseif (is_array($node['field'])) {
                $nextAggregateDepth = $aggregateDepth + (QueryFunctionRegistry::isAggregate($name) ? 1 : 0);
                $this->validateExpressionNode($node['field'], $path . '.field', $errors, $depth + 1, $nextAggregateDepth, $context);
            } elseif ($node['field'] !== '*' && !$this->isIdentifier($node['field'])) {
                $errors[] = ['path' => $path . '.field', 'message' => 'Function field must be an identifier or expression node.'];
            }
        }
        $this->validateFunctionField($node, $path, $errors);
    }

    private function validateUnary(
        $unary,
        string $path,
        array &$errors,
        int $depth,
        int $aggregateDepth,
        string $context = 'expression'
    ): void {
        if (!is_array($unary)) {
            $errors[] = ['path' => $path, 'message' => 'Unary expression must be an object.'];
            return;
        }
        $this->rejectUnknown($unary, ['operator', 'operand'], $path . '.', $errors);
        if (!in_array($unary['operator'] ?? null, ['+', '-'], true)) {
            $errors[] = ['path' => $path . '.operator', 'message' => 'Unsupported unary operator.'];
        }
        $this->validateExpressionNode($unary['operand'] ?? null, $path . '.operand', $errors, $depth + 1, $aggregateDepth, $context);
    }

    private function containsAggregate($node): bool
    {
        if (!is_array($node)) {
            return false;
        }
        if (isset($node['function']) && is_string($node['function'])
            && QueryFunctionRegistry::isAggregate($node['function'])) {
            return true;
        }
        foreach ($node as $value) {
            if (is_array($value) && $this->containsAggregate($value)) {
                return true;
            }
        }
        return false;
    }

    private function isLiteral($value): bool
    {
        return is_int($value) || is_float($value) || is_string($value)
            || is_bool($value) || $value === null;
    }

    private function validateSourceName(array $request, string $key, array &$errors, string $prefix = ''): void
    {
        $source = $request['source'] ?? null;
        if (!is_array($source) || !$this->isIdentifier($source[$key] ?? null)) {
            $errors[] = ['path' => $prefix . "source.{$key}", 'message' => 'A valid source identifier is required.'];
            return;
        }
        $this->rejectUnknown($source, [$key, 'alias'], $prefix . 'source.', $errors);
        if (isset($source['alias']) && !$this->isIdentifier($source['alias'])) {
            $errors[] = ['path' => $prefix . 'source.alias', 'message' => 'Alias must be a valid identifier.'];
        }
    }

    private function isIdentifier($value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value) === 1;
    }

    private function isDatatype($value): bool
    {
        if (!is_string($value)
            || preg_match('/^([A-Za-z]+)(?:\([0-9]+(?:,[0-9]+)?\))?$/', $value, $matches) !== 1) {
            return false;
        }
        return in_array(strtoupper($matches[1]), self::DATATYPES, true);
    }

    private function rejectUnknown(
        array $value,
        array $allowed,
        string $prefix,
        array &$errors
    ): void {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = ['path' => $prefix . $key, 'message' => 'Unknown property.'];
            }
        }
    }
}
