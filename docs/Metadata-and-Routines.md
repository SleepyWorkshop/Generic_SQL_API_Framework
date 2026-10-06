# Metadata and routines

## Metadata API

Metadata actions return ordinary result rows in `data` and the standard query
`meta`. Field names are the aliases emitted by repository SQL.

| Action | Exact request | Row fields | Message |
|---|---|---|---|
| `metadata.tables` | `{"action":"metadata.tables"}` | `TABLE_NAME` | `Tables Loaded Successfully` |
| `metadata.columns` | `{"action":"metadata.columns","source":{"table":"Items"}}` | `COLUMN_NAME`, `DATA_TYPE`, `IS_NULLABLE` | `Columns Loaded Successfully` |
| `metadata.views` | `{"action":"metadata.views"}` | `TABLE_NAME` | `Views Loaded Successfully` |
| `metadata.procedures` | `{"action":"metadata.procedures"}` | `ROUTINE_NAME` | `Stored Procedures Loaded Successfully` |
| `metadata.schema` | `{"action":"metadata.schema"}` | `TABLE_NAME`, `COLUMN_NAME`, `DATA_TYPE`, `ORDINAL_POSITION` | `Schema Loaded Successfully` |

Tables includes only `INFORMATION_SCHEMA.TABLES` base tables. Columns is ordered
by ordinal position and binds the table name as a prepared parameter. Views and
procedures list names. Schema returns one row per database column ordered by table
and ordinal position; it is not a nested schema document.

Example response:

```json
{
  "success": true,
  "message": "Columns Loaded Successfully",
  "data": [
    { "COLUMN_NAME": "ItemCode", "DATA_TYPE": "varchar", "IS_NULLABLE": "NO" }
  ],
  "meta": {
    "page": null, "pageSize": null, "totalRows": 1,
    "rowsReturned": 1, "executionTime": 1.1
  }
}
```

Only `metadata.columns` accepts `source`, requiring `source.table`. The shared
validator technically permits `source.alias`, but normalization discards it; omit
it. Other metadata actions accept no fields beyond `action`.

Frontends can use these endpoints to populate table/column pickers, but should not
assume they authorize subsequent access. Metadata requires the server-owned
`metadata.read` permission, but catalog objects are not individually scoped;
subsequent query/write/routine authorization is evaluated independently.

## Routine API

Routine names are identifier-validated and can be schema-qualified. Parameters
are positional prepared values. Send a JSON list; omission defaults to `[]`.

Stored procedure:

```json
{
  "action": "procedure",
  "source": { "procedure": "dbo.RunReport" },
  "parameters": [2026, true]
}
```

The generated concept is `EXEC dbo.RunReport ?, ?`. Result rows use
`Procedure Executed Successfully`.

Scalar function:

```json
{
  "action": "function",
  "source": { "function": "dbo.Score" },
  "parameters": [42]
}
```

The generated concept is `SELECT dbo.Score(?) AS Result`; message:
`Function Executed Successfully`.

Table-valued function:

```json
{
  "action": "tableFunction",
  "source": { "function": "dbo.RowsForYear" },
  "parameters": [2026]
}
```

The generated concept is `SELECT * FROM dbo.RowsForYear(?)`; message:
`Table Function Executed Successfully`.

The shared source shape permits an optional alias, but it is ignored. Routine
actions do not accept filters, sorting, pagination, named parameters, output
parameter declarations, result-set selection, or transaction controls. The API
does not inspect routine signatures. Unknown or mismatched signatures of a
registered routine surface as generic `QUERY_ERROR`.

## Routine registry

Routines are deny-by-default. Only entries in `config/routine-resources.php`
can be called, and only through the action matching their `type`:

```php
'dbo.RunReport' => [
    'type' => 'procedure',          // procedure | function | tableFunction
    'schema' => 'dbo',
    'name' => 'RunReport',
    'access' => 'read',             // write additionally requires data.write
    'parameters' => 2,              // exact positional argument count
    'roles' => ['read-only', 'data-operator'],
],
```

Clients send the registry key (`source.procedure` / `source.function`). The SQL
identifier is always built from the registry's `schema` and `name`
(`EXEC [dbo].[RunReport] ?, ?`) in the configured database; client text never
becomes a SQL identifier, and system or cross-database routines are unreachable
unless an operator registers them. A caller needs `routine.execute`, one of the
entry's `roles`, and `data.write` for `access: write`. `frontend.read` does not
authorize routines. Unregistered IDs return `INVALID_ROUTINE`; a wrong argument
count returns `INVALID_ROUTINE_PARAMETERS`; roles outside the entry return
`RESOURCE_ACCESS_DENIED`. The shipped registry is empty.

`metadata.procedures` lists only registered procedures. `metadata.tables`,
`metadata.views`, `metadata.schema`, and `metadata.columns` list only query
sources registered in `config/query-sources.php` (see
[JSON Query Mode](Query-Mode.md#query-source-registry)).
