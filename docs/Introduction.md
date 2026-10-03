# Introduction

Generic SQL API Framework is a backend-only PHP service for describing read queries as structured JSON. It validates a request against a fixed public contract, normalizes public names to an internal query model, validates database objects through metadata, builds SQL Server SQL, executes prepared values through ODBC, and returns JSON.

It solves a narrow problem: clients can request common query shapes without a dedicated endpoint and hand-written query for each one. It is not a raw-SQL endpoint, ORM, frontend, dashboard, or reporting UI.

## Current scope

The implemented backend provides:

- SELECT queries with aliases, expressions, supported functions, filtering, joins, grouping, sorting, and pagination
- window functions, filter subqueries, CTEs, and UNION/UNION ALL within documented limits
- auto-discovered, server-owned read-only SQL Resources with approved runtime filtering, sorting, and pagination
- deny-by-default single-object INSERT, UPDATE, DELETE, and UPSERT resources
- stored procedure, scalar function, and table-valued function execution with positional parameters
- SQL Server metadata actions
- standard success/error response envelopes, execution timing, row counts, and logs
- SQL Server connectivity through PHP ODBC
- optional AES-256-GCM protection for the complete `database.json` configuration
- a bundled Windows PHP runtime and validated startup script
- database-independent PHP tests and GitHub Actions CI

Microsoft SQL Server is the only selectable provider. Other driver files are incomplete placeholders and do not constitute provider support.

## Request in brief

```json
{
  "action": "select",
  "source": { "table": "Customers" },
  "fields": ["CustomerId", "Name"],
  "filters": [{ "field": "Active", "operator": "=", "value": 1 }]
}
```

The request crosses a strict boundary: public JSON uses `source`, `fields`, and `field`; internal builders use normalized keys. Applications should depend only on the [public JSON reference](JSON-Request-Reference.md).

## Runtime and testing

`start-windows.bat` uses the bundled `runtime/windows/php/`, verifies required
extensions with `extension_loaded()`, initializes configuration, prepares the
local encryption key, starts API and SQL Parser, enables application database
availability, verifies the development runtime, and starts Admin Console. The
same controls remain available in System Health after startup. `start-linux.sh`
provides the same lifecycle with the documented system-PHP fallback. Database
configuration and encrypted saving are performed through Admin Console. Normal
tests use fakes and need neither ODBC nor a database configuration file.

Continue with the [documentation map](README.md), [HTTP API](API.md),
[frontend integration](Frontend-Integration.md), or [capability matrix](Capability-Matrix.md).
