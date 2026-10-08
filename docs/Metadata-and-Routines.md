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
| `metadata.databases` | `{"action":"metadata.databases"}` | `id`, `name`, `default`, `enabled`, `available`, `crossDatabaseGroup` | `Databases Loaded Successfully` |

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
    "requestId": "7f4dd403d84c99e1",
    "page": null, "pageSize": null, "totalRows": 1,
    "rowsReturned": 1, "executionTime": 1.1
  }
}
```

Only `metadata.columns` accepts `source`, requiring `source.table` and
optionally `source.schema`. With a schema, only that schema's table is
described; without one, columns of every table with that name are listed, as
before. The shared validator technically permits `source.alias`, but
normalization discards it; omit it. Every catalog action also accepts a
top-level `database`; `metadata.databases` accepts nothing beyond `action`.

Frontends can use these endpoints to populate table/column pickers. Metadata
requires `metadata.read` (or frontend access) and returns the user tables,
views, columns, and procedures of the selected database that its login can
see. Listing an object does not authorize other actions on it; query, write, and
routine authorization are evaluated independently.

### Database selection

A catalog action reads the database named by its top-level `database`, a
registered database id, else the registry's default database, so requests
without `database` behave as before. The id is resolved through the registry
and the request's connection opens to that database; the client never supplies
a physical name. An unknown, disabled, or disconnected database fails with
`DATABASE_NOT_FOUND`, `DATABASE_DISABLED`, `SERVER_PROFILE_DISABLED`, or
`DATABASE_UNAVAILABLE` before any connection; there is no fallback to the
default database.

```json
{"action":"metadata.columns","database":"inventory","source":{"schema":"sales","table":"Product"}}
```

The same rules validate SELECT sources: in a cross-database query each source's
tables and columns are checked against its own database's catalog, so a table
name present in several databases is described independently.

### Databases

`metadata.databases` lists the logical databases a client can name:

```json
{
  "success": true,
  "message": "Databases Loaded Successfully",
  "data": [
    { "id": "company", "name": "Company", "default": true, "enabled": true, "available": true, "crossDatabaseGroup": "sql01" },
    { "id": "inventory", "name": "Inventory", "default": false, "enabled": true, "available": false, "crossDatabaseGroup": "sql01" },
    { "id": "legacy", "name": "Legacy", "default": false, "enabled": true, "available": true, "crossDatabaseGroup": "sql02" }
  ],
  "meta": { "requestId": "7f4dd403d84c99e1", "rowsReturned": 3, "...": "..." }
}
```

| Field | Meaning |
|---|---|
| `id` | Registry id to send as `database` |
| `name` | Display name |
| `default` | Used when a request names no database |
| `enabled` | The database and its server profile are enabled in the registry |
| `available` | Its availability gate is open (Admin Console Connect/Disconnect); not a live connectivity check |
| `crossDatabaseGroup` | Its server profile id: databases of one group can be combined in one query |

The listing is read from the registry and the availability state only; it
does not connect to SQL Server, so disabled and disconnected databases stay
listed. Physical database names, servers, ports, logins, passwords, TLS
options, timeouts, and encrypted configuration are never returned; the Admin
Console remains the place to manage them. Reachability is reported by the
health endpoints, not here.

## Routines

Routines are called by name; there is no routine registration. Parameters are
positional prepared values. Send a JSON list; omission defaults to `[]`.

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
parameter declarations, result-set selection, or transaction controls.

### Name and signature validation

- The name is `Name` (schema `dbo`) or `Schema.Name`. Three-part or
  cross-database names, brackets, the `sys` and `INFORMATION_SCHEMA` schemas,
  and names starting with `sp_` or `xp_` are rejected before any database
  lookup (`INVALID_ROUTINE`).
- A routine request may name a registered database with a top-level
  `database` (default: the default database). The request's connection opens
  to that database, and the routine runs there only; unknown, disabled, or
  disconnected databases fail before connecting, without falling back. A
  routine never targets several databases (what its own body does is SQL
  Server's business).
- The name must match a user-defined routine of the requested kind in the
  selected database's `INFORMATION_SCHEMA.ROUTINES` (procedure, scalar
  function, or table-valued function); otherwise `INVALID_ROUTINE`. System
  procedures are not listed there and cannot be called.
- Functions require exactly their declared number of parameters. Procedures
  accept at most that number, so trailing parameters can use their defaults.
  Otherwise `INVALID_ROUTINE_PARAMETERS`.
- The SQL identifier is built from the validated, bracket-quoted schema and
  name (`EXEC [dbo].[RunReport] ?, ?`); values are always bound.

### Authorization

| Action | Required permissions | CSRF (session callers) |
|---|---|:-:|
| `function`, `tableFunction` | `routine.execute` | no |
| `procedure` | `routine.execute` and `data.write` | yes |

SQL Server functions cannot modify data; stored procedures can, so they require
the write permission and CSRF like other writes. `frontend.read` never
authorizes routines. Session and API-key callers follow the same rules. The
database login's `EXECUTE` permissions remain the final boundary: grant it only
on the routines clients should call.
