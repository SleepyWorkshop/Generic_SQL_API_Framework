# Errors and validation

This document describes how requests are validated, the error envelope, every
public error code, and how unexpected failures are kept out of responses.
Success envelopes are in [Response reference](Response-Reference.md).

## Validation pipeline

Every non-`OPTIONS` API request is read with a bounded body reader, decoded,
validated against the public action schema, normalized into private controller
input, and dispatched. Unknown properties are rejected, not ignored.
Action-specific validators and repositories then check resources, live
metadata, types, and write safety before any SQL runs.

- Malformed JSON: `400 INVALID_JSON`.
- `null`, a string, a number, or a boolean body: `400 INVALID_REQUEST` with
  path `""` and `Request body must be a JSON object.`
- A JSON list body: `400 INVALID_REQUEST` on the Admin API. On the public API a
  list carries no `action`, so it is rejected by authentication (`401`) or
  authorization (`403`) before validation.

## Error envelope

All controlled API, Admin API, and SQL Parser errors use one envelope:

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
  "data": [],
  "meta": {
    "requestId": "7f4dd403d84c99e1"
  }
}
```

- `data` is always an empty array; `details` is always an array and may be
  empty. Validation errors can carry several `path`/`message` items.
- `meta.requestId` (also sent as `X-Request-ID`) matches the server logs.
- Error responses use `application/json; charset=utf-8` and
  `Cache-Control: no-store`.

Clients should branch on HTTP status and `error.code`, map `details[].path` to
form fields where possible, and show `message` as a safe summary. Do not parse
message text.

## Request and security codes

| Code | HTTP | Meaning |
|---|---:|---|
| `INVALID_JSON` | 400 | Malformed JSON body |
| `INVALID_REQUEST` | 400 | Unknown action or property, or an invalid field, shape, or value |
| `AUTHENTICATION_REQUIRED` | 401 | No valid session or API key for the configured mode |
| `INVALID_CREDENTIALS` | 401 | Login failed |
| `AUTHORIZATION_DENIED` | 403 | The principal lacks the permission |
| `CSRF_VALIDATION_FAILED` | 403 | Missing or stale `X-CSRF-Token` on a session mutation |
| `CORS_ORIGIN_DENIED` | 403 | `Origin` is not an allowed exact origin |
| `NOT_FOUND` | 404 | Unknown route, or an action that is not served on this endpoint |
| `METHOD_NOT_ALLOWED` | 405 | Not `POST` (or `OPTIONS` on the public API) |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | Content type is not `application/json` |
| `REQUEST_TOO_LARGE` | 413 | Body exceeds `request.maxBodyBytes` |
| `RATE_LIMIT_EXCEEDED` | 429 | API rate limit exceeded; includes `Retry-After` |
| `LOGIN_RATE_LIMITED` | 429 | Login throttling |
| `SERVICE_UNAVAILABLE` | 503 | The API or SQL Parser application is disabled (production) |
| `AUTHENTICATION_UNAVAILABLE` | 503 | `api_key` mode with no key configured |

## Data action codes

| Code | HTTP | Meaning | Client handling |
|---|---:|---|---|
| `INVALID_SQL_RESOURCE` | 400 | SQL Resource ID is invalid, missing, excluded, ambiguous, or unsafe | Use a discovered resource ID |
| `INVALID_SQL_RUNTIME_FIELD` | 400 | Filter or sort field is not exposed by execution metadata | Remove it or update the report definition |
| `INVALID_SQL_RUNTIME_VALUE` | 400 | A mapped runtime value failed conversion (for example `integer-date`) | Correct the value |
| `INVALID_SQL_RUNTIME_FILTER` | 400 | Filter placement is ambiguous or unsafe | Change the filter logic or resource design |
| `INVALID_SQL_PAGINATION` | 400 | Pagination lacks an approved sort or conflicts with authored OFFSET/FETCH | Supply approved sorting or use the resource's fixed paging |
| `INVALID_ROUTINE` | 400 | Routine name is unsafe, does not exist, or is a different routine kind | Use an existing user routine of the right kind |
| `INVALID_ROUTINE_PARAMETERS` | 400 | Too many procedure arguments, or a function argument count that differs from its declaration | Send the declared parameters |
| `INVALID_WRITE_TABLE` | 400 | Write table is in a system schema or does not exist as a user table | Use an existing user table |
| `UNSAFE_WRITE` | 400 | UPDATE or DELETE without filters | Supply at least one filter |
| `INVALID_WRITE_COLUMN` | 400 | Column is not writable or filterable, or is generated | Remove or replace the field |
| `INVALID_WRITE_VALUE` | 400 | Value fails live type, range, format, null, or length rules | Correct the value |
| `MISSING_REQUIRED_FIELD` | 400 | Required INSERT/UPSERT column without a default is missing | Supply the field |
| `INVALID_UPSERT_KEY` | 400 | Keys are disabled, mismatched, missing, null, or lack an exact unique index | Send the configured key set |
| `DUPLICATE_KEY` | 409 | SQL Server duplicate-key error (2601/2627) | Refresh or choose another key |
| `CONSTRAINT_VIOLATION` | 409 | Recognized constraint, null, truncation, or reference failure | Correct input; do not blind-retry |
| `RESULT_TOO_LARGE` | 413 | Unpaginated read exceeded `GENERIC_MAX_RESULT_ROWS` | Paginate or narrow filters |
| `DATABASE_NOT_FOUND` | 404 | A requested `database` id is not configured | Use a configured database id |
| `DATABASE_DISABLED` | 403 | A requested database is disabled | Use an enabled database; operator action |
| `SERVER_PROFILE_DISABLED` | 403 | A requested database's server profile is disabled | Operator action |
| `SERVER_PROFILE_NOT_FOUND` | 503 | A database references a missing server profile | Operator action |
| `CROSS_SERVER_QUERY_NOT_SUPPORTED` | 400 | A request names databases on different server profiles | Query one server profile per request |
| `CROSS_DATABASE_EXECUTION_NOT_SUPPORTED` | 501 | A request names more than one database; cross-database execution is not available yet | Query one database per request |
| `DATABASE_UNAVAILABLE` | 503 | Database access is disabled or the database is unreachable | Retry later |
| `DATABASE_CONNECTION_TIMEOUT` | 504 | SQL Server did not complete the login within the configured login timeout | Retry later; operator checks reachability |
| `DATABASE_CONNECTION_FAILED` | 503 | SQL Server could not be reached, or TLS validation failed | Operator action |
| `DATABASE_AUTHENTICATION_FAILED` | 503 | SQL Server rejected the configured credentials | Operator action |
| `DATABASE_CONFIGURATION_ERROR` | 503 | Encrypted database configuration could not be read | Operator action |
| `QUERY_ERROR` | 500 | Undisclosed generation, metadata, connection, or execution failure | Show a generic failure; correlate by request ID |
| `QUERY_ERROR` | 504 | Statement or PHP execution timeout | Offer retry; investigate server-side |
| `INTERNAL_ERROR` | 500 | Unexpected exception or fatal error | Correlate by request ID |

SQL Parser syntax errors return safe stage, line, column, and corrective
messages without echoing submitted SQL; unexpected parser failures use
`PARSER_ERROR`.

## Failure handling

Each entry point registers the exception handler before loading application
dependencies. Production registration disables displayed PHP errors, enables
server-side logging, converts warnings and notices so they cannot corrupt JSON,
and installs a last-resort fatal shutdown handler that also converts a PHP
execution timeout into `504 QUERY_ERROR`. Entry points buffer output, and
controlled responses discard any accidental output before emitting JSON. A
recursion guard prevents an error during error handling from looping.

`ApiRequestException` carries expected status codes, messages, codes, and
details. Unexpected PHP, driver, and application exception messages never become
client messages, and raw ODBC diagnostics are categorized before a response is
built. Clients never receive stack traces, source paths, line numbers, SQL, ODBC
messages, connection strings, environment values, credentials, keys, session
IDs, CSRF tokens, cookies, or authorization headers. Details go to the redacted
logs described in [Logging](Logging.md).

## Hosting boundary

PHP can only handle failures after the entry point starts. Parse and startup
failures before handler registration, worker crashes, memory exhaustion, and
web-server or proxy errors are produced by IIS/FastCGI or Nginx/PHP-FPM. The
production templates keep detailed web-server errors local and pass application
JSON errors through unchanged; verify this on the target host.

## Validation and security boundaries

Validation is one layer of the security model. Query construction, SQL
Resource containment, identifier and catalog checks, and credential handling are
described in
[Security model](security/Security-Model.md#data-access-controls).
