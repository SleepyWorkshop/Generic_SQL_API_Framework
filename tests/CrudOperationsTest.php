<?php

require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/WriteRepository.php';
require_once __DIR__ . '/../app/Controllers/WriteController.php';
require_once __DIR__ . '/../core/Response.php';

class CrudTestEngine extends QueryEngine
{
    public array $executions = [];
    public string $upsertOperation = 'INSERT';
    public ?string $failure = null;

    public function __construct() {}

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->executions[] = ['sql' => $sql, 'params' => $params, 'context' => $context];
        if ($this->failure !== null) throw new RuntimeException($this->failure);
        if (str_contains($sql, 'INSERT INTO [dbo].[Customers]')) {
            return ['executionTime' => 0.1, 'data' => [['__affected' => 1, '__generatedId' => 42]]];
        }
        if (str_contains($sql, 'UPDATE [dbo].[Customers]')) {
            return ['executionTime' => 0.2, 'data' => [['__affected' => 1], ['__affected' => 1]]];
        }
        if (str_contains($sql, 'DELETE FROM [dbo].[Customers]')) {
            return ['executionTime' => 0.3, 'data' => [['__affected' => 1]]];
        }
        return ['executionTime' => 0.4, 'data' => [[
            '__affected' => 1,
            '__operation' => $this->upsertOperation,
            '__generatedId' => 43,
        ]]];
    }

    public function executePreparedQuery($sql, array $params = [], array $context = [])
    {
        return $this->executePrepared($sql, $params, $context);
    }
}

class CrudTestMetadata extends MetadataRepository
{
    public bool $uniqueKey = true;

    public function __construct() {}

    public function getWriteColumns(string $schema, string $table): array
    {
        if ($schema === 'inventory' && $table === 'Stock') {
            return ['data' => [
                $this->column('Sku', 'varchar', false, false, false, 40),
                $this->column('Quantity', 'int', false),
            ]];
        }
        if ($schema !== 'dbo' || $table !== 'Customers') {
            return ['data' => []];
        }
        return ['data' => [
            $this->column('Id', 'int', false, true),
            $this->column('CustomerCode', 'varchar', false, false, false, 20),
            $this->column('Name', 'nvarchar', false, false, false, 200),
            $this->column('Email', 'varchar', true, false, false, 255),
            $this->column('Status', 'varchar', false, false, true, 20),
            $this->column('CreatedAt', 'datetime2', false, false, true),
        ]];
    }

    public function hasUniqueKey(string $schema, string $table, array $columns): bool
    {
        return $this->uniqueKey && $columns === ['CustomerCode'];
    }

    private function column(
        string $name,
        string $type,
        bool $nullable,
        bool $identity = false,
        bool $default = false,
        int $maxLength = -1
    ): array {
        return [
            'ColumnName' => $name,
            'DataType' => $type,
            'MaxLength' => in_array($type, ['nvarchar', 'nchar'], true) && $maxLength > 0
                ? $maxLength * 2
                : $maxLength,
            'NumericPrecision' => 10,
            'NumericScale' => 0,
            'IsNullable' => (int)$nullable,
            'IsIdentity' => (int)$identity,
            'IsComputed' => 0,
            'GeneratedAlwaysType' => 0,
            'IsHidden' => 0,
            'HasDefault' => (int)$default,
        ];
    }
}

function crudAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function crudThrows(callable $operation, string $code): ApiRequestException
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        crudAssert($exception->getErrorCode() === $code, "Expected {$code}, got {$exception->getErrorCode()}.");
        return $exception;
    }
    throw new RuntimeException("Expected {$code} exception.");
}

class CrudTestService extends WriteService
{
    public function __construct() {}
    public function execute(array $request): array
    {
        return ['data' => [['operation' => $request['action'], 'affectedRows' => 1]], 'affectedRows' => 1];
    }
}

class CrudTestController extends WriteController
{
    public array $captured = [];
    protected function success($data = [], $message = 'Success', $code = 200)
    {
        $this->captured = ['data' => $data, 'message' => $message, 'code' => $code];
    }
}

