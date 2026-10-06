# Production error handling

JSON error construction is centralized in `Response` and uncaught
failure handling in `ExceptionHandler`. It preserves intentionally
user-correctable validation messages while keeping internal diagnostics in
server logs. It does not add external monitoring or log aggregation.

## Canonical response

Controlled API, Admin API, and SQL Parser failures use the existing envelope
with a request identifier added to `meta`:

```json
{
  "success": false,
  "message": "A client-safe message.",
  "error": {
    "code": "INTERNAL_ERROR",
    "details": []
  },
  "data": [],
  "meta": {
    "requestId": "7f4dd403d84c99e1"
  }
}
```

The same request ID is used by response timing, security audit records, and
unhandled-error logs. Successful response fields are unchanged.

Production registration disables PHP diagnostic display, enables server-side
PHP logging, intercepts non-fatal warnings/notices/deprecations so they cannot
corrupt JSON, installs the exception handler, and installs a last-resort fatal
shutdown handler. API boundaries buffer output; controlled responses discard
accidental buffered output before emitting JSON.

## Status and category model

| Condition | Typical status | Safe code |
|---|---:|---|
| Invalid JSON/request validation | 400 or existing 422 | Existing validation code |
| Authentication required | 401 | `AUTHENTICATION_REQUIRED` |
| Authorization, CSRF, or CORS origin denied | 403 | Existing security code |
| Route/resource missing | 404 | `NOT_FOUND` or existing resource code |
| Method not allowed | 405 | `METHOD_NOT_ALLOWED` |
| Conflict | 409 | Existing conflict code |
| Body too large | 413 | `REQUEST_TOO_LARGE` |
| Unpaginated result over the row limit | 413 | `RESULT_TOO_LARGE` |
| Rate limited | 429 | `RATE_LIMIT_EXCEEDED` |
| Database availability gate/dependency unavailable | 503 | `DATABASE_UNAVAILABLE` |
| Database authentication failure | 503 | `DATABASE_AUTHENTICATION_FAILED` |
| Encrypted database configuration failure | 503 | `DATABASE_CONFIGURATION_ERROR` |
| Query/PHP execution timeout | 504 | Existing `QUERY_ERROR` |
| Parser validation | 400/422 | Existing parser/capability code |
| Unexpected exception or fatal | 500 | `INTERNAL_ERROR` |

`ApiRequestException` remains the authoritative carrier for expected public
status codes, messages, error codes, and validation details. Unexpected PHP,
database-driver, and application exception messages never become client
messages. Raw ODBC diagnostics are categorized before response generation.

SQL Parser syntax errors retain safe stage, line, column, and corrective
messages but no longer echo a fragment of submitted SQL. Unexpected parser
failures use `PARSER_ERROR` and are reported server-side.

## Logging and disclosure boundary

Unhandled failures create safe audit metadata plus a detailed diagnostic log.
Logging uses the existing redaction and fail-open behavior. If log storage or
the logger fails, the primary JSON response still completes. A recursion guard
prevents an error in error processing from repeatedly invoking the handler.

Clients are never intentionally given stack traces, source paths, PHP line
numbers, SQL, ODBC messages, connection strings, process commands,
environment values, passwords, API keys, encryption keys, session IDs, CSRF
tokens, cookies, or authorization headers. Error responses use
`application/json; charset=utf-8` and `Cache-Control: no-store` when headers can
still be controlled.

## Request boundaries and health

The normal API and Admin API register the handler before loading application
dependencies. The independent SQL Parser registers it for JSON parsing
requests. Unknown API and SQL Parser routes return canonical JSON 404 errors.
Disallowed browser origins are rejected with a safe 403 before request-body or
authentication processing. Existing successful CORS preflight behavior and
security headers remain unchanged.

Health semantics remain independent: liveness stays deliberately
small, readiness still returns 503 for failed required dependencies, and
detailed health remains System Administrator-only.

## Hosting limitations and validation

PHP can handle uncaught runtime exceptions and fatal shutdown errors only after
the entry point has begun executing. Parse/compile/startup failures that occur
before handler registration, worker crashes, memory exhaustion that prevents
response allocation, and output already transmitted outside buffering may be
handled by IIS/FastCGI, Nginx, or PHP-FPM instead. Production PHP configuration
and web-server custom-error settings must therefore suppress detailed platform
error pages and preserve application responses.

Production validation must cover IIS/FastCGI and Nginx/PHP-FPM 4xx/5xx
passthrough, body limits, request IDs in deployed logs, unwritable-log behavior,
PHP worker termination, real ODBC categories, proxy-generated errors, TLS
headers on error responses, and malformed requests at the actual web-server
boundary.

The current executed/static result and remaining operator checks are tracked in
[Windows and Linux production validation](Production-Validation.md).
