# JSON Request Reference

This document defines the public JSON accepted by `QueryRequestValidator` and transformed by `QueryRequestNormalizer`. It does not document the private PHP builder arrays.

## Common request

| Name | Type | Required | Allowed/default | Notes |
|---|---|---:|---|---|
| `action` | string | yes | See action table; no default | Case-sensitive |

Unknown top-level properties are rejected for every action.

| Action | Required fields | Optional fields |
|---|---|---|
| `select` | `source`, `fields` | `filters`, `joins`, `groupBy`, `having`, `sort`, `pagination`, `distinct`, `limit`, `filterLogic`, `with` |
| `sql` | `resource` | `execution`, `filters`, `sort`, `pagination`, `filterLogic`, `database` |
| `insert` | `table`, non-empty `data` object | `database` |
| `update` | `table`, non-empty `data` object, non-empty `filters` | `filterLogic`, `database` |
| `delete` | `table`, non-empty `filters` | `filterLogic`, `database` |
| `upsert` | `table`, non-empty `data` object, non-empty `keys` list | `database` |
| `union`, `unionAll` | non-empty `queries` | none |
| `procedure` | `source.procedure` | `parameters`, `database` |
| `function`, `tableFunction` | `source.function` | `parameters`, `database` |
| `metadata.columns` | `source.table` | `database`, `source.schema` |
| `metadata.tables`, `metadata.views`, `metadata.procedures`, `metadata.schema` | none beyond `action` | `database` |
| `metadata.databases` | none beyond `action` | none |

Identifiers use `^[A-Za-z_][A-Za-z0-9_.]*$`: letters/underscore first, then letters, digits, underscores, or dot qualifiers. This is syntax validation; SELECT builders also check tables and columns against live metadata.

