<?php

require_once __DIR__ . '/../../core/QueryEngine.php';
require_once __DIR__ . '/MetadataRepository.php';
require_once __DIR__ . '/../Resources/DatabaseObjectName.php';
require_once __DIR__ . '/../Requests/WritePayloadValidator.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/Write/InsertBuilder.php';
require_once __DIR__ . '/Write/UpdateBuilder.php';
require_once __DIR__ . '/Write/DeleteBuilder.php';
require_once __DIR__ . '/Write/UpsertBuilder.php';

class WriteRepository
{
    private QueryEngine $queryEngine;
    private MetadataRepository $metadataRepository;
    private WritePayloadValidator $payloadValidator;
    private InsertBuilder $insertBuilder;
    private UpdateBuilder $updateBuilder;
    private DeleteBuilder $deleteBuilder;
    private UpsertBuilder $upsertBuilder;

    public function __construct(
        ?QueryEngine $queryEngine = null,
        ?MetadataRepository $metadataRepository = null,
        ?WritePayloadValidator $payloadValidator = null
    ) {
        $this->queryEngine = $queryEngine ?? new QueryEngine();
        $this->metadataRepository = $metadataRepository ?? new MetadataRepository($this->queryEngine);
        $this->payloadValidator = $payloadValidator ?? new WritePayloadValidator();
        $this->insertBuilder = new InsertBuilder();
        $this->updateBuilder = new UpdateBuilder();
        $this->deleteBuilder = new DeleteBuilder();
        $this->upsertBuilder = new UpsertBuilder();
    }

    public function execute(array $request): array
    {
        // Any user table of the configured database can be written by a
        // principal holding data.write; the database login's own permissions
        // remain the final boundary.
        $name = DatabaseObjectName::parse($request['table'], 'table', 'INVALID_WRITE_TABLE', 'Invalid write table.');
        $metadataRows = $this->metadataRepository->getWriteColumns($name['schema'], $name['name'])['data'] ?? [];
        $target = [
            'name' => $name['schema'] . '.' . $name['name'],
            'schema' => $name['schema'],
            'table' => $name['name'],
            'identityColumn' => $this->identityColumn($metadataRows),
        ];
        $request = $this->payloadValidator->validate($request, $target, $metadataRows);
        if ($request['action'] === 'upsert'
            && !$this->metadataRepository->hasUniqueKey($target['schema'], $target['table'], $request['keys'])) {
            throw new ApiRequestException(
                'Invalid UPSERT key.',
                'INVALID_UPSERT_KEY',
                [['path' => 'keys', 'message' => 'Keys must exactly match a primary key or unfiltered unique index.']]
            );
        }
        $query = $this->build($request, $target);

        try {
            $result = $this->queryEngine->executePreparedQuery(
                $query['sql'],
                $query['params'],
                ['action' => $request['action'], 'queryPhase' => 'write', 'resource' => $target['name']]
            );
        } catch (Throwable $exception) {
            $this->throwClassifiedDatabaseError($exception);
        }

        return $this->formatResult($request['action'], $result);
    }

    public function build(array $request, array $target): array
    {
        return match ($request['action']) {
            'insert' => $this->insertBuilder->build($target, $request['data']),
            'update' => $this->updateBuilder->build(
                $target,
                $request['data'],
                $request['filters'],
                $request['filterLogic'] ?? 'AND'
            ),
            'delete' => $this->deleteBuilder->build(
                $target,
                $request['filters'],
                $request['filterLogic'] ?? 'AND'
            ),
            'upsert' => $this->upsertBuilder->build($target, $request['data'], $request['keys']),
            default => throw new LogicException('Unsupported write action.'),
        };
    }

    private function identityColumn(array $metadataRows): ?string
    {
        foreach ($metadataRows as $row) {
            if (is_array($row) && (bool)$this->rowValue($row, 'IsIdentity')) {
                $column = $this->rowValue($row, 'ColumnName');
                return is_string($column) ? $column : null;
            }
        }
        return null;
    }

    private function formatResult(string $requestedAction, array $result): array
    {
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $outputRows = array_values(array_filter(
            $rows,
            fn ($row) => is_array($row) && $this->rowValue($row, '__affected') !== null
        ));
        $affectedRows = count($outputRows);
        $operation = $requestedAction;
        if ($requestedAction === 'upsert' && isset($outputRows[0])) {
            $databaseOperation = strtolower((string)$this->rowValue($outputRows[0], '__operation'));
            if (in_array($databaseOperation, ['insert', 'update'], true)) {
                $operation = $databaseOperation;
            }
        }
        $payload = ['operation' => $operation, 'affectedRows' => $affectedRows];
        if (isset($outputRows[0]) && ($requestedAction === 'insert'
            || ($requestedAction === 'upsert' && $operation === 'insert'))) {
            $generatedId = $this->rowValue($outputRows[0], '__generatedId');
            if ($generatedId !== null) $payload['generatedId'] = $generatedId;
        }

        return [
            'executionTime' => $result['executionTime'] ?? null,
            'rowsReturned' => 0,
            'totalRows' => 0,
            'affectedRows' => $affectedRows,
            'data' => [$payload],
        ];
    }

    private function rowValue(array $row, string $key)
    {
        foreach ($row as $name => $value) {
            if (strcasecmp((string)$name, $key) === 0) return $value;
        }
        return null;
    }

    private function throwClassifiedDatabaseError(Throwable $exception): never
    {
        $message = $exception->getMessage();
        if (preg_match('/(?:\b2601\b|\b2627\b|duplicate key|unique (?:index|constraint))/i', $message) === 1) {
            throw new ApiRequestException(
                'Duplicate key conflict.',
                'DUPLICATE_KEY',
                [],
                409
            );
        }
        if (preg_match('/(?:\b23000\b|\b547\b|\b515\b|constraint|cannot insert the value null|truncated)/i', $message) === 1) {
            throw new ApiRequestException(
                'Database constraint violation.',
                'CONSTRAINT_VIOLATION',
                [],
                409
            );
        }
        throw $exception;
    }
}