$engine = new CrudTestEngine();
$metadata = new CrudTestMetadata();
$repository = new WriteRepository($engine, $metadata);
$validator = new QueryRequestValidator();
$normalizer = new QueryRequestNormalizer();
$publicInsert = $normalizer->normalize([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C', 'Name' => 'N'],
]);
crudAssert($publicInsert['controller'] === 'Write' && $publicInsert['action'] === 'insert', 'Public write routing failed.');
$controller = new CrudTestController(new CrudTestService());
$controller->insert($publicInsert);
crudAssert($controller->captured['message'] === 'Data Inserted Successfully', 'Write controller response failed.');

$execute = function (array $public) use ($validator, $normalizer, $repository): array {
    $validator->validate($public);
    return $repository->execute($normalizer->normalize($public));
};

// INSERT: required/default/nullable/identity/type validation and prepared values.
$injection = "Robert'); DROP TABLE Customers;--";
$insert = $execute([
    'action' => 'insert', 'table' => 'Customers',
    'data' => ['CustomerCode' => 'C001', 'Name' => $injection, 'Email' => null],
]);
$insertExecution = $engine->executions[array_key_last($engine->executions)];
crudAssert(str_contains($insertExecution['sql'], 'INSERT INTO [dbo].[Customers]'), 'INSERT target was not quoted.');
crudAssert(substr_count($insertExecution['sql'], '?') === 3, 'INSERT placeholders changed.');
crudAssert(!str_contains($insertExecution['sql'], $injection), 'INSERT value was interpolated.');
crudAssert($insertExecution['params'] === ['C001', $injection, null], 'INSERT parameters changed.');
crudAssert($insert['affectedRows'] === 1 && $insert['data'][0]['generatedId'] === 42, 'INSERT result is invalid.');

crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Missing', 'data' => ['CustomerCode' => 'C', 'Name' => 'N'],
]), 'INVALID_WRITE_TABLE');
crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C', 'Name' => 'N', 'Unknown' => 1],
]), 'INVALID_WRITE_COLUMN');
crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C'],
]), 'MISSING_REQUIRED_FIELD');
crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C', 'Name' => 10],
]), 'INVALID_WRITE_VALUE');
crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['Id' => 7, 'CustomerCode' => 'C', 'Name' => 'N'],
]), 'INVALID_WRITE_COLUMN');

// UPDATE: allowlisted filters, parameter order, affected rows, and full-table guard.
$update = $execute([
    'action' => 'update', 'table' => 'Customers', 'data' => ['Email' => 'new@example.com'],
    'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 10]],
]);
$updateExecution = $engine->executions[array_key_last($engine->executions)];
crudAssert(str_contains($updateExecution['sql'], 'UPDATE [dbo].[Customers] SET [Email] = ?'), 'UPDATE SQL is invalid.');
crudAssert(str_contains($updateExecution['sql'], 'WHERE ([Id] = ?)'), 'UPDATE targeting condition is missing.');
crudAssert($updateExecution['params'] === ['new@example.com', 10], 'UPDATE parameter order changed.');
crudAssert($update['affectedRows'] === 2, 'UPDATE affected-row count is invalid.');
crudThrows(fn () => $execute([
    'action' => 'update', 'table' => 'Customers', 'data' => ['Email' => 'x'],
]), 'UNSAFE_WRITE');
// Filters are generic: any existing column can target rows without a
// per-table filter registration.
$execute([
    'action' => 'update', 'table' => 'Customers', 'data' => ['Email' => 'x'],
    'filters' => [['field' => 'Status', 'operator' => '=', 'value' => 'Active']],
]);
crudAssert(str_contains($engine->executions[array_key_last($engine->executions)]['sql'], 'WHERE ([Status] = ?)'), 'Generic filter column was not applied.');
crudThrows(fn () => $execute([
    'action' => 'update', 'table' => 'Customers', 'data' => ['Email' => 'x'],
    'filters' => [['field' => 'Missing', 'operator' => '=', 'value' => 1]],
]), 'INVALID_WRITE_COLUMN');
crudThrows(fn () => $execute([
    'action' => 'update', 'table' => 'Customers', 'data' => ['Email' => 12],
    'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]],
]), 'INVALID_WRITE_VALUE');
crudThrows(fn () => $execute([
    'action' => 'update', 'table' => 'Missing', 'data' => ['Email' => 'x'],
    'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]],
]), 'INVALID_WRITE_TABLE');

