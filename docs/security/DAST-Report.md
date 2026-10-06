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

## 6. Regression coverage

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

## 7. Files changed

- `sqlparser/index.php`, `sqlparser/router.php` (DAST-01, DAST-02)
- `runtime/linux/php/php.ini` (DAST-01)
- `tests/DastRegressionTest.php`, `tests/run.php`
- `tests/AuthorizationApiCoverageTest.php` (test-server settings only)
