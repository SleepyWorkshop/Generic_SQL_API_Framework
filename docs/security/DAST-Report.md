# DAST Report

Review status: **Partially completed** — unauthenticated DAST and deployment-template review complete; authenticated dynamic testing deferred to the external penetration test — 2026-10-06

This document records the dynamic application security testing (DAST) step of
roadmap phase v2.1.4 (DAST and penetration-test preparation) of
[v2.1 — Security Verification & Operational Hardening](../Roadmap.md#v21--security-verification--operational-hardening).
It builds on the [Static security analysis inventory](Static-Security-Analysis-Inventory.md)
and the [Authorization & API security inventory](Authorization-API-Security-Inventory.md),
whose results are not reopened. Scope for the external penetration test is in
[Penetration-test preparation](Penetration-Test-Preparation.md). No password,
hash, API key, session identifier, token, or other secret is reproduced here.

## Summary

- **Resolved:** DAST-01 (SQL Parser PHP version disclosure) and DAST-02 (SQL
  Parser development router executed arbitrary PHP files).
- **Accepted:** DAST-03, DAST-04, and DAST-05 remain accepted Informational
  observations that affect development only.
- **Not performed:** authenticated, session, rate-limit, and injection dynamic
  testing was not performed in this phase (see
  [Section 5](#5-not-performed)).
- **Existing regression coverage:** the v2.1.3 and earlier automated regression
  suites exercise portions of those security controls (see
  [Section 6](#6-existing-regression-coverage-of-deferred-areas)). That
  coverage is not DAST and does not replace the deferred external DAST and
  penetration-test activity.
- **Handoff:** [Penetration-test preparation](Penetration-Test-Preparation.md)
  is the handoff for the external penetration test.

## 1. Scope and method

- **Targets:** the local development deployment only. The public API ran on
  `127.0.0.1:8000`, the SQL Parser on `127.0.0.1:8101`, and the Admin Console
  on `127.0.0.1:8090` (`GENERIC_ADMIN_ENABLED=1`), all on the PHP built-in
  server with their development routers.
- **Probes:** unauthenticated, read-only HTTP requests sent individually.
  These covered route discovery, path traversal and encoding variants,
  hidden-file and directory-listing probes, HTTP method handling, and
  response headers and cookies. Each probe was issued a small, bounded number
  of times.
- **Templates:** the production IIS and Nginx templates under `deployment/`
  and `deployment/php-production-security.ini` were reviewed statically to
  decide whether each development observation also applies in production.
- **Why the deployment was not tested with credentials:** the running
  development deployment uses the real `config/` directory, which holds real
  accounts and database settings. Authenticated, session, and state-changing
  testing was therefore not run against it (see
  [Section 5](#5-not-performed)).
- **Not used:** production systems, external or third-party hosts, scanners
  that crawl beyond the listed ports, load or denial-of-service traffic,
  credential guessing, destructive SQL, file uploads, and file modification or
  deletion.

## 2. Attack surface

| Surface | Entry points | Authentication | Production exposure |
|---|---|---|---|
| Public API | `POST /` (`api/index.php`); `GET /health/live`, `GET /health/ready` | Session, managed API key, or both, per `authentication.mode` | Public, behind IIS/Nginx with TLS |
| Admin Console | `GET /admin/`; `POST /admin/api.php`; three static assets | Session plus `admin.manage`; loopback and `GENERIC_ADMIN_ENABLED=1` | Loopback only |
| SQL Parser | `GET /` page; `POST /` parse; two static assets | None (non-executing, no data access) | Loopback or internal only |

The production templates route only these entry points and assets. All other
paths, including `app/`, `config/`, `core/`, `storage/`, `logs/`, dotfiles,
and `sqlparser/src/`, return 404 or are denied.

## 3. Results

| Area | Test | Result |
|---|---|---|
| Path traversal | `/../README.md`, `%2e%2e`, `..%2f`, `/assets/../../` on every surface | Not exploitable; requests never left the document root |
| Hidden files | `/.git/config`, `/.env` | 404 |
| Directory listing | Directory paths on every surface | 404; no listings |
| HTTP methods | `TRACE` on the public API; `TRACK` | 405; 501 from the built-in server |
| Public API headers | Responses carry nosniff, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP, and `Cache-Control: no-store` | Pass; no `X-Powered-By` |
| Admin headers | Same header set as the public API | Pass; no `X-Powered-By` |
| Session cookie | `HttpOnly; SameSite=Lax` | Pass; `Secure` is set in production or on direct HTTPS and absent on plain-HTTP development |
| SQL Parser headers | Page and responses | **DAST-01** (resolved) |
| SQL Parser routing | Requests for `src/*.php` and `router.php` | **DAST-02** (resolved) |
| Health endpoints | `/health/live`, `/health/ready`, `/health` | Minimal liveness/readiness bodies; see DAST-04 |
| Static assets | `Content-Type` and headers in development | See DAST-03 |

## 4. Findings

| ID | Severity | Title | Status |
|---|---|---|---|
| DAST-01 | Low | SQL Parser disclosed the PHP version in `X-Powered-By` | Resolved |
| DAST-02 | Low | SQL Parser development router executed any PHP file in its document root | Resolved |
| DAST-03 | Informational | Development static assets have no security headers | Accepted |
| DAST-04 | Informational | Development `/health` reports listener metadata | Accepted |
| DAST-05 | Informational | API and Admin development routers pass existing files to the built-in server | Accepted |

No Critical, High, or Medium findings were identified in the tested scope.

### DAST-01 — SQL Parser disclosed the PHP version (Low)

**Observation:** the SQL Parser page (`GET /`), parse responses, and the
router's 404 responses sent `X-Powered-By: PHP/<version>`. The public API,
Admin Console, and SQL Parser `/health` already removed that header.

**Production impact:** `deployment/php-production-security.ini` and the
bundled Windows runtime set `expose_php = Off`. However, the bundled Linux
runtime `php.ini`, which `start-linux.sh` loads, set `expose_php = On`, so the
version was also exposed in that hosting mode.

**Remediation:** `sqlparser/index.php` and `sqlparser/router.php` now call
`header_remove('X-Powered-By')` for every response, and the bundled Linux
`php.ini` sets `expose_php = Off`. The development SQL Parser page also now
sends `X-Frame-Options: DENY` and `Referrer-Policy: no-referrer`, matching the
public API and Admin development headers. In production, these headers come
from IIS or Nginx.

### DAST-02 — SQL Parser development router executed any PHP file (Low)

**Observation:** `sqlparser/router.php` returned `false` for any existing
file, so the built-in server executed `src/*.php` and `router.php` directly.
Each returned an empty `200` with no side effects, because the files only
declare classes.

**Production impact:** none. The IIS template allowlists `index.php` and the
two assets, hides `src`, and returns 404 for every other path. The Nginx
template routes only `/`, `/index.php`, and the two assets.

**Remediation:** the development router now serves only
`/assets/css/app.css` and `/assets/js/app.js`, matching the production
allowlist. Every other path receives the JSON `404 NOT_FOUND`.

### DAST-03 — Development static assets have no security headers (Informational)

In development, assets such as `/assets/js/app.js` are served by the PHP
built-in server without nosniff or other security headers. In production,
these headers are owned by IIS or Nginx (see the `security-headers.*` and
`*.web.config.example` templates, verified by `HttpsSecurityTest`). This is
accepted because it affects development only.

### DAST-04 — Development `/health` reports listener metadata (Informational)

The development `/health` route returns service, version, and port metadata
used by the development process manager. Production exposes only
`/health/live` and `/health/ready` (see the Nginx template), and those return
minimal bodies. This is accepted because it affects development only.

### DAST-05 — API and Admin development routers pass existing files through (Informational)

`api/router.php` and `admin/router.php` hand any existing file in their
document roots to the built-in server. Those document roots contain only
their entry points, the routers, and Admin static assets, so no additional
code is reachable. Production templates allowlist the entry points. This is
accepted because it affects development only.

## 5. Not performed

The following dynamic tests were not run in this phase. They are carried
into the external penetration-test scope in
[Penetration-test preparation](Penetration-Test-Preparation.md):

- authenticated session testing, including fixation, rotation, idle and
  absolute timeouts, logout, and revocation after authorization changes, over
  HTTPS;
- live rate-limit and login-lockout behavior using a dedicated test account;
- authenticated injection testing of query, write, routine, and SQL Resource
  actions against a SQL Server test database;
- dynamic SQL Parser input testing beyond the regression suite;
- TLS configuration of a production-like IIS or Nginx deployment;
- the Admin loopback boundary behind a production reverse proxy.

The authorization, CSRF, API-key, and request-validation behavior over HTTP
is already covered by the v2.1.3 suites against isolated, temporary
deployments (see the
[Authorization & API security inventory](Authorization-API-Security-Inventory.md#coverage-verification)).

## 6. Existing regression coverage of deferred areas

This section maps the existing automated regression suites to the deferred
areas. It is **not** DAST: no new dynamic testing was performed to produce it.
It was compiled by reading the test code. The suites run against isolated,
temporary state with synthetic accounts, and the database is left unavailable,
so any request that reaches the database ends at `503 DATABASE_UNAVAILABLE`.
"Over HTTP" means the suite sends real requests to the PHP built-in server.
"In-process" means it calls the application classes directly.

Classification:

- **Covered:** the control is asserted by existing regression tests, including
  over HTTP.
- **Partially covered:** some aspects are asserted, but others are untested,
  untested over HTTP, or depend on infrastructure the suites do not run.
- **Not covered:** deferred to the external penetration test.

| # | Area | Classification | Not covered by the suites (external pentest scope) |
|---|---|---|---|
| 1 | Authentication | Partially covered | Unicode, long, and duplicate-field credentials; disabled- and deleted-account login over HTTP; production hosting |
| 2 | Session management | Partially covered | Secure cookie over real TLS; session fixation and timeouts observed over HTTP |
| 3 | Authorization | Covered | Behavior with a live SQL Server database |
| 4 | CSRF | Covered | Browser-based cross-site requests |
| 5 | API-key security | Covered | Behavior behind a production reverse proxy |
| 6 | Rate limiting | Partially covered | Per-client isolation over HTTP; multi-worker or multi-host deployments |
| 7 | Input validation / injection | Partially covered | Injection against a live SQL Server database; HTML or script content round-tripped through stored data |
| 8 | Error and information disclosure | Partially covered | Production-mode responses through IIS or Nginx and FastCGI; real database errors |
| 9 | SQL Parser | Partially covered | SQL comments; non-JSON content types; broader malformed-input testing |
| 10 | Admin API | Covered | Loopback boundary behind a production reverse proxy |
| 11 | Path / file access | Partially covered | Public API and Admin routing over HTTP; IIS and Nginx routing in a deployed environment |
| 12 | Security headers / transport | Partially covered | Live TLS configuration; headers emitted by a deployed IIS or Nginx; `TRACE` in production |

### Supporting tests

**1. Authentication** — Partially covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - valid login and wrong-password `401 INVALID_CREDENTIALS`;
  - login CSRF, and an unknown login property rejected with
    `INVALID_AUTH_REQUEST`;
  - malformed JSON, empty and NUL bodies, scalar bodies, and wrong content
    types refused before dispatch on both APIs.
- **In-process** (`tests/SecurityTestingTest.php`):
  - empty username, CR/LF username, empty password, and a client-supplied role
    are rejected by validation;
  - missing and disabled accounts receive identical generic failures;
  - a deleted account's session is invalidated.
- **In-process** (`tests/AuthenticationFlowTest.php`):
  - a rejected login creates no session, and login regenerates the session
    identifier;
  - usernames are normalized, passwords are not trimmed, and logout destroys
    the session.
- **In-process** (`tests/FirstTimeSetupTest.php`): passwords are stored hashed
  only.

**2. Session management** — Partially covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - a logged-out cookie cannot be replayed;
  - existing sessions are revoked after a frontend-access change, profile
    rename, disable and re-enable, or role change;
  - non-POST and OPTIONS requests never touch the session.
- **In-process** (`tests/SecurityHardeningTest.php`, `tests/SecurityTestingTest.php`):
  - login regenerates the session identifier, and strict mode rejects the old
    and destroyed identifiers;
  - URL session identifiers are refused;
  - the cookie is session-only, `HttpOnly`, `SameSite=Lax`, host-only, has
    path `/`, and is `Secure` in production;
  - idle and absolute timeouts are enforced;
  - logout deletes server-side state;
  - password changes and deletion invalidate sessions;
  - concurrent same-session requests serialize.
- **In-process** (`tests/HttpsSecurityTest.php`): cookies are `Secure` in
  production and on direct HTTPS, and not because of a forwarded protocol
  header.

**3. Authorization** — Covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - anonymous callers are refused on every data action;
  - read-only, data-operator, and frontend-only sessions are refused on every
    frontend-user action;
  - an Application Administrator cannot create privileged users, promote
    accounts, change its own lifecycle, or reach System Administrators through
    username case variants;
  - client-supplied identity fields are ignored;
  - SQL Resource and write-resource scopes are enforced.
- **Over HTTP** (`tests/AuthorizationBoundaryTest.php`): Application
  Administrators cannot manage backend-only accounts (AAPI-01), and System
  Administrator accounts cannot be managed through the public API (AAPI-02).
- **Over HTTP** (`tests/ApiSecurityHardeningTest.php`): backend identity
  profiles are minimized in frontend lists, and refused probes create no
  accounts.
- **In-process** (`tests/SecurityTestingTest.php`,
  `tests/FrontendUserMutationAuthorizationTest.php`): the role and permission
  matrix and resource scopes are enforced.

**4. CSRF** — Covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - all 13 protected public actions and all 36 protected Admin actions refuse
    missing, invalid, and foreign-session tokens, with no persisted state
    change;
  - the token rotates at login, and the pre-login token is rejected afterwards;
  - API-key writes are CSRF-exempt.
- **In-process** (`tests/ApiSecurityHardeningTest.php`): registered write
  routines require CSRF (AAPI-03).
- **In-process** (`tests/SecurityHardeningTest.php`,
  `tests/SecurityTestingTest.php`): the token rotates on login and is
  invalidated on logout.

**5. API-key security** — Covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - malformed, unknown, wrong-secret, and empty keys are rejected;
  - disabled and revoked keys, including a revoked key left enabled in
    storage, and keys whose owner is disabled are rejected;
  - a revoked key cannot be re-enabled;
  - keys cannot be created with privileged or mixed roles;
  - keys are confined to their resource scopes;
  - keys never reach Admin or frontend-user management;
  - API-key mode fails closed without keys;
  - legacy shared-key behavior;
  - `session+api_key` precedence.
- **In-process** (`tests/SecurityTestingTest.php`,
  `tests/UnifiedAdminConsoleTest.php`): the raw key is shown once and never
  stored or listed, and client fields cannot raise a key's role.

**6. Rate limiting** — Partially covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - the login lockout triggers after the configured failures, and username
    case variants do not bypass it;
  - the API rate limit applies to sessions and to anonymous callers
    (`429 RATE_LIMIT_EXCEEDED`).
- **In-process** (`tests/SecurityTestingTest.php`):
  - lockout feedback contains only the server-side retry duration;
  - invalid API keys are throttled, and changing the user agent or request ID
    does not reset the count.
- The HTTP suite gives each run its own client address so that stored counters
  do not affect other runs, but it does not assert per-client isolation.

**7. Input validation / injection** — Partially covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - non-string actions (`[]`, number, `null`, boolean, object) return
    `400 INVALID_REQUEST`;
  - padded and case-changed actions are not normalized;
  - unknown properties are rejected by every validator;
  - method, content-type, and body-size limits are enforced;
  - no request in the suite logs a PHP warning or notice.
- **In-process** (`tests/SecurityTestingTest.php`):
  - filter and write values are bound as prepared parameters, including a
    quote and `UNION` payload;
  - table, sort, direction, column, filter-expression, and resource
    identifiers containing SQL metacharacters are rejected;
  - SQL Resources reject `INTO`, multiple statements, and `DELETE`;
  - write resources and connection-string fields reject injection.
- **In-process** (`tests/StaticSecurityRemediationTest.php`): inlined
  literals use quote doubling, including Unicode quote lookalikes and NUL.

**8. Error and information disclosure** — Partially covered

- **In-process** (`tests/ProductionErrorHandlingTest.php`,
  `tests/SecurityTestingTest.php`):
  - unexpected, database, credential, and timeout errors map to safe codes
    without paths, SQL, ODBC diagnostics, environment variable names,
    credentials, or stack traces;
  - accidental buffered output cannot corrupt error responses;
  - logs redact passwords, keys, session identifiers, CSRF tokens, bearer
    tokens, and cookies.
- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`): no request in the
  suite logs a PHP warning, notice, or deprecation.
- **In-process** (`tests/UnifiedAdminConsoleTest.php`): Admin status and
  configuration responses do not return the database password, encryption
  key, or API key secret.

**9. SQL Parser** — Partially covered

- **In-process** (`tests/SqlParserGeneratorTest.php`):
  - the handler returns `INVALID_JSON`, 405, and 413 as expected;
  - parse errors report stage, line, and column without the SQL fragment;
  - multiple statements and unsupported features are rejected;
  - no database execution classes are loaded.
- **Over HTTP** (`tests/DastRegressionTest.php`):
  - page rendering, a valid parse, and a malformed-JSON error;
  - no `X-Powered-By` header;
  - development security headers;
  - only the two assets are served, and `router.php`, `src/*.php`, traversal
    variants, directories, and dotfiles return 404.
- **In-process** (`tests/SqlParserAssetUrlTest.php`): asset URLs reject
  traversal and script injection through `SCRIPT_NAME`.

**10. Admin API** — Covered

- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - every gated action returns 404 with `GENERIC_ADMIN_ENABLED` set to `0` or
    unset;
  - a non-loopback client is refused even with a valid System Administrator
    session and CSRF token, and with `X-Forwarded-For`, `X-Real-IP`,
    `Forwarded`, or `Client-IP` claiming loopback;
  - requests without a session are refused;
  - read-only, data-operator, and Application Administrator sessions are
    refused;
  - unknown actions are refused, and CSRF is enforced;
  - the last enabled System Administrator is protected;
  - backup identifiers are validated, and the body limit is enforced;
  - authorized actions succeed.
- **Over HTTP** (`tests/AuthorizationBoundaryTest.php`,
  `tests/StaticSecurityRemediationTest.php`): Admin identity management works
  only through the enabled loopback Admin API, and setup runs once.

**11. Path / file access** — Partially covered

- **Over HTTP** (`tests/DastRegressionTest.php`): SQL Parser traversal,
  encoded traversal, source, router, directory, and dotfile requests return 404.
- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`): Admin backup
  identifiers with traversal or a `.php` name are refused, and nothing is
  written outside the backup directory.
- **In-process** (`tests/SecurityTestingTest.php`):
  - SQL Resource IDs reject `../`, `..\`, absolute, Windows, encoded,
    NUL-byte, and URL forms;
  - write-resource IDs reject traversal;
  - backups cannot be written outside their directory.
- Production IIS and Nginx routing was reviewed manually (see
  [Section 3](#3-results)). The suites do not check it.

**12. Security headers / transport** — Partially covered

- **Template checks** (`tests/HttpsSecurityTest.php`):
  - the Nginx and IIS templates define HSTS (without `preload` or
    `includeSubDomains`), nosniff, `Referrer-Policy`, CSP with
    `frame-ancestors 'none'`, `X-Frame-Options`, and `Permissions-Policy`;
  - TLS 1.2 and 1.3, and the HTTP redirect behavior;
  - applications do not emit HSTS;
  - no key material is present.
- **Over HTTP** (`tests/AuthorizationApiCoverageTest.php`):
  - CORS refuses disallowed origins on POST and on preflight, reflects only
    allowed origins, and sends no CORS headers without an `Origin`;
  - public OPTIONS returns 204, Admin OPTIONS returns 405, and other methods
    return 405.
- **Over HTTP** (`tests/DastRegressionTest.php`): SQL Parser development
  headers.
- These suites do not assert TLS behavior or the headers sent by a deployed
  IIS or Nginx server.

## 7. DAST regression tests

`tests/DastRegressionTest.php` runs the SQL Parser on the PHP built-in server
with `expose_php` forced on, both through the development router and with
`index.php` called directly as IIS and Nginx call it. It uses an isolated,
temporary runtime configuration and log directory. It asserts that:

- the SQL Parser page, parse responses, error responses, `/health`, and 404
  responses send no `X-Powered-By`;
- the development page sends nosniff, `X-Frame-Options`, `Referrer-Policy`,
  and its CSP;
- the two public assets are served, while `router.php`, `src/*.php`,
  traversal and encoded variants, directories, and dotfiles return `404`;
- the bundled Linux `php.ini` disables `expose_php`.

Mutation checks were run against the suite. Each check reverted one
safeguard, ran the suite, then restored the file and verified its checksum.

| Mutation | Result |
|---|---|
| `header_remove` removed from `sqlparser/index.php` | Caught |
| `header_remove` removed from `sqlparser/router.php` | Caught |
| Router passes any existing file through again | Caught |
| Development `X-Frame-Options` removed | Caught |
| Bundled Linux `expose_php = On` restored | Caught |

### Test harness correction

While verifying this phase, `tests/AuthorizationApiCoverageTest.php` (v2.1.3)
was found to pass only when a system `php.ini` was loaded. Under the
documented `php -n tests/run.php`, PHP's defaults applied to its test servers:

- `display_errors` was on, so the oversized Admin request's startup warning
  sent headers early. The response was still refused (`REQUEST_TOO_LARGE`),
  but returned `200` instead of `413`.
- Probabilistic session garbage collection intermittently logged notices for
  the unreadable system session directory.

The test servers now run with `display_errors=0` and
`session.gc_probability=0`, as the production `php.ini` and the host
configuration do. No application code or assertion changed, and v2.1.3 results
are unaffected.

## 8. Files changed

- `sqlparser/index.php`, `sqlparser/router.php` (DAST-01, DAST-02)
- `runtime/linux/php/php.ini` (DAST-01)
- `tests/DastRegressionTest.php`, `tests/run.php`
- `tests/AuthorizationApiCoverageTest.php` (test-server settings only)
- `docs/security/DAST-Report.md` (this report; Section 6 adds the existing regression coverage map)
