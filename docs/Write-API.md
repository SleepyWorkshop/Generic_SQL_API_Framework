# Write API

The Write API performs single-object INSERT, UPDATE, DELETE, and UPSERT
operations on any user table of a registered database. There is no
per-table registration: a caller holding `data.write` (Data Operator, System
Administrator, or an `api-administrator` key) can write to any table the
database login can write to. Session callers must send `X-CSRF-Token`.

The request names the target table; the backend validates the name, loads the
table's live column metadata, and builds parameterized SQL. The database login's
own permissions remain the final boundary, so grant it only the rights the
deployment needs (see [Deployment checklist](#deployment-checklist)).

## Target database

A write has exactly one target database: the optional top-level `database`
(a registered database id, for example `"database": "inventory"`), else the
registry's default database. Requests without `database` behave as before.

```json
{"action":"insert","database":"inventory","table":"sales.Product","data":{"Sku":"K1","Region":"EU"}}
```

- The id is resolved through the registry before any connection opens; the
  request's connection opens to that database, and the table, its metadata,
  and the statement all belong to it. The client never supplies a physical
  database name, server, or credentials.
- An unknown, disabled, or disconnected database fails with
  `DATABASE_NOT_FOUND`, `DATABASE_DISABLED`, `SERVER_PROFILE_DISABLED`, or
  `DATABASE_UNAVAILABLE`; there is no fallback to the default database.
- There is no syntax for a second database: `database` is one id, the write
  grammar has no sources, joins, or subqueries, and lists or `targets` are
  rejected. Multi-target writes, cross-database and cross-server writes, and
  distributed transactions are not supported.

## Target table

`table` is `Name` (schema `dbo`) or `Schema.Name`, in the target database:

- each part is an unquoted identifier (`[A-Za-z_][A-Za-z0-9_]*`);
- three-part or cross-database names, brackets, and other punctuation are
  rejected (`400 INVALID_REQUEST`);
- the `sys` and `INFORMATION_SCHEMA` schemas are rejected
  (`400 INVALID_WRITE_TABLE`);
- the table must exist as a user table in the target database
  (`sys.tables`); otherwise `400 INVALID_WRITE_TABLE`. Views are not write
  targets.

Columns come from the table's live metadata:

- any existing column can be used in UPDATE/DELETE filters;
- any column that is not database-generated can be written. Identity, computed,
  generated-always, hidden, `timestamp`, and `rowversion` columns are rejected
  (`INVALID_WRITE_COLUMN`);
- a non-nullable column without a default must be supplied on INSERT and UPSERT
  (`MISSING_REQUIRED_FIELD`); omit a field to use its database default;
- values are checked for type, range, format, nullability, and length
  (`INVALID_WRITE_VALUE`). Unsupported SQL Server types are rejected.

If the table has an identity column, INSERT (and the insert branch of UPSERT)
returns its value as `generatedId`.

## INSERT

```json
{
  "action": "insert",
  "table": "dbo.Customers",
  "data": {
    "CustomerCode": "C001",
    "Name": "John",
    "Email": "john@example.com",
    "Status": "Active"
  }
}
```

`data` is a non-empty object of scalar or null values. INSERT accepts no
filters, keys, or return-field selection.

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

## UPDATE

```json
{
  "action": "update",
  "table": "Customers",
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
  "table": "Customers",
  "filters": [{ "field": "Id", "operator": "=", "value": 42 }]
}
```

DELETE has no `data`; a non-empty `filters` list is mandatory
(`UNSAFE_WRITE` otherwise). Success data is
`[{"operation":"delete","affectedRows":N}]`.

## UPSERT

```json
{
  "action": "upsert",
  "table": "Customers",
  "keys": ["CustomerCode"],
  "data": {
    "CustomerCode": "C001",
    "Name": "John",
    "Email": "john@example.com"
  }
}
```

- `keys` is required. Each key must be an existing column with a non-null value
  in `data`, and `data` must include at least one non-key value.
- The key set must exactly match the table's PRIMARY KEY or an unfiltered UNIQUE
  index (case- and order-insensitive); otherwise `400 INVALID_UPSERT_KEY`.

UPSERT is one `MERGE ... WITH (HOLDLOCK)` statement with prepared source values.
There is no surrounding API transaction. HOLDLOCK closes the gap between a
separate UPDATE and INSERT, but SQL Server MERGE caveats still apply; test
concurrency against each target, especially with triggers or replication.

## How writes execute

DML `OUTPUT` is captured into a table variable and selected afterwards. This
gives an affected-row count without relying on the driver, works when the target
has enabled DML triggers, and returns only the table's identity column.
Schema, table, and column names are bracket-quoted after validation against
metadata; all values are positional prepared parameters. Each request executes
one DML statement. The Write API never generates DDL or any statement other than
INSERT, UPDATE, DELETE, or MERGE.

## Write filters

UPDATE and DELETE accept `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=`, `LIKE`,
`NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `IS NULL`, and
`IS NOT NULL` on any column of the table. `IN` lists must be non-empty, `BETWEEN`
takes exactly two values, and the null operators omit `value`. Subqueries and
`EXISTS` are not supported. Filters combine with `AND` by default or with
top-level `filterLogic: "OR"`.

Write error codes (`INVALID_WRITE_TABLE`, `UNSAFE_WRITE`,
`INVALID_WRITE_COLUMN`, `INVALID_WRITE_VALUE`, `MISSING_REQUIRED_FIELD`,
`INVALID_UPSERT_KEY`, `DUPLICATE_KEY`, `CONSTRAINT_VIOLATION`) are listed in
[Errors and validation](Errors-and-Validation.md#data-action-codes). SQL Server
error text is never returned.

## Not supported

Bulk or array input, multi-action transactions, writes to views or system
objects, more than one target database, cross-database sources,
cross-server writes, distributed transactions, identity-insert overrides, SQL expression
values, explicit requests for database defaults, returned-column selection, soft
delete, and optimistic concurrency tokens.

## Deployment checklist

1. Grant the database login INSERT/UPDATE/DELETE only on the tables or schemas
   clients should be able to change. With `data.write`, the API can reach every
   table the login can write to.
2. Give `data.write` only to roles and API keys that need it.
3. For UPSERT, create the matching primary key or unique index.
4. Verify DML, constraints, triggers, affected-row output, and concurrent UPSERT
   against the deployment database.
