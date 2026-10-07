# SQL Parser

The SQL Parser (SQL → API JSON generator) translates supported SQL into the framework's existing
Universal API JSON. It is an independent application and never executes pasted
SQL or opens a database connection.

## Run it

- **Development:** the launchers start the parser on a loopback port; System
  Health starts, stops, or restarts it and shows the selected port.
- **Production:** IIS or Nginx serves `sqlparser/index.php` and its assets on a
  loopback or internal-only boundary (the `/sqlparser` IIS application or a
  separate internal Nginx site). Admin System Health enables, disables, or
  reloads it; a disabled parser returns `503 SERVICE_UNAVAILABLE`. Do not expose
  it publicly, and consider a dedicated worker pool with no access to
  application state.

The parser does not use Admin authentication, configuration, sessions, the API
runtime, or database credentials. Requests are limited to 200,000 bytes,
responses are not cached, pasted SQL is not logged, and the parser accepts no
execution option.

## Architecture

```text
SQL input
  → SqlLexer tokens
  → SqlParser recursive abstract syntax tree
  → SqlCapabilityAnalyzer
  → SqlToApiMapper
  → existing QueryRequestValidator
  → existing QueryRequestNormalizer compatibility check
  → public Universal API JSON
```

This is a reverse adapter, not another query engine. Generated property names are
the same public names accepted by the API: `action`, `source`, `fields`,
`filters`, `joins`, `groupBy`, `having`, `sort`, `distinct`, `limit`, and the
existing set-operation `queries` shape. Internal normalized builder keys are not
emitted.

## Parser choice

The parser has no third-party dependency: available open-source PHP SQL parsers
target the MySQL dialect rather than SQL Server. The tool instead uses an isolated
character-level lexer and recursive-descent/precedence parser that produces a
real AST. Clause recognition is not implemented with SELECT/FROM regular
expressions. Unsupported grammar fails explicitly rather than being guessed.

## Supported conversion

- one SELECT with table and optional aliases;
- selected identifiers and aliases;
- DISTINCT and SQL Server TOP, mapped to public `distinct` and `limit`;
- INNER, LEFT, and RIGHT equality joins;
- legacy comma-separated FROM sources when each additional source has exactly
  one unambiguous AND equality predicate connecting it to an earlier source;
- flat WHERE conditions using one AND or OR logic;
- comparisons, LIKE/NOT LIKE, BETWEEN/NOT BETWEEN, IN/NOT IN, and NULL tests;
- `IN (SELECT ...)`, `NOT IN (SELECT ...)`, `EXISTS (SELECT ...)`, and
  `NOT EXISTS (SELECT ...)` using the backend's existing nested filter query;
- GROUP BY identifiers or recursive scalar expressions;
- supported aggregate HAVING comparisons, including scalar/arithmetic expressions
  containing aggregates compared with a literal value (AND only);
- ORDER BY fields, selected aliases, or recursive expressions with ASC/DESC;
- COUNT, SUM, AVG, MIN, MAX and selected simple scalar functions when their
  arguments match the exact public function schema;
- recursive arithmetic, unary arithmetic, nested functions, and aggregate over
  expression/CASE mapping with AST precedence and parentheses preserved;
- searched CASE with one comparison per WHEN and recursive expression branches;
- supported window `OVER (PARTITION BY ... ORDER BY ...)` functions, including
  scalar expression inputs, partitions, and ordering;
- one standard CTE and the existing two-branch recursive CTE form;
- homogeneous UNION or UNION ALL chains using the existing public actions;
- page-aligned SQL Server `ORDER BY ... OFFSET n ROWS FETCH NEXT m ROWS ONLY`,
  mapped to public `pagination.page` and `pagination.pageSize`;
- safe named-property mappings for DATEDIFF, EOMONTH, DATEFROMPARTS,
  DATETIMEFROMPARTS, IIF, and CHOOSE, plus sized CONVERT datatypes;
- `EXEC`/`EXECUTE` stored procedure authoring with comma-separated positional
  scalar literal parameters, mapped to the existing `procedure` action;
- quoted strings, numeric literals, comments, bracketed identifiers, and a
  trailing semicolon.

Every successful result is passed through the production
`QueryRequestValidator` and `QueryRequestNormalizer`. A developer can copy it directly to the normal API,
subject to the API's usual live table/column metadata validation at execution.

## Deliberate limitations

The generator rejects or explains constructs that the public JSON contract cannot
represent, including FULL/CROSS/APPLY joins, non-equality or multi-term JOIN ON,
mixed AND/OR filters, HAVING OR, positional/literal ORDER BY, mixed UNION/UNION ALL
chains, branch ordering, derived tables, subqueries not covered by the mapper,
multiple CTE definitions, CTE column-name lists, and expression-valued
WHERE comparison values or BETWEEN endpoints. WHERE still requires a direct
field on the left; reviewed comma-join equality edges use the existing safe
join conversion rather than expanding the WHERE expression contract.

