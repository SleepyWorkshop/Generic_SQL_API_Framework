# SQL Resource authoring

This guide is for backend maintainers who add or review SQL Resources: reviewed,
server-owned, read-only `.sql` files that clients execute by ID. The client
request contract (`execution` metadata, runtime filters, sorting, pagination) is
in [SQL Resource Mode](SQL-Resource-Mode.md).

## Discovery settings

`config/sql-resources.php` contains global discovery settings only. Individual
resources are never registered there.

```php
return [
    'root' => QUERY_PATH,
    'exclude' => ['system'],
];
```

| Setting | Default | Meaning |
|---|---|---|
| `root` | `QUERY_PATH` (`Backend/queries`) | Server-owned directory scanned recursively |
| `exclude` | `['system']` | Directory segment names omitted from discovery |

If the file is absent, the same defaults apply. Exclusions are simple path
segments, not globs. `queries/system/` holds internal metadata SQL used directly
by the metadata repositories; it is never public and must not be deleted.
Hidden directories are never discoverable.

## Adding a resource

Create a file under the root:

```sql
-- queries/reports/item-summary.sql
SELECT
    Item_Code,
    Description,
    SUM(Amount) AS TotalAmount
FROM dbo.ItemLedger
GROUP BY Item_Code, Description
```

It is immediately executable, with no registry edit:

```json
{ "action": "sql", "resource": "reports/item-summary" }
```

Use explicit, stable output aliases for any column a client will filter or sort
on. Discovery does not parse the projection or infer an output schema.

## Resource IDs

- The ID is the path relative to the root without `.sql`:
  `queries/reports/customer.sql` → `reports/customer`.
- Segments match `[A-Za-z0-9][A-Za-z0-9_-]*`, separated by `/`.
- Absolute paths, drive prefixes, dot segments, empty segments, backslashes,
  null bytes, URLs, and extensions are rejected.
- The resolver uses `realpath`, requires a regular `.sql` file inside the root,
  and never returns an absolute path in an error.
- Exact lookup is case-sensitive; IDs that differ only by case fail discovery
  so behavior is the same on every operating system.
- A unique basename (for example `item-summary`) also resolves. Ambiguous
  basenames fail; prefer full IDs in new integrations.

## Authored SQL rules

- One read-only `SELECT` or CTE (`WITH ... SELECT`) statement. Multiple
  statements, `SELECT INTO`, and DML are rejected. A trailing semicolon is
  removed.
- Because the file is trusted code, it may use any SQL Server read syntax:
  joins of every kind, `APPLY`, subqueries, derived tables, CTEs, aggregates,
  windows, set operations, `PIVOT`/`UNPIVOT`, JSON/XML functions, `TOP`, and
  ordering.
- Objects of the configured database may be written as `Table`,
  `schema.Table`, or `Database.schema.Table`; the parser keeps every part.
  Runtime filters resolve source columns only through the connected
  database's catalog, so a filter that would need a column of a
  database-qualified source cannot be placed. Four-part (linked-server) names
  in the main query's FROM/JOIN clauses, and `OPENQUERY`, `OPENROWSET`, and
  `OPENDATASOURCE` anywhere in the file, are rejected.
- Never put runtime values in the file. Clients supply values through runtime
  filters, which are always prepared parameters.
- Authored `OFFSET/FETCH` makes the resource fixed-page: any request filters,
  sort, or pagination are rejected.
- Pagination requires an approved runtime/default sort or an authored top-level
  `ORDER BY`.

The SQL inside a resource is trusted server code and is not analyzed table by
table. Review every file as backend code.

## How runtime filters are applied

Files contain no placeholders or markers. The backend transforms the statement
using the request's validated `execution.filters` placement:

| Placement | Where the predicate goes |
|---|---|
| `output` (default) | `WHERE` on a generated outer wrapper over the resource; the field must be in `execution.columns` |
| `source` | The top-level query's `WHERE` stage, before `GROUP BY`/`HAVING`/`ORDER BY`; the identifier must belong to a registered query source |
| `having` | The top-level `HAVING` stage; the expression is `COUNT`, `SUM`, `AVG`, `MIN`, or `MAX` over one identifier or `*` |

The statement analyzer identifies only top-level clause boundaries and ignores
nested queries and window expressions. A top-level `WITH` prefix is preserved
around wrappers. `source` and `having` placement on a top-level set operation
is rejected as ambiguous, and `OR` cannot combine filters placed in different
stages; use output placement or a dedicated resource instead.

## Review checklist

- Treat every file under the root as reviewed backend code.
- Keep internal directories in `exclude`.
- Use stable, unique output aliases and full path-based IDs.
- Declare only the control fields the UI actually needs; `execution.columns` is
  a control allowlist, not response projection or redaction.
- Remember that any caller with `sql.execute` or frontend access can run every
  discovered resource; keep resources that should not be broadly available out
  of the discovery root.
- Use least-privilege database permissions and review query plans.
- Test discovery, execution, each filter stage, and pagination and count
  behavior. Then test the real SQL against SQL Server.