The `sql` action overview is documented in [API](API.md#controlled-sql-resource-request).
Backend developers should use [SQL Resource authoring](SQL-Resource-Authoring.md)
for discovery and file details.
Resource IDs use slash-separated segments matching
`[A-Za-z0-9][A-Za-z0-9_-]*`. They resolve to discovered `.sql` files beneath the
fixed root. Sort and filter fields come from validated `execution` metadata;
arbitrary SQL is never accepted.

These restrictions describe client-composed JSON. A discovered SQL Resource is
backend-owned SQL and may use SQL Server functions, CTEs, joins, windows,
subqueries, and set operations that are intentionally not exposed by the JSON
Query function or expression allowlists. The client still supplies only the
resource ID and documented runtime controls, never SQL text.

### SQL execution metadata

`execution` is optional and accepted only by `sql`:

| Name | Shape | Rules |
|---|---|---|
| `columns` | non-empty unique identifier list | Output controls only; not projection/redaction. |
| `filters` | object keyed by unique logical identifiers | Each mapping has `expression`, `placement`, and optional `valueType`. |
| `defaultSort` | non-empty sort list | Fields must be in `columns`; directions are `ASC`/`DESC`. |

An output mapping expression must exactly match an execution column. A source
expression is one optionally qualified identifier. A having expression is
`COUNT`, `SUM`, `AVG`, `MIN`, or `MAX` over one identifier or `*`. Placements are
`output`, `source`, and `having`; the only value type is `integer-date`. An
execution column is automatically usable as an output filter unless an explicit
mapping with the same logical name overrides it. Pagination requires an approved
runtime sort or default sort.

## CRUD writes

CRUD `table` is `Table` or `Schema.Table`, each part matching
`[A-Za-z_][A-Za-z0-9_]*`. Three-part names, brackets, the `sys` and
`INFORMATION_SCHEMA` schemas, and tables that do not exist in the target
database are rejected. The optional top-level `database` selects the one
target database (default: the default database); see
[Write API](Write-API.md#target-database). Clients cannot send `source`, SQL, expressions, file
paths, metadata, or connection information. `data` is a JSON object keyed by
unqualified column names; every value must be a string, number, boolean, or
null. Arrays and nested objects are not write values.

Write columns are case-insensitively matched to the table's live SQL Server
metadata. Integer, numeric, bit, string/length, ISO date/time,
UUID, binary-string, nullability, required/default, identity, computed, and
rowversion rules are validated before SQL execution. Unsupported database types
are rejected instead of being guessed. Defaults are used by omitting their
columns; clients cannot request a SQL DEFAULT expression.

UPDATE and DELETE filters use this shape:

| Name | Type | Required | Allowed/default |
|---|---|---:|---|
| `filters` | array | yes | Non-empty; an empty/missing list is `UNSAFE_WRITE` |
| `filters[].field` | unqualified identifier | yes | An existing column of the table |
| `filters[].operator` | string | yes | `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `IS NULL`, `IS NOT NULL` |
| `filters[].value` | scalar/array | except NULL forms | Non-empty list for IN; exactly two values for BETWEEN |
| `filterLogic` | string | no | `AND`; `OR` also accepted |

Write filters do not accept `query`, EXISTS, NOT EXISTS, or dotted fields. A null
comparison must use IS NULL/IS NOT NULL. For UPSERT, `keys` is a required unique
list of unqualified column names that must exactly match the table's primary key
or an unfiltered unique index; all key values must exist in `data` and be
non-null. UPSERT does not accept
filters or `filterLogic`.

All four actions are single-object operations. There is no bulk request shape,
transaction property, begin/commit/rollback action, arbitrary returned-column
selection, or client override for identity insertion.

## SELECT fields

| Name | Type | Required | Allowed/default | Example/notes |
|---|---|---:|---|---|
| `database` | database id | no | Base source database, else the default database | Top-level request only; see [Database selection](#database-selection) |
| `source` | object | yes | Exactly `table`, optional `alias`, `schema`, `database` | `{"table":"Items","alias":"I"}` |
| `source.table` | identifier | yes | No default | One table or matching CTE name; dotted names such as `dbo.Items` or `Db.dbo.Items` are rejected |
| `source.alias` | identifier | no | none | Table alias; a single identifier |
| `source.schema` | identifier | no | none (SQL Server's default schema for the login) | One schema name; `sys` and `INFORMATION_SCHEMA` are rejected; not allowed on a CTE reference |
| `source.database` | database id | no | The request's primary database | Registered database id; not allowed on a CTE reference |
| `fields` | array | yes | Non-empty | Strings or field objects |
| `fields[]` string | identifier | no | `*` also allowed | `"I.ItemCode"` |
| `fields[].field` | identifier | conditional | `*` for applicable aggregate | Public name for a selected/function field |
| `fields[].alias` | identifier | no | Plain fields: none; functions: lowercase function name; CASE: `CaseValue`; arithmetic: `Expression` | Explicit aliases are recommended |
| `distinct` | boolean | no | `false` | Adds DISTINCT |
| `limit` | integer | no | none; minimum 1 | SQL Server TOP |

A field object requires one expression type: `field`, `function`, `case`,
`expression`, `unary`, or `literal`. Function objects additionally use only the
properties defined for that function. `alias` is accepted only on the selected
field envelope, not inside a recursive expression.

### Aliases and expressions

```json
{
  "fields": [
    { "field": "Description", "alias": "ItemName" },
    { "expression": { "left": "Amount", "operator": "*", "right": 1.18 }, "alias": "Gross" },
    {
      "case": {
        "when": [{ "condition": { "field": "Status", "operator": "=", "value": "A" }, "then": "Active" }],
        "else": "Inactive"
      },
      "alias": "StatusText"
    }
  ]
}
```

The displayed arithmetic and CASE forms are retained legacy shorthands. New
recursive operands use explicit nodes so string fields cannot be confused with
string values:

```json
{
  "expression": {
    "left": {"field":"Amount"},
    "operator": "/",
    "right": {
      "expression": {
        "left": {"field":"Units"},
        "operator": "+",
        "right": {"literal":1}
      }
    }
  }
}
```

An expression node is exactly one of `{"field":identifier}`,
`{"literal":scalar-or-null}`, `{"expression":{left,operator,right}}`,
`{"unary":{operator,operand}}`, an allowlisted function object, or a CASE
object. Recursive binary children must use explicit nodes; operators are `+`,
`-`, `*`, `/`, and `%`. Unary operators are `+` and `-`. Expression depth is
limited to 32. Literal nodes are prepared parameters except a finite numeric
literal used directly as a division divisor: that structural expression constant
is emitted as a SQL number to avoid SQL Server/ODBC parameter-type inference
failures. Strings (including numeric strings), booleans, and null never use this
exception. Runtime filter/BETWEEN and HAVING comparison values remain prepared
parameters, including numeric values.

Recursive CASE conditions use `{left,operator,right}` expression nodes and
`then`/`else` expression nodes. The legacy `{field,operator,value}` condition
and literal branches remain accepted.

## Functions

Every function object uses `function` and normally an `alias`. Requirements below are the usable public forms.

| Functions | Additional public properties |
|---|---|
| `COUNT`, `SUM`, `AVG`, `MIN`, `MAX` | `field` (`COUNT` may use `*`) |
| `STRING_AGG` | `field`, `separator`; optional `sort` |
| `UPPER`, `LOWER`, `LTRIM`, `RTRIM`, `TRIM`, `LEN` | `field` |
| `COALESCE` | non-empty identifier array `fields`; optional literal `default` |
| `ISNULL` | `field`, literal `default` |
| `NULLIF` | `field`, literal `value` |
| `CAST` | `field`, `datatype` |
| `CONVERT` | `field`, `datatype`; optional integer `style` |
| `CONCAT` | at least two identifier entries in `fields` |
| `LEFT`, `RIGHT` | `field`, `length` |
| `SUBSTRING` | `field`, `start`, `length` |
| `REPLACE` | `field`, `search`, `replace` |
| `CHARINDEX` | `field`, `search` |
| `PATINDEX` | `field`, `pattern` |
| `FORMAT` | `field`, `format`; optional `style` |
| `YEAR`, `MONTH`, `DAY` | `field`; builder treats it as integer `YYYYMMDD` via style 112 |
| `DATEPART`, `DATENAME` | `field`, `part`; same integer-date conversion. Parts: YEAR, QUARTER, MONTH, DAYOFYEAR, DAY, WEEK, WEEKDAY, HOUR, MINUTE, SECOND, MILLISECOND |
| `GETDATE`, `SYSDATETIME`, `CURRENT_TIMESTAMP` | no field |
| `DATEADD` | `field`, `datepart`, `number`; optional `style` |
| `DATEDIFF` | `datepart`, `start`, `end`; each endpoint is `{"field":"DateField"}` or `{"function":"GETDATE"}`, with optional `style` on field endpoints |
| `EOMONTH` | `start` endpoint as above; optional `month` offset |
| `ISDATE` | `field`; optional `style` |
| `DATEFROMPARTS` | `year`, `month`, `day` |
| `DATETIMEFROMPARTS` | `year`, `month`, `day`, `hour`, `minute`, `second`, `millisecond` |
| `IIF` | `condition: {left, operator, right}`, `true`, `false` |
| `CHOOSE` | `index`, `values` with at least two values |
| `ABS`, `CEILING`, `FLOOR`, `SQRT`, `EXP`, `LOG` | `field` |
| `ROUND` | `field`; optional `precision` default 0 |
| `POWER` | `field`, `power` |

Function-specific required options are validated before normalization. Lengths, window offsets/buckets, and `CHOOSE.index` are positive integers (`SUBSTRING.length` may be zero); styles and numeric precisions use JSON integers. Date endpoints are either `{"field":"DateField"}` with an optional integer `style`, or `{"function":"GETDATE"}`. Nested field/arithmetic expressions used by conditional and date-part constructors are shape-checked and their referenced columns are validated through metadata.

`datatype` must use an approved SQL Server type name, optionally followed by
numeric size/precision, such as `date`, `varchar(50)`, or `decimal(10,2)`.
Approved base names are BIGINT, BINARY, BIT, CHAR, DATE, DATETIME, DATETIME2,
DATETIMEOFFSET, DECIMAL, FLOAT, IMAGE, INT, MONEY, NCHAR, NTEXT, NUMERIC,
NVARCHAR, REAL, SMALLDATETIME, SMALLINT, SMALLMONEY, TEXT, TIME, TINYINT,
UNIQUEIDENTIFIER, VARBINARY, VARCHAR, and XML. `TIMEFROMPARTS` cannot currently
be expressed publicly because the validator rejects its builder-required
`fractions` property.

## Filters

| Name | Type | Required | Allowed/default |
|---|---|---:|---|
| `filters` | array | no | empty |
| `filters[].operator` | string | yes | `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `IS NULL`, `IS NOT NULL`, `EXISTS`, `NOT EXISTS` |
| `filters[].field` | identifier | except EXISTS forms | none |
| `filters[].value` | any/array | depends on operator | scalar comparisons; non-empty list for IN; exactly two values for BETWEEN; omitted for NULL/EXISTS forms |
| `filters[].query` | SELECT body | IN/NOT IN alternative, required for EXISTS forms | Nested object without a required `action` |
| `filterLogic` | string | no | `AND` (or `OR`) |

Ordinary values, IN lists, BETWEEN bounds, and HAVING values become prepared parameters. A `YYYY-MM-DD` BETWEEN bound is converted to integer `YYYYMMDD` only when live metadata reports an integer-family column.

## Joins, grouping, HAVING, and sorting

| Name | Type | Required | Allowed/default |
|---|---|---:|---|
| `joins` | array | no | empty |
| `joins[].type` | string | yes | `INNER`, `LEFT`, `RIGHT` (case-insensitive during normalization) |
| `joins[].source` | object | yes | `table`, optional `alias`, `schema`, `database` |
| `joins[].on.left/right` | identifier | yes | Logical fields |
| `joins[].on.operator` | string | no | `=` only; defaults to `=` |
| `groupBy` | identifier/ExpressionNode array | no | empty; aggregate/window nodes rejected |
| `having` | array | no | empty; combined with AND |
| `having[].function` | string | yes | `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, `STRING_AGG` |
| `having[].field` | identifier or `*` | yes | none |
| `having[].operator` | string | yes | `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=` |
| `having[].value` | any | yes | Prepared parameter |
| `having[].expression` | ExpressionNode | alternative to `function`/`field` | Must contain an aggregate |
| `sort` | array | no | empty/default builder ordering |
| `sort[].field` | identifier | yes | Logical source field or selected top-level alias; numeric position rejected |
| `sort[].expression` | ExpressionNode | alternative to `field` | Rendered expression; numeric position still rejected |
| `sort[].direction` | string | no | `ASC`; also `DESC` |

FULL/CROSS joins, non-equality join predicates, multiple ON predicates, HAVING OR logic, and public positional ordering are unsupported.

## Pagination

| Name | Type | Required | Allowed/default |
|---|---|---:|---|
| `pagination` | object | no | no pagination |
| `pagination.page` | integer | yes when object present | minimum 1 |
| `pagination.pageSize` | integer | no | configured default when omitted; minimum 1 and configured maximum |

There is no implicit page or page size. Pagination normally returns a total from a separate count, and its SQL strategy is selected from SQL Server compatibility level. SQL Resource Mode has a documented complete-first-page `TOP` optimization that can infer the total instead.

## Window fields

All window functions require `sort`, whose entries have the same public
field-or-expression shape as top-level sorting. Optional `partitionBy` is an
array of logical field strings and/or ExpressionNodes. Aggregate and window
nodes are rejected inside partitions.

| Function | Additional properties |
|---|---|
| `ROW_NUMBER`, `RANK`, `DENSE_RANK` | `sort`; optional `partitionBy` |
| `NTILE` | positive `buckets`, `sort`; optional `partitionBy` |
| `LAG`, `LEAD` | `field`, `sort`; optional positive `offset`, `default`, and `partitionBy` |
| `FIRST_VALUE`, `LAST_VALUE` | `field`, `sort`; optional `partitionBy` |

## CTE and subquery bodies

A standard CTE is `"with":{"name":"ActiveItems","query":{...select body...}}`. A recursive CTE is `"with":{"name":"Tree","anchor":{...},"recursive":{...}}`. Only one `with` object is accepted. Each branch uses SELECT fields such as `source` and `fields`; `action` is not accepted. The backend infers the CTE output names from its projection so outer fields, filters, grouping, and ordering are validated without querying `INFORMATION_SCHEMA` for a nonexistent physical table. Recursive branches must return the same number of fields. Top-level pagination is supported and keeps the CTE prefix on both count and data queries.

Subqueries are accepted only as filter `query` values for IN, NOT IN, EXISTS, and NOT EXISTS. IN/NOT IN subqueries must select exactly one explicit field; `*` is rejected. Nested SELECT bodies do not accept `action`, `sort`, `pagination`, or another `with`. General FROM/SELECT-expression and nested-CTE subqueries are not exposed.

## Set operations

```json
{
  "action": "unionAll",
  "queries": [
    { "source": { "table": "Items" }, "fields": ["ItemCode"] },
    { "source": { "table": "ArchivedItems" }, "fields": ["ItemCode"] }
  ]
}
```

A set-operation request also accepts a top-level `database`. `queries` is a non-empty array of SELECT bodies without nested actions, sorting, pagination, or CTEs. Explicit branch projections must have the same field count during public validation; wildcard counts are resolved from metadata before execution. SQL Server remains responsible for checking data-type compatibility between corresponding expressions. The current set-operation contract has no top-level sorting or pagination. Public actions are only `union` and `unionAll`; internal support for INTERSECT/EXCEPT is not public.

## Database selection

`select`, `union`, and `unionAll` requests can name databases by their registry
id (lowercase letters, digits, `_`, `-`; configured by an operator). A request
never supplies a physical database name, server, port, or credential.

- The top-level `database` is the request's primary database. Without it, the
  base source's `source.database` is primary (for set operations, the first
  branch's source); without either, the configured default database is used,
  so requests without `database` behave as before.
- `source.database` may appear on the base source, join sources, filter
  subquery sources, CTE branch sources, and set-operation branch sources.
  Nested SELECT bodies do not accept a top-level `database`. A source without
  `database` belongs to the primary database.
- Every named database must be configured and enabled, on an enabled server
  profile, and available; otherwise the request fails with
  `DATABASE_NOT_FOUND`, `DATABASE_DISABLED`, `SERVER_PROFILE_DISABLED`, or
  `DATABASE_UNAVAILABLE`. Error details give the path where it was named.
- All databases in one request must belong to the primary database's server
  profile, or the request fails with `CROSS_SERVER_QUERY_NOT_SUPPORTED`.
- A request that names more than one database on one server profile runs as
  one SQL statement on one connection, opened to the primary database. Every
  physical source is then rendered with its own database, for example
  `[InventoryDB].[dbo].[Product] AS [p]`, or `[InventoryDB]..[Product] AS
  [Product]` without a schema or alias. Column references keep using the
  source's alias (or table name). Sources that share a table name, or an
  alias, need distinct aliases. Subqueries still cannot refer to aliases of
  the outer query.

```json
{ "action": "select", "database": "inventory", "source": { "schema": "sales", "table": "Product" }, "fields": ["ProductCode"] }
```

Each source resolves on the server to a structured object: the registered
database's physical name, the schema, and the table, each validated and
quoted as a separate SQL Server identifier. Clients never supply the
physical name, and multi-part names are never parsed from one field. A
source with `schema` renders as `[schema].[table]`; a source without one
renders exactly as in V2 and resolves through SQL Server's default schema.
Two sources with the same table name must name the same object, and a
source with `schema` is validated against that schema's catalog entry.

Metadata actions and routines accept a top-level `database` the same way; see
[Metadata and routines](Metadata-and-Routines.md). A SQL Resource names its
databases with `{{database:id}}` placeholders, or takes the request's
`database` when it has none; see
[SQL Resource authoring](SQL-Resource-Authoring.md#database-placeholders).
`metadata.databases` lists the ids that can be named. Writes accept one
top-level `database` as their single target. Cross-server queries are not
supported.

## Routines and metadata

Routine `parameters` is an optional positional array and defaults to `[]`:

```json
{ "action": "procedure", "source": { "procedure": "dbo.RunReport" }, "parameters": [2026, true] }
```

```json
{ "action": "function", "source": { "function": "dbo.Score" }, "parameters": [42] }
```

```json
{ "action": "tableFunction", "source": { "function": "dbo.RowsForYear" }, "parameters": [2026] }
```

The shared source validator also accepts an optional identifier `source.alias` on routine and `metadata.columns` requests, but the normalizer discards it and it has no execution effect. Do not depend on it. `parameters` is only checked as a decoded PHP array; clients should send a JSON list because routine placeholders are positional.

Metadata requests are `{"action":"metadata.tables"}`, `metadata.views`, `metadata.procedures`, or `metadata.schema`, each with an optional top-level `database`. Columns uses `{"action":"metadata.columns","source":{"table":"Items"}}`, optionally with `source.schema` and a top-level `database`. `{"action":"metadata.databases"}` accepts nothing else.

## Public versus internal names

| Public JSON | Private normalized builder key |
|---|---|
| `source.table` / `source.alias` | `table` / `alias` |
| `fields` / field-object `field` | `columns` / `column` |
| `filters` / filter `field` / filter `query` | `where` / `column` / `subquery` |
| `filterLogic` | `condition` |
| `limit` | `top` |
| `sort[].field` | `sort[].column` |
| window field `sort` | `orderBy` |
| `pagination.page`, `pagination.pageSize` | top-level `page`, `pageSize` |
| `with.query` | `cte.query` |
| recursive `with` | `recursiveCte` |
| routine `parameters` | `params` |

Clients must use the left column only.

Recursive expressions, including legacy arithmetic and CASE shorthands, normalize
to the private type-tagged `node` AST (`field`, `literal`, `binary`, `unary`,
`function`, or `case`). Direct columns and non-recursive legacy function objects
retain their established private builder keys because repository-level callers
and compatibility tests use that internal surface. Both paths share identifier
metadata validation; only the canonical node path accepts recursive children.
