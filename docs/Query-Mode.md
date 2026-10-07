# JSON Query Mode

JSON Query Mode turns an allowlisted JSON structure into one prepared SQL Server
SELECT. Use `action: "select"`; the frontend never sends SQL text.

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": ["I.ItemCode", "I.Description"],
  "filters": [{ "field": "I.Status", "operator": "=", "value": "Active" }],
  "sort": [{ "field": "I.ItemCode", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 50 }
}
```

The public schema is authoritative in [JSON request reference](JSON-Request-Reference.md).

## Sources

Any table or view of the configured database can be queried by a caller holding
`data.read` (or frontend access); nothing has to be registered. Every physical
table or view referenced by a request—source, join, subquery, set-operation
branch, or CTE body—must exist in the database's `INFORMATION_SCHEMA` and each
column must exist on it, so system catalog objects and other databases never
resolve. A name the catalog does not confirm fails before any data query runs
(`500 INTERNAL_ERROR`). CTE names are request-local and must be plain identifiers.
The database login's permissions remain the final boundary.

Unpaginated results are limited to `GENERIC_MAX_RESULT_ROWS` rows (default
10,000) and return `413 RESULT_TOO_LARGE` instead of being truncated.

## Selection and aliases

Strings select columns. Objects add aliases, functions, recursive arithmetic,
unary expressions, literal nodes, or CASE. `distinct` is boolean; `limit` is a
positive SQL Server TOP.

```json
{
  "action": "select",
  "source": { "table": "BillDetails", "alias": "B" },
  "distinct": true,
  "limit": 10,
  "fields": [
    { "field": "B.ItemCode", "alias": "Code" },
    { "expression": { "left": "B.Amount", "operator": "*", "right": 1.18 }, "alias": "Gross" },
    {
      "case": {
        "when": [
          { "condition": { "field": "B.Status", "operator": "=", "value": "P" }, "then": "Paid" }
        ],
        "else": "Open"
      },
      "alias": "StatusText"
    }
  ]
}
```

The simple arithmetic and CASE forms above remain compatible. Recursive trees
use explicit `{"field":"Amount"}` and `{"literal":2}` nodes, nested
`{"expression":...}` nodes, or `{"unary":{"operator":"-","operand":...}}`.
Functions and CASE are also expression nodes. Recursive CASE branches can return
fields, literals, arithmetic, functions, or nested CASE. Values are prepared
parameters and the maximum recursive depth is 32.

## Filtering

Multiple filters use AND by default. Set `filterLogic` to uppercase `OR` to join
all top-level filters with OR. Values, including list and range members, are
prepared parameters. IN/NOT IN can use a non-empty value list or a one-column
nested query. EXISTS/NOT EXISTS require a nested query and omit `field`.

```json
{
  "action": "select",
  "source": { "table": "Customers" },
  "fields": ["CustomerCode", "Name"],
  "filters": [
    { "field": "Name", "operator": "LIKE", "value": "%John%" },
    { "field": "Status", "operator": "IN", "value": ["Active", "Pending"] }
  ],
  "filterLogic": "AND"
}
```

All operators and value shapes are in
[Filtering, sorting, and pagination](Filtering-Sorting-Pagination.md).

## Joins

Public joins are INNER, LEFT, or RIGHT with one equality predicate.

```json
{
  "action": "select",
  "source": { "table": "Orders", "alias": "O" },
  "fields": ["O.OrderId", "C.Name"],
  "joins": [{
    "type": "LEFT",
    "source": { "table": "Customers", "alias": "C" },
    "on": { "left": "O.CustomerCode", "operator": "=", "right": "C.CustomerCode" }
  }]
}
```

FULL/CROSS/APPLY, multiple ON terms, and non-equality ON operators are not public
JSON features. Use a server-owned SQL Resource when such SQL is required.

## Grouping and HAVING

HAVING entries are combined with AND. The aggregate shorthand remains supported;
an entry may alternatively use `expression`, a comparison `operator`, and a
prepared scalar `value`. `groupBy` entries may be field strings or safe
ExpressionNodes.

```json
{
  "action": "select",
  "source": { "table": "Bills" },
  "fields": [
    "CustomerCode",
    { "function": "SUM", "field": "Amount", "alias": "TotalAmount" }
  ],
  "groupBy": ["CustomerCode"],
  "having": [{ "function": "SUM", "field": "Amount", "operator": ">", "value": 1000 }],
  "sort": [{ "field": "TotalAmount", "direction": "DESC" }]
}
```

## Subqueries

Subqueries are limited to IN, NOT IN, EXISTS, and NOT EXISTS filters. A nested
body has SELECT properties but no `action`; it cannot contain sort, pagination,
or another CTE. IN/NOT IN requires one explicit field and rejects `*`.

```json
{
  "action": "select",
  "source": { "table": "Customers", "alias": "C" },
  "fields": ["C.CustomerCode", "C.Name"],
  "filters": [{
    "field": "C.CustomerCode",
    "operator": "IN",
    "query": {
      "source": { "table": "Bills" },
      "fields": ["CustomerCode"],
      "filters": [{ "field": "Amount", "operator": ">", "value": 1000 }]
    }
  }]
}
```

General FROM-derived tables and SELECT-expression subqueries are not exposed.

## CTEs

One standard CTE can be supplied through `with`:

```json
{
  "action": "select",
  "with": {
    "name": "ActiveItems",
    "query": {
      "source": { "table": "Items" },
      "fields": ["ItemCode", "Description"],
      "filters": [{ "field": "Status", "operator": "=", "value": "Active" }]
    }
  },
  "source": { "table": "ActiveItems" },
  "fields": ["ItemCode", "Description"]
}
```

A recursive CTE uses `name`, `anchor`, and `recursive`; the branches must project
the same number of fields. The recursive body may reference the named CTE, whose
logical output is inferred from the anchor.

```json
{
  "action": "select",
  "with": {
    "name": "Tree",
    "anchor": {
      "source": { "table": "Categories" },
      "fields": ["CategoryId", "ParentId", "Name"],
      "filters": [{ "field": "ParentId", "operator": "IS NULL" }]
    },
    "recursive": {
      "source": { "table": "Categories", "alias": "C" },
      "fields": ["C.CategoryId", "C.ParentId", "C.Name"],
      "joins": [{
        "type": "INNER",
        "source": { "table": "Tree", "alias": "T" },
        "on": { "left": "C.ParentId", "right": "T.CategoryId" }
      }]
    }
  },
  "source": { "table": "Tree" },
  "fields": ["CategoryId", "ParentId", "Name"]
}
```

Only one top-level CTE is accepted; nested/multiple CTE definitions are not.
Top-level pagination remains supported and keeps the CTE in count and data SQL.

## Window functions

ROW_NUMBER, RANK, DENSE_RANK, NTILE, LAG, LEAD, FIRST_VALUE, and LAST_VALUE are
public. Each requires a `sort` list inside the field object. `sort` entries may
use a logical `field` or an `expression`. Optional `partitionBy` accepts fields
and expressions.

```json
{
  "action": "select",
  "source": { "table": "Bills" },
  "fields": [
    "BillNo",
    { "function": "ROW_NUMBER", "sort": [{ "field": "BillDate", "direction": "DESC" }], "alias": "RowNo" },
    { "function": "LAG", "field": "Amount", "offset": 1, "default": 0,
      "sort": [{ "field": "BillDate", "direction": "ASC" }], "alias": "PreviousAmount" }
  ]
}
```

Window ordering never accepts a numeric position. LAST_VALUE receives the
full-partition frame internally.

## Set operations

UNION and UNION ALL are separate top-level actions, `union` and `unionAll`, not
properties of SELECT:

```json
{
  "action": "unionAll",
  "queries": [
    {
      "source": { "table": "CurrentItems" },
      "fields": ["ItemCode", "Description"]
    },
    {
      "source": { "table": "ArchivedItems" },
      "fields": ["ItemCode", "Description"]
    }
  ]
}
```

`union` removes duplicate rows; `unionAll` keeps them. Each `queries[]` member is
a JSON Query SELECT body without an `action` property.

- The list must be non-empty, and explicit projections must have the same field
  count. Wildcards are resolved through live metadata before execution; SQL
  Server checks type compatibility.
- Branches cannot contain `sort`, `pagination`, or `with`, and the combined
  request has no top-level sort or pagination.
- Success uses `Data Loaded Successfully`. Invalid shape or count is
  `INVALID_REQUEST`; metadata, build, and execution failures are `QUERY_ERROR`.

INTERSECT and EXCEPT exist only inside the builder and have no public action.
Use a SQL Resource for server-owned INTERSECT, EXCEPT, or more complex set
operations.

## Date, string, mathematical, and aggregate expressions

Functions use field objects such as:

```json
{
  "fields": [
    { "function": "UPPER", "field": "Name", "alias": "UpperName" },
    { "function": "YEAR", "field": "BillDate", "alias": "BillYear" },
    { "function": "ROUND", "field": "Amount", "precision": 2, "alias": "RoundedAmount" },
    { "function": "SUM", "field": "Amount", "alias": "TotalAmount" }
  ]
}
```

The exact public shape of every function is in
[Query function reference](Query-Functions.md). SQL Server functions absent from
that reference are not implicitly accepted by JSON Query Mode.

## What the backend does

The validator rejects unknown fields and unsupported structures. Normalization
maps public names to private builder input. Metadata checks logical tables and
columns. Builders quote identifiers and parameterize ordinary WHERE/HAVING
values, then the query engine executes the prepared SQL. The result is returned
through the [standard response](Response-Reference.md).
