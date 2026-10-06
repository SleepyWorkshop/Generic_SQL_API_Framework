# Validation, errors, and security

## Validation pipeline

Every non-OPTIONS request is decoded as one JSON object, logged with a request
ID, validated against the public action schema, normalized into private controller
input, and dispatched. Unknown request properties are rejected rather than
ignored. Action-specific validators and repositories add resource, metadata,
type, and safety checks before SQL execution.

Malformed JSON is distinct from valid JSON of the wrong type:

- malformed JSON: HTTP 400 `INVALID_JSON`;
- `null`, string, number, boolean, or JSON list body: HTTP 400 `INVALID_REQUEST`
  with path `""` and `Request body must be a JSON object.`

## Error envelope

```json
{
  "success": false,
  "message": "Invalid request.",
  "error": {
    "code": "INVALID_REQUEST",
    "details": [
      { "path": "sort.0.direction", "message": "Direction must be ASC or DESC." }
    ]
  },
  "data": []
}
```

There is no `meta` on errors. Validation details can contain several path/message
items; runtime/resource errors often contain one or none. Frontends should branch
on HTTP status and `error.code`, associate `details[].path` with form fields when
possible, and show `message` as a safe summary. Do not parse message text to infer
database details.

## Public error codes

| Code | HTTP | Meaning | Frontend handling |
|---|---:|---|---|
| `INVALID_JSON` | 400 | Request body is malformed JSON. | Fix serialization; do not retry unchanged. |
| `INVALID_REQUEST` | 400 | Unknown action/property or invalid required field/shape/value. | Show validation details and correct input. |
| `INVALID_SQL_RESOURCE` | 400 | SQL Resource ID is invalid, missing, excluded, ambiguous, or unsafe. | Use a discovered backend-published resource ID. |
| `INVALID_ROUTINE` | 400 | Routine ID is not registered in `config/routine-resources.php` for this action. | Use a backend-registered routine ID. |
| `INVALID_ROUTINE_PARAMETERS` | 400 | Positional argument count differs from the registered routine. | Send exactly the registered number of parameters. |
| `RESOURCE_ACCESS_DENIED` | 403 | Table/view, SQL Resource, or routine is unregistered or outside the caller's roles. | Do not retry; request backend registration or access. |
| `RESULT_TOO_LARGE` | 413 | An unpaginated read exceeded `GENERIC_MAX_RESULT_ROWS` (default 10,000). Rows are never silently truncated. | Request pagination or narrow the filters. |
| `INVALID_SQL_RUNTIME_FIELD` | 400 | Filter/sort field is not exposed by execution metadata. | Remove it or update the reviewed report definition. |
| `INVALID_SQL_RUNTIME_VALUE` | 400 | Runtime mapped value failed conversion, currently integer-date. | Correct the field value. |
| `INVALID_SQL_RUNTIME_FILTER` | 400 | Runtime placement is semantically unsafe/ambiguous. | Change filter logic/resource design. |
| `INVALID_SQL_PAGINATION` | 400 | Pagination lacks an approved sort or conflicts with authored OFFSET/FETCH. | Supply approved sorting or use the resource's fixed behavior. |
| `INVALID_WRITE_RESOURCE` | 400 | Write ID is absent or action not enabled. | Do not retry; request backend configuration. |
| `UNSAFE_WRITE` | 400 | UPDATE/DELETE targeting is missing or empty. | Require at least one filter. |
| `INVALID_WRITE_COLUMN` | 400 | Column is not writable/filterable or is generated. | Remove/replace the field. |
| `INVALID_WRITE_VALUE` | 400 | Value fails live type/range/format/null/length rules. | Correct the indicated data/filter value. |
| `MISSING_REQUIRED_FIELD` | 400 | Required insert/upsert database column is missing. | Supply the indicated data field. |
| `INVALID_UPSERT_KEY` | 400 | Keys are disabled, mismatch config, lack data, or lack an exact unique index. | Send the configured set and values; otherwise fix backend/database configuration. |
| `DUPLICATE_KEY` | 409 | SQL Server duplicate/unique-key conflict. | Refresh state or ask the user to choose a different key. |
| `CONSTRAINT_VIOLATION` | 409 | Recognized constraint, null, truncation, or reference failure. | Correct input/current state; do not blind-retry. |
| `QUERY_ERROR` | 500 | Non-disclosed generation, metadata, connection, or execution failure. | Show a generic failure and correlate server logs by request ID. |
| `QUERY_ERROR` | 504 | Application/PHP query timeout. | Offer retry/cancel; investigate query/timeout server-side. |

The legacy internal controller/action-not-found branches can return HTTP 404 with
the default `INTERNAL_ERROR` code, but the public action validator prevents a
valid public request from reaching them. They are not normal public action errors.

## Security controls

### Query construction

- Public actions and properties are explicitly allowlisted.
- Identifiers have restricted syntax; JSON Query tables/columns are checked
  against live metadata.
- Operators, functions, join shapes, sort directions, and expressions are
  allowlisted rather than concatenated from arbitrary SQL.
- Ordinary filter/HAVING values and routine arguments are prepared parameters.

### SQL Resource Mode

- Clients select a safe path-derived ID or unique basename, never SQL or a file path.
- Resolved files must remain inside the configured root; internal directories
  are excluded and discovery collisions fail closed.
- Resources must analyze as one read-only SELECT/CTE statement; SELECT INTO and
  multiple statements are rejected.
- Output, runtime filter, and sort fields come from strictly validated execution metadata.
- Public expressions accept only output/source identifiers or a narrow aggregate
  grammar with fixed output/source/HAVING placement values.
- Runtime values use prepared parameters.

### Write API

- Only entries explicitly present in the write-resource registry are writable;
  all other resource IDs are denied.
- Public IDs map to private schema/table names and enabled actions.
- Writable/filterable/key/identity columns are allowlisted and checked against
  live SQL Server metadata.
- Generated columns are protected; data/filter values are type checked and
  prepared.
- Empty UPDATE/DELETE targeting is explicitly rejected.
- UPSERT key configuration is checked against an exact database unique index.

### Credentials and logging

The recommended database file is an AES-256-GCM encrypted envelope containing the
complete configuration; its 32-byte Base64 key comes separately from
`GENERIC_SQL_API_ENCRYPTION_KEY`. Plaintext remains readable only for migration/
compatibility. Neither credentials nor the key belong in requests or source
control. Credential exceptions are logged without a trace, public database
failures are sanitized, and query logging omits parameter values. Logs themselves
remain sensitive operational data and require access controls.

Database failures additionally record the request ID, execution phase, safe
SQLSTATE, error category, and a redacted/truncated driver message. The client
continues to receive an empty `details` array by design; driver text is never a
public error detail.

## Explicit security boundaries

The API implements configurable session/API-key authentication, fixed backend
and frontend roles, SQL/write-resource scopes, administrator authorization,
session-bound CSRF for cookie-authenticated mutations, login throttling, and a
general local API rate limit. It does not implement tenant isolation, per-column
read authorization, editable roles, or distributed multi-host rate limiting.
Routine actions execute only routines registered in `config/routine-resources.php`
(deny-by-default; the SQL identifier comes from the registry, never the request).
JSON Query Mode, metadata listings, and SQL Resource source filters are limited to
tables/views registered in `config/query-sources.php`. Least-privilege database
credentials and production network/TLS controls remain essential.

CORS is not access control. Exact origins are loaded from validated backend
configuration, while non-browser clients must still satisfy the configured
authentication mode. API keys use `X-API-Key`, not bearer transport. The API
accepts POST and OPTIONS only.