Filter subqueries are SELECT bodies only. IN/NOT IN must return exactly one
explicit field. Nested SELECT bodies cannot contain action, sort, pagination, or
WITH properties, so set-operation subqueries, correlated field-to-field filters,
derived tables, and scalar projection subqueries remain unsupported. Boolean
grouping is not preserved: one WHERE may use flat AND or flat OR, not a mixed
tree. OFFSET must be an exact multiple of FETCH because the public contract is
page-based; OFFSET/FETCH also requires ORDER BY. SQL placeholders such as
`:name` have no public parameter-reference node and remain unsupported.

Nested functions and arithmetic are always parsed recursively. A generic AST
expression dispatcher preserves identifiers, literals, grouping, unary/binary
operators, functions, CASE, and windows. Positional function arguments are
translated through declarative public-property schemas; function admission,
recursive-input eligibility, and window/aggregate categories come from the
backend's `QueryFunctionRegistry`, exposed through `SqlBackendCapabilities`.
Structural arguments retain their existing named properties and restrictions.
There is no unrestricted public `arguments` array.

The capability analyzer checks mapped expression probes with the real backend
validator in their clause contexts instead of maintaining a second recursive
function/signature/context allowlist. Thus nested aggregates, illegal window
contexts, invalid datatypes, and excessive expression depth remain rejected.
The generator additionally validates and normalizes the complete mapped request,
including aliases and CTE/set-operation envelopes. Rejected requests never
expose a partial candidate. The parser AST remains internal to the parser.

Parser syntax errors include the byte position plus one-based line and column
and a nearby SQL fragment. Capability failures explain the backend/public
contract boundary; unsupported syntax never becomes a raw JSON or SQL fragment.

For example:

```sql
ROUND(SUM(BIL.Item_Rate) / 100000, 0)
```

now generates this public projection node:

```json
{
  "function": "ROUND",
  "field": {
    "expression": {
      "left": { "function": "SUM", "field": { "field": "BIL.Item_Rate" } },
      "operator": "/",
      "right": { "literal": 100000 }
    }
  },
  "precision": 0
}
```

Fields use `{ "field": "..." }`; values use `{ "literal": ... }` in recursive
positions. Unary nodes use `{ "unary": { "operator": "-", "operand": ... } }`.
CASE comparisons use `{left,operator,right}` nodes and recursive `then`/`else`.
The same mapper emits GROUP BY entries, HAVING `expression`, ORDER BY
`expression`, and window partition/order expressions. Qualification is retained;
projection aliases stay outside expression trees.

Legacy identifier projections, simple functions (for example
`ROUND(Sale_Rate,2)`), one-level numeric/identifier arithmetic, simple literal
CASE, aggregate HAVING, alias sorting, and unpartitioned windows keep their
established compatible JSON. One standard CTE or the existing recursive CTE
object remains supported, with recursive expressions inside its SELECT bodies;
homogeneous UNION/UNION ALL branches also reuse projection mapping.

### Current implementation boundaries

Historical parser designs are not runtime authorities. Simple fields/functions
retain their established private normalized shapes while recursive nodes
normalize to a type-tagged AST with function `input` and named `options`. The
generator emits only public JSON and leaves this compatibility adapter intact.

Not every allowlisted function has a safe SQL reverse mapping or recursive
renderer. For example, direct CONCAT/COALESCE conversion is retained but nesting
these functions inside a recursive expression remains unsupported. Their
non-primary structural arguments are not expanded to arbitrary expressions.
Direct `COUNT(*)` remains supported, but a wildcard input inside a recursive
function tree is rejected: the current canonical backend field renderer cannot
render `*`. This pre-existing validator/renderer discrepancy is deliberately not
fixed by changing backend expression behavior in the parser.

### Regression matrix

| Query | Result |
|---|---|
| 1: ROUND(stock × rate) | SUPPORTED |
| 2: aggregate over stock × rate | SUPPORTED |
| 3: ROUND(SUM(rate) / 100000), safe comma join, TOP and alias ordering | SUPPORTED |
| 4: nested SUBSTRING/CONVERT in SELECT, GROUP BY and ORDER BY | SUPPORTED |
| 5: year/month expression grouping and mixed sort directions | SUPPORTED |
| 6: recursive aggregate expression HAVING | SUPPORTED |
| 7: full two-CTE ranked sales query with computed BETWEEN bounds | REJECTED: multiple CTE definitions and expression-valued BETWEEN endpoints only |

`tests/SqlParserGeneratorTest.php` pins complete golden public JSON for Queries
1–6, real validator/normalizer round trips, Query 7's exact two diagnostics, and
separately supported CASE/aggregate, date-boundary projection, partitioned-window,
CTE, and UNION expression fixtures. Security fixtures cover malicious identifiers,
aliases, function names, operators and datatypes, statement/raw fragments,
malformed AST nodes, ambiguous public nodes, depth limits, and illegal aggregate
or window contexts. These tests never load database execution infrastructure.

Run `php tests/run.php` and `php -n tests/run.php`; the parser suite is also
independently runnable with `php tests/SqlParserGeneratorTest.php`.
The suite runner propagates no-INI execution to its child processes when invoked
with `php -n`, so the second command verifies the extension-minimal path as well.

## Relationship to the API

SQL Parser has its own document root, router, and request handler. The Admin
Console controls only its availability through fixed lifecycle operations. Neither
Admin nor the normal API imports the parser generator, and the parser works
without SQL Server, credentials, or `database/config/database.json`.