// DELETE: prepared targeting and full-table guard.
$delete = $execute([
    'action' => 'delete', 'table' => 'Customers',
    'filters' => [['field' => 'Email', 'operator' => 'LIKE', 'value' => '%@old.example']],
]);
$deleteExecution = $engine->executions[array_key_last($engine->executions)];
crudAssert(str_contains($deleteExecution['sql'], 'DELETE FROM [dbo].[Customers]'), 'DELETE SQL is invalid.');
crudAssert($deleteExecution['params'] === ['%@old.example'], 'DELETE value was not parameterized.');
crudAssert($delete['affectedRows'] === 1, 'DELETE affected-row count is invalid.');
crudThrows(fn () => $execute(['action' => 'delete', 'table' => 'Customers']), 'UNSAFE_WRITE');
crudThrows(fn () => $execute([
    'action' => 'delete', 'table' => 'Missing',
    'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]],
]), 'INVALID_WRITE_TABLE');
crudThrows(fn () => $execute([
    'action' => 'delete', 'table' => 'Customers',
    'filters' => [['field' => 'Id', 'operator' => 'DROP', 'value' => 1]],
]), 'INVALID_REQUEST');

// UPSERT: request keys must match a unique index; single parameterized MERGE/HOLDLOCK statement.
$upsertRequest = [
    'action' => 'upsert', 'table' => 'Customers', 'keys' => ['CustomerCode'],
    'data' => ['CustomerCode' => 'C002', 'Name' => 'Sam'],
];
$upsertInsert = $execute($upsertRequest);
$upsertExecution = $engine->executions[array_key_last($engine->executions)];
crudAssert(str_contains($upsertExecution['sql'], 'MERGE INTO [dbo].[Customers] WITH (HOLDLOCK)'), 'UPSERT locking strategy is missing.');
crudAssert(!str_contains($upsertExecution['sql'], 'C002'), 'UPSERT value was interpolated.');
crudAssert($upsertExecution['params'] === ['C002', 'Sam'], 'UPSERT parameters changed.');
crudAssert(str_contains($upsertExecution['sql'], '[target].[CustomerCode] = [source].[CustomerCode]'), 'UPSERT key did not reach SQL generation.');
crudAssert($upsertInsert['data'][0]['operation'] === 'insert', 'UPSERT insert path was not reported.');
$engine->upsertOperation = 'UPDATE';
$upsertUpdate = $execute($upsertRequest);
crudAssert($upsertUpdate['data'][0]['operation'] === 'update', 'UPSERT update path was not reported.');
$metadata->uniqueKey = false;
crudThrows(fn () => $execute($upsertRequest), 'INVALID_UPSERT_KEY');
$metadata->uniqueKey = true;
crudThrows(fn () => $execute(array_merge($upsertRequest, ['keys' => ['Email']])), 'INVALID_UPSERT_KEY');
crudThrows(fn () => $execute(array_merge($upsertRequest, ['keys' => ['Name']])), 'INVALID_UPSERT_KEY');
crudThrows(fn () => $execute(array_merge($upsertRequest, ['keys' => []])), 'INVALID_REQUEST');
$keylessUpsert = $upsertRequest;
unset($keylessUpsert['keys']);
crudThrows(fn () => $execute($keylessUpsert), 'INVALID_REQUEST');
crudThrows(fn () => $execute([
    'action' => 'upsert', 'table' => 'Customers', 'keys' => ['CustomerCode'],
    'data' => ['CustomerCode' => null, 'Name' => 'Sam'],
]), 'INVALID_WRITE_VALUE');
crudThrows(fn () => $execute([
    'action' => 'upsert', 'table' => 'Customers', 'keys' => ['CustomerCode'],
    'data' => ['CustomerCode' => 'C003', 'Name' => 'Sam', 'Unknown' => true],
]), 'INVALID_WRITE_COLUMN');

