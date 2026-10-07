<?php

require_once __DIR__ . '/ApiRequestException.php';

/**
 * Validates a write request against the live column metadata of its target
 * table. Every existing column may be filtered on; every column that is not
 * database-generated may be written. Values are type-checked and stay bound
 * parameters.
 */
class WritePayloadValidator
{
    public function validate(array $request, array $target, array $metadataRows): array
    {
        $metadata = $this->indexMetadata($metadataRows);

        $validated = $request;
        if (isset($request['data'])) {
            $validated['data'] = $this->validateData($request['data'], $metadata, $request['action']);
        }
        if (isset($request['filters'])) {
            $validated['filters'] = $this->validateFilters($request['filters'], $metadata);
        }
        if ($request['action'] === 'upsert') {
            $validated['keys'] = $this->validateKeys($request['keys'], $validated['data'], $metadata);
        }
        return $validated;
    }

    private function indexMetadata(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $column = $this->rowValue($row, 'ColumnName');
            if (!is_string($column) || $column === '') continue;
            $indexed[strtolower($column)] = [
                'name' => $column,
                'type' => strtolower((string)$this->rowValue($row, 'DataType')),
                'maxLength' => (int)$this->rowValue($row, 'MaxLength'),
                'precision' => (int)$this->rowValue($row, 'NumericPrecision'),
                'scale' => (int)$this->rowValue($row, 'NumericScale'),
                'nullable' => (bool)$this->rowValue($row, 'IsNullable'),
                'identity' => (bool)$this->rowValue($row, 'IsIdentity'),
                'computed' => (bool)$this->rowValue($row, 'IsComputed'),
                'generated' => (int)$this->rowValue($row, 'GeneratedAlwaysType') !== 0,
                'hidden' => (bool)$this->rowValue($row, 'IsHidden'),
                'hasDefault' => (bool)$this->rowValue($row, 'HasDefault'),
            ];
        }
        if ($indexed === []) {
            throw new ApiRequestException(
                'Invalid write table.',
                'INVALID_WRITE_TABLE',
                [['path' => 'table', 'message' => 'Table does not exist in the configured database.']]
            );
        }
        return $indexed;
    }

    private function validateData(array $data, array $metadata, string $action): array
    {
        $canonical = [];
        foreach ($data as $requestedColumn => $value) {
            $properties = $this->column($metadata, (string)$requestedColumn, 'data.' . $requestedColumn);
            if ($this->isGenerated($properties)) {
                $this->invalidColumn('data.' . $requestedColumn, 'Database-generated columns cannot be written.');
            }
            if (array_key_exists($properties['name'], $canonical)) {
                $this->invalidColumn('data.' . $requestedColumn, 'Column was supplied more than once.');
            }
            $canonical[$properties['name']] = $this->validateValue(
                $value,
                $properties,
                'data.' . $requestedColumn
            );
        }

        if (in_array($action, ['insert', 'upsert'], true)) {
            foreach ($metadata as $properties) {
                $required = !$properties['nullable'] && !$properties['hasDefault'] && !$this->isGenerated($properties);
                if ($required && !array_key_exists($properties['name'], $canonical)) {
                    throw new ApiRequestException(
                        'Missing required field.',
                        'MISSING_REQUIRED_FIELD',
                        [['path' => 'data.' . $properties['name'], 'message' => 'This database field is required.']]
                    );
                }
            }
        }
        return $canonical;
    }

    private function validateFilters(array $filters, array $metadata): array
    {
        $validated = [];
        foreach ($filters as $index => $filter) {
            $properties = $this->column($metadata, (string)$filter['field'], "filters.{$index}.field");
            $operator = strtoupper($filter['operator']);
            $item = ['field' => $properties['name'], 'operator' => $operator];
            if (array_key_exists('value', $filter)) {
                $values = in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)
                    ? $filter['value']
                    : [$filter['value']];
                $normalized = [];
                foreach ($values as $valueIndex => $value) {
                    if ($value === null) {
                        throw new ApiRequestException(
                            'Invalid write value.',
                            'INVALID_WRITE_VALUE',
                            [[
                                'path' => "filters.{$index}.value" . (count($values) > 1 ? ".{$valueIndex}" : ''),
                                'message' => 'Use IS NULL or IS NOT NULL for null filters.',
                            ]]
                        );
                    }
                    $normalized[] = $this->validateValue(
                        $value,
                        $properties,
                        "filters.{$index}.value" . (count($values) > 1 ? ".{$valueIndex}" : '')
                    );
                }
                $item['value'] = in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)
                    ? $normalized
                    : $normalized[0];
            }
            $validated[] = $item;
        }
        return $validated;
    }

    /**
     * UPSERT keys come from the request. They must name existing columns, each
     * needs a non-null value, and the repository then requires a matching
     * primary key or unfiltered unique index.
     */
    private function validateKeys(array $requestedKeys, array $data, array $metadata): array
    {
        $canonical = [];
        foreach ($requestedKeys as $index => $key) {
            $name = $this->column($metadata, (string)$key, "keys.{$index}")['name'];
            if (!array_key_exists($name, $data) || $data[$name] === null) {
                throw new ApiRequestException(
                    'Invalid UPSERT key.',
                    'INVALID_UPSERT_KEY',
                    [['path' => 'data.' . $key, 'message' => 'A non-null value is required for each UPSERT key.']]
                );
            }
            $canonical[] = $name;
        }
        if (count($data) === count($canonical)) {
            throw new ApiRequestException(
                'Invalid write value.',
                'INVALID_WRITE_VALUE',
                [['path' => 'data', 'message' => 'UPSERT requires at least one non-key value for the update path.']]
            );
        }
        return $canonical;
    }

    private function column(array $metadata, string $requested, string $path): array
    {
        $properties = $metadata[strtolower($requested)] ?? null;
        if ($properties === null) {
            $this->invalidColumn($path, 'Column does not exist in the target table.');
        }
        return $properties;
    }

    private function isGenerated(array $properties): bool
    {
        return $properties['identity'] || $properties['computed']
            || $properties['generated'] || $properties['hidden']
            || in_array($properties['type'], ['timestamp', 'rowversion'], true);
    }

    private function validateValue($value, array $column, string $path)
    {
        if ($value === null) {
            if (!$column['nullable']) {
                $this->invalidValue($path, 'This field does not accept null.');
            }
            return null;
        }

        $type = $column['type'];
        if (in_array($type, ['tinyint', 'smallint', 'int', 'bigint'], true)) {
            if (!is_int($value)) $this->invalidValue($path, 'Expected an integer.');
            $ranges = [
                'tinyint' => [0, 255], 'smallint' => [-32768, 32767],
                'int' => [-2147483648, 2147483647],
            ];
            if (isset($ranges[$type]) && ($value < $ranges[$type][0] || $value > $ranges[$type][1])) {
                $this->invalidValue($path, 'Integer is outside the database type range.');
            }
            return $value;
        }
        if (in_array($type, ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'], true)) {
            if (!is_int($value) && !is_float($value)
                && !(is_string($value) && preg_match('/^-?(?:\d+)(?:\.\d+)?$/', $value) === 1)) {
                $this->invalidValue($path, 'Expected a numeric value.');
            }
            return $value;
        }
        if ($type === 'bit') {
            if (!is_bool($value) && $value !== 0 && $value !== 1) {
                $this->invalidValue($path, 'Expected a boolean or 0/1.');
            }
            return is_bool($value) ? (int)$value : $value;
        }
        if (in_array($type, ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext', 'xml'], true)) {
            if (!is_string($value)) $this->invalidValue($path, 'Expected a string.');
            $maxLength = $column['maxLength'];
            if (in_array($type, ['nchar', 'nvarchar'], true) && $maxLength > 0) $maxLength = intdiv($maxLength, 2);
            if (in_array($type, ['char', 'varchar', 'nchar', 'nvarchar'], true)
                && $maxLength > 0 && $this->stringLength($value) > $maxLength) {
                $this->invalidValue($path, "String exceeds the {$maxLength}-character limit.");
            }
            return $value;
        }
        if (in_array($type, ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset', 'time'], true)) {
            if (!is_string($value) || !$this->isTemporalValue($value, $type)) {
                $this->invalidValue($path, 'Expected an ISO date/time string.');
            }
            return $value;
        }
        if ($type === 'uniqueidentifier') {
            if (!is_string($value)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
                $this->invalidValue($path, 'Expected a UUID value.');
            }
            return $value;
        }
        if (in_array($type, ['binary', 'varbinary', 'image'], true)) {
            if (!is_string($value)) $this->invalidValue($path, 'Expected a binary string value.');
            return $value;
        }

        $this->invalidValue($path, 'This database type is not supported for writes.');
    }

    private function isTemporalValue(string $value, string $type): bool
    {
        if ($type === 'date') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        }
        if ($type === 'time') {
            return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,7})?)?$/', $value) === 1;
        }
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/',
            $value
        ) !== 1) return false;
        [$year, $month, $day] = array_map('intval', explode('-', substr($value, 0, 10)));
        return checkdate($month, $day, $year);
    }

    private function rowValue(array $row, string $key)
    {
        foreach ($row as $name => $value) {
            if (strcasecmp((string)$name, $key) === 0) return $value;
        }
        return null;
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function invalidColumn(string $path, string $message): never
    {
        throw new ApiRequestException(
            'Invalid write column.',
            'INVALID_WRITE_COLUMN',
            [['path' => $path, 'message' => $message]]
        );
    }

    private function invalidValue(string $path, string $message): never
    {
        throw new ApiRequestException(
            'Invalid write value.',
            'INVALID_WRITE_VALUE',
            [['path' => $path, 'message' => $message]]
        );
    }
}
