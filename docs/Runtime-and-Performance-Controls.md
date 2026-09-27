# Runtime and performance controls

Operational controls are stored under `runtime` in `config/admin.json` schema version 6. A System Administrator can edit them in **Configuration → Runtime & Performance**. The backend validates the complete runtime object before the existing atomic JSON writer replaces the file. A failed validation or write leaves the previous configuration intact.

Schema versions 1–5 migrate in place to version 6. Existing server, CORS, and authentication values are preserved and missing runtime/backup sections receive validated defaults. Runtime settings are loaded for new requests, so no API, SQL Parser, or Admin restart is required.

## Implemented settings

| Setting | Default | Valid range | Enforcement |
|---|---:|---:|---|
| `query.timeoutSeconds` | 45 | 1–300 seconds | Applied to each ODBC statement through `SQL_QUERY_TIMEOUT` |
| `rateLimit.api.enabled` | `true` | boolean | Enables the general API limiter |
| `rateLimit.api.requests` | 600 | 1–10,000 | Maximum requests per identity and window |
| `rateLimit.api.windowSeconds` | 60 | 1–86,400 seconds | Fixed rate-limit window |
| `rateLimit.login.enabled` | `true` | boolean | Enables failed-login throttling |
| `rateLimit.login.maximumAttempts` | 5 | 2–100 | Failures before temporary lockout |
| `rateLimit.login.windowSeconds` | 900 | 60–86,400 seconds | Failed-attempt window |
| `rateLimit.login.lockoutSeconds` | 300 | 30–86,400 seconds | Temporary lockout duration |
| `session.idleTimeoutSeconds` | 1,800 | 60–2,592,000 seconds | Maximum inactivity; must not exceed absolute lifetime |
| `session.absoluteTimeoutSeconds` | 28,800 | 300–2,592,000 seconds | Maximum session lifetime |
| `request.maxBodyBytes` | 1,048,576 | 1,024–104,857,600 bytes | Checked before and while reading API/Admin JSON bodies |
| `request.defaultPageSize` | 25 | 1–10,000 rows | Used when a pagination object contains `page` but omits `pageSize` |
| `request.maxPageSize` | 1,000 | 1–10,000 rows | Larger page sizes are rejected; values are not clamped |

Omitting pagination still means an unpaginated request, preserving the existing public contract. When pagination is requested, `page` remains mandatory. The default page size must not exceed the maximum.

`DB_QUERY_TIMEOUT_SECONDS`, `GENERIC_SESSION_IDLE_TIMEOUT`, `GENERIC_SESSION_ABSOLUTE_TIMEOUT`, `GENERIC_LOGIN_MAX_ATTEMPTS`, `GENERIC_LOGIN_WINDOW_SECONDS`, and `GENERIC_LOGIN_LOCKOUT_SECONDS` remain supported as validated deployment overrides. Invalid or out-of-range overrides are ignored in favor of the stored validated value. Related session overrides that would make idle timeout exceed absolute lifetime are ignored together.

## Rate limiting and errors

The API limiter distinguishes authenticated sessions, supplied API keys after successful authentication, and anonymous source addresses. It does not trust forwarded identity headers. State is stored as opaque hashes in local files with per-identity locks and atomic replacement. Exceeding the limit returns HTTP 429 with `RATE_LIMIT_EXCEEDED`, an empty details list, and a `Retry-After` header. Authorization is still evaluated independently.

Login protection continues to key failures by source address plus normalized username, uses the same response for known and unknown accounts, clears state after successful authentication, and rejects disabled accounts. Exceeding the configured login threshold returns HTTP 429 with `LOGIN_RATE_LIMITED`.

The local file-backed implementation is suitable for the supported single-host runtime. It is not a distributed/global limiter across multiple application hosts or containers. Centralized rate-limit infrastructure belongs to a later deployment phase.

## Query timeout limitations

Drivers supporting the ODBC statement timeout enforce the configured value. If the driver returns the standard unsupported-option result, the engine logs a sanitized capability event and continues without claiming the statement timeout was enforced. The existing safe HTTP 504 `QUERY_ERROR` conversion, correlation IDs, SQL redaction, and parameter metadata logging are unchanged. PHP, proxy, browser, and load-balancer timeouts are separate.

Every request owns its non-persistent ODBC connection and statements. Statement handles are freed in `finally` on success, driver failure, and recognized timeout, and a failed execution cannot reuse another request's connection. PHP's fatal execution-time shutdown handler preserves the controlled 504 response, but PHP cannot guarantee driver cancellation while blocked inside `odbc_execute`; verify SQL Server-side cancellation and connection cleanup with the deployed driver.

Local file-backed API and login counters use per-identity exclusive locks and atomic complete-record replacement. Concurrent increments are preserved, login reset takes the same lock, and malformed records recover as a new empty window. This guarantee applies only to workers sharing one reliable local filesystem, not to multi-host deployments.

## Audit decisions

| Value | Previous location/default | Current behavior |
|---|---|---|
| SQL query timeout | `config/performance.php`, 45 seconds | Configurable and enforced per statement |
| Login threshold/window/lockout | `SecurityConfiguration`, 5 / 900 / 300 | Configurable; existing limiter retained |
| Session idle/absolute lifetime | `SecurityConfiguration`, 1,800 / 28,800 | Configurable; fixation and invalidation rules unchanged |
| Page size | Positive integer only | Default and maximum configurable when pagination is requested |
| JSON body size | No API/Admin application limit | Configurable at both front controllers |
| General API rate limit | Not implemented | Configurable local file-backed limiter added |
| SQL parser body limit | Parser-specific 200,000-byte protocol boundary | Kept parser-owned; not exposed as an API runtime control |
| Expression recursion depth | Validator safety bound of 32 | Kept in code as a parser/security invariant |
| CSRF retry count | One frontend compatibility retry | Kept in client code; not a server runtime threshold |
| Frontend cache TTL/refresh intervals | Frontend/report definitions | Kept frontend-owned; not backend operational controls |
| API-key count | No artificial limit | No limit introduced |
| Connections/concurrent requests | Hosting process model | Local process contention is regression-tested; production IIS/FastCGI/Nginx/PHP-FPM capacity still requires load testing |
| Cryptography, token entropy, identifiers, authorization | Security code and fixed role model | Never configurable |

The bundled PHP development server has no production-grade worker or connection controls. Windows ODBC and Linux unixODBC behavior remains driver-dependent; no cursor-library or platform-specific connection change is introduced by these controls.