// Generic CRUD needs no per-table registration: any user table the metadata
// confirms is writable, including schema-qualified names.
$execute([
    'action' => 'insert', 'table' => 'inventory.Stock', 'data' => ['Sku' => 'A-1', 'Quantity' => 5],
]);
$stockExecution = $engine->executions[array_key_last($engine->executions)];
crudAssert(str_contains($stockExecution['sql'], 'INSERT INTO [inventory].[Stock]') && $stockExecution['params'] === ['A-1', 5],
    'Unregistered table write was not generated generically.');
crudAssert(str_contains($stockExecution['sql'], 'CAST(NULL AS sql_variant)') && !str_contains($stockExecution['sql'], 'INSERTED.'),
    'A table without an identity column captured a generated identity.');
crudAssert(str_contains($insertExecution['sql'], 'INSERTED.[Id]'), 'The identity column was not detected from metadata.');
$execute([
    'action' => 'delete', 'table' => 'dbo.Customers', 'filters' => [['field' => 'Id', 'operator' => '=', 'value' => 1]],
]);
crudAssert(str_contains($engine->executions[array_key_last($engine->executions)]['sql'], 'DELETE FROM [dbo].[Customers]'), 'Schema-qualified table was not resolved.');

// System catalog objects stay out of reach of the data API.
foreach (['sys.objects', 'INFORMATION_SCHEMA.TABLES', 'Sys.Tables'] as $systemTable) {
    crudThrows(fn () => $execute([
        'action' => 'delete', 'table' => $systemTable, 'filters' => [['field' => 'name', 'operator' => '=', 'value' => 'x']],
    ]), 'INVALID_WRITE_TABLE');
}

// Identifier attacks and malformed filter objects are rejected before SQL construction.
foreach ([
    ['action' => 'insert', 'table' => 'master.dbo.Customers', 'data' => ['CustomerCode' => 'C', 'Name' => 'N']],
    ['action' => 'insert', 'table' => '[dbo].[Customers]', 'data' => ['CustomerCode' => 'C', 'Name' => 'N']],
    ['action' => 'insert', 'resource' => 'customers', 'data' => ['CustomerCode' => 'C', 'Name' => 'N']],
    ['action' => 'insert', 'table' => 'Customers;drop', 'data' => ['CustomerCode' => 'C', 'Name' => 'N']],
    ['action' => 'insert', 'table' => 'Customers', 'data' => ['Name]; DROP TABLE X;--' => 'x']],
    ['action' => 'delete', 'table' => 'Customers', 'filters' => [['field' => 'Id OR 1=1', 'operator' => '=', 'value' => 1]]],
    ['action' => 'delete', 'table' => 'Customers', 'filters' => [['field' => 'Id', 'operator' => '=']]],
] as $maliciousRequest) {
    crudThrows(fn () => $execute($maliciousRequest), 'INVALID_REQUEST');
}

// Safe public response and classified database conflicts.
Response::setRequestContext(['action' => 'insert', 'table' => 'Customers']);
$response = Response::successPayload($insert, 'Data Inserted Successfully');
crudAssert($response['success'] && $response['meta']['affectedRows'] === 1, 'Write response metadata is invalid.');
crudAssert($response['data'][0]['generatedId'] === 42, 'Generated identity is missing from response.');
$engine->failure = '[Microsoft][ODBC SQL Server Driver][SQL Server]Violation of UNIQUE KEY constraint. (2627)';
crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C', 'Name' => 'N'],
]), 'DUPLICATE_KEY');
$engine->failure = 'The INSERT statement conflicted with the FOREIGN KEY constraint. (547)';
crudThrows(fn () => $execute([
    'action' => 'insert', 'table' => 'Customers', 'data' => ['CustomerCode' => 'C', 'Name' => 'N'],
]), 'CONSTRAINT_VIOLATION');

echo "CRUD operation tests passed (database-independent; no live SQL Server execution).\n";
