# Write API

The Write API performs single-object INSERT, UPDATE, DELETE, and UPSERT
operations on registered resources. It is deny-by-default: only resources
declared in `config/write-resources.php` are writable. Callers need `data.write`
and a matching `writeResources` scope (Data Operator, System Administrator, or
an `api-administrator` key), and session callers must send `X-CSRF-Token`.

## Write resource registry

`config/write-resources.php` maps public resource IDs to fixed SQL Server
targets. It is separate from SQL Resources: report queries are never implied to
be safe write targets.

The repository ships one entry, `crud-test`, for the test table
`dbo.ApiCrudTest`. Replace or remove it in deployments that do not have that
table.

```php
return [
    'crud-test' => [
        'schema' => 'dbo',
        'table' => 'ApiCrudTest',
        'actions' => ['insert', 'update', 'delete', 'upsert'],
        'columns' => ['CustomerCode', 'Name', 'Email', 'Age', 'Status'],
        'filterColumns' => ['Id', 'CustomerCode', 'Name', 'Email', 'Age', 'Status'],
        'keys' => ['CustomerCode'],
        'identityColumn' => 'Id',
    ],
];
```

| Setting | Required | Meaning |
|---|:-:|---|
| `table` | yes | Fixed physical base table |
| `schema` | no | Fixed schema; default `dbo` |
| `actions` | yes | Non-empty subset of `insert`, `update`, `delete`, `upsert` |
| `columns` | yes | Columns clients may assign |
| `filterColumns` | no | Columns usable in UPDATE/DELETE filters; defaults to `columns` |
| `keys` | no | Exact UPSERT key set (a subset of `columns`); required for UPSERT, empty disables it |
| `identityColumn` | no | Identity returned by INSERT or an inserting UPSERT; must appear in `columns` or `filterColumns` |

All names are unqualified SQL identifiers; lists are checked for
case-insensitive duplicates. On every request the resource is checked against
live SQL Server metadata: configured columns must exist, a declared identity
must really be an identity, and identity, computed, generated-always, hidden,
timestamp, and rowversion columns can never be written even if listed. A
mismatch is treated as server misconfiguration and returned as a generic
`QUERY_ERROR`. Values are checked for required columns, nullability, type,
range, format, and length; omit a field to use its database default.

## INSERT

```json
{
  "action": "insert",
  "resource": "crud-test",
  "data": {
    "CustomerCode": "C001",
    "Name": "John",
    "Email": "john@example.com",
    "Age": 30,
    "Status": "Active"
  }
}
```

`data` is a non-empty object of scalar or null values. INSERT accepts no
filters, keys, source, or return-field selection.

```json
{
  "success": true,
  "message": "Data Inserted Successfully",
  "data": [{ "operation": "insert", "affectedRows": 1, "generatedId": 42 }],
  "meta": {
    "requestId": "7f4dd403d84c99e1",
    "page": null, "pageSize": null, "totalRows": 0,
    "rowsReturned": 0, "executionTime": 1.27, "affectedRows": 1
  }
}
```

`generatedId` appears only when a verified, configured identity is returned.

## UPDATE

```json
{
  "action": "update",
  "resource": "crud-test",
  "data": { "Email": "new@example.com", "Status": "Active" },
  "filters": [{ "field": "CustomerCode", "operator": "=", "value": "C001" }],
  "filterLogic": "AND"
}
```

Both `data` and `filters` must be non-empty. Success data is
`[{"operation":"update","affectedRows":N}]`. Filters may match zero or more
rows; there is no single-row guarantee.

## DELETE

```json
{
  "action": "delete",
  "resource": "crud-test",
  "filters": [{ "field": "Id", "operator": "=", "value": 42 }]
}
```

DELETE has no `data`; a non-empty `filters` list is mandatory. Success data is
`[{"operation":"delete","affectedRows":N}]`.

## UPSERT

```json
{
  "action": "upsert",
  "resource": "crud-test",
  "data": {
    "CustomerCode": "C001",
    "Name": "John",
    "Email": "john@example.com",
    "Age": 30,
    "Status": "Active"
  },
  "keys": ["CustomerCode"]
}
```

- The request `keys` must equal the configured key set (case- and
  order-insensitive), and every key needs a non-null value in `data`.
- `data` must include at least one non-key value.
- Live metadata must show a PRIMARY KEY or unfiltered UNIQUE index exactly
  matching the configured keys. The API cannot tell whether those columns are
  the intended business key.

UPSERT is one `MERGE ... WITH (HOLDLOCK)` statement with prepared source values.
There is no surrounding API transaction. HOLDLOCK closes the gap between a
separate UPDATE and INSERT, but SQL Server MERGE caveats still apply; test
concurrency against each target, especially with triggers or replication.

## How writes execute

DML `OUTPUT` is captured into a table variable and selected afterwards. This
gives an affected-row count without relying on the driver, works when the target
has enabled DML triggers, and returns only a configured, verified identity.
Identifiers are bracket-quoted from the registry; all values are positional
prepared parameters. Each request executes one operation as an independent
statement.

## Write filters

UPDATE and DELETE accept `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=`, `LIKE`,
`NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `IS NULL`, and
`IS NOT NULL` on `filterColumns`. `IN` lists must be non-empty, `BETWEEN` takes
exactly two values, and the null operators omit `value`. Subqueries and `EXISTS`
are not supported. Filters combine with `AND` by default or with top-level
`filterLogic: "OR"`.

Write error codes (`INVALID_WRITE_RESOURCE`, `UNSAFE_WRITE`,
`INVALID_WRITE_COLUMN`, `INVALID_WRITE_VALUE`, `MISSING_REQUIRED_FIELD`,
`INVALID_UPSERT_KEY`, `DUPLICATE_KEY`, `CONSTRAINT_VIOLATION`) are listed in
[Errors and validation](Errors-and-Validation.md#data-action-codes). SQL Server
error text is never returned.

## Not supported

Bulk or array input, multi-action transactions, client-selected tables or
schemas, identity-insert overrides, SQL expression values, explicit requests for
database defaults, returned-column selection, soft delete, and optimistic
concurrency tokens.

## Deployment checklist

1. Grant the database login only the INSERT/UPDATE/DELETE rights the registered
   resources need.
2. Keep `columns` and `filterColumns` as small as possible.
3. Make sure every required, non-generated column is assignable or has a
   database default.
4. Configure `identityColumn` only when returning it is safe.
5. For UPSERT, create and verify the matching unique index.
6. Remove `crud-test` unless the deployment has `dbo.ApiCrudTest`.
7. Verify DML, constraints, triggers, affected-row output, and concurrent UPSERT
   against the deployment database.
