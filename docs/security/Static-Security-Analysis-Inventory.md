# Static security analysis inventory (v2.1.2)

Review status: **Completed** — 2026-10-06

This report records roadmap phase v2.1.2 (Static Security Analysis) of
[v2.1 — Security Verification & Operational Hardening](../Roadmap.md#v21--security-verification--operational-hardening).
The original read-only inventory is preserved below the
[remediation status](#remediation-status); findings whose analysis changed
after deeper tracing carry a **Correction** note. Phase v2.1.2 is complete:
every finding in the approved remediation scope (SSA-01 – SSA-12) is fixed,
documented, or accepted, and the SSA-05 operator action (credential rotation)
was completed and verified on 2026-10-06. SSA-13 – SSA-20 remain deferred.

## Method and scope

- Manual source review of the backend PHP application (`api/`, `admin/`,
  `sqlparser/`, `app/`, `core/`, `database/`, `config/`, `scripts/`), the
  Admin Console and SQL Parser JavaScript, deployment templates, the CI
  workflow, and repository history for committed secrets.
- Each dangerous primitive found by search (process execution, dynamic
  include/class loading, string-built SQL, file paths, deserialization, DOM
  `innerHTML`) was traced from its input source to the sink. Occurrences whose
  inputs are fixed, validated, or server-controlled are recorded as controls,
  not findings.
- No external static analyzer, SAST product, or live SQL Server was used.
  Database-dependent exploitability (marked as such) was reasoned from code and
  SQL Server behavior, not demonstrated.
- No secret, key, password, or hash value is reproduced in this document.

Severity reflects impact in a supported deployment if exploited; confidence
reflects how certain the control path is from code.

## Architecture and security boundaries

| Boundary | Entry point | Who can reach it | Gate in code |
| --- | --- | --- | --- |
| Public API | `api/index.php` (POST, `application/json` only) | Network clients, browsers via CORS | Runtime availability → authentication → API rate limit → admin/frontend-user authorization → CSRF → action authorization → database availability → request validation |
| Health | `api/health.php` (`/health`, `/health/live`, `/health/ready`) | Unauthenticated | GET/HEAD only; fixed payloads |
| Admin Console UI | `admin/index.php` | Loopback only | `REMOTE_ADDR` check; web-server loopback binding/ACL in templates |
| Admin API | `admin/api.php` (POST, JSON, up to 30 MB) | Loopback only (web server) | Action allowlist → `LocalAdminMiddleware` (`admin.*` needs `GENERIC_ADMIN_ENABLED=1` and loopback; after remediation every non-session action does) → session → `admin.manage` → CSRF |
| SQL Parser | `sqlparser/index.php` (GET page, POST parse) | Unauthenticated | Runtime availability; 200 kB body cap; never executes SQL |
| Database | `core/QueryEngine.php` → ODBC → SQL Server | Application only | Encrypted connection configuration; request-scoped connection |
| Filesystem state | `config/*.json`, `database/config/database.json`, `runtime/`, `logs/`, `backups/` | Application only | Outside web roots; atomic 0600/0700 writes; templates deny sensitive extensions |
| Development process manager | `app/Runtime/ApiProcessManager.php` | Admin (development only) | argv array `proc_open`, loopback-only bind address |

Principals: session users (backend role + optional frontend role), managed
`gsk_` API keys (one backend role), the legacy shared key
(`GENERIC_SQL_API_KEY`), and the public principal in authentication mode `none`.
Permissions come from `config/authorization.json`; defaults are in
`app/Configuration/RuntimeConfiguration.php:72-86`.

Request data flow for data actions: JSON body → `QueryRequestValidator` /
`SqlRequestValidator` / `WriteRequestValidator` (allowlisted shapes,
identifier regexes) → `QueryRequestNormalizer` (fixed controller/action) →
builders (identifiers bracketed or regex-constrained, values as `?`
parameters) → `odbc_prepare`/`odbc_execute`.

## Summary by severity

Severities after correction (original inventory severities in brackets where
changed):

| Severity | Count | IDs |
| --- | --- | --- |
| Critical | 0 | — |
| High | 3 | SSA-01, SSA-02, SSA-05 [Medium] |
| Medium | 2 | SSA-03, SSA-04 |
| Low | 6 | SSA-06, SSA-07, SSA-08, SSA-09, SSA-11, SSA-12 |
| Informational | 9 | SSA-10 [Low], SSA-13 – SSA-20 |

Confirmed (control path proven from code): SSA-01 – SSA-07, SSA-09, SSA-11 –
SSA-20. Deployment-dependent: SSA-08. No exploitable path: SSA-10.

## Remediation status

| ID | Severity | Status | Remediation |
| --- | --- | --- | --- |
| SSA-01 | High | **Fixed** | Deny-by-default `config/routine-resources.php` + `RoutineResourceRegistry`. Entries fix type, schema, name, access, exact parameter count, and allowed roles. SQL is built from the registry (`EXEC [schema].[name] ?`), never from the request. Requires `routine.execute`, a listed role, and `data.write` for write routines; `frontend.read` no longer authorizes routines. Shipped registry is empty. |
| SSA-02 | High | **Fixed** | `setup.createAdmin` removed from the public API (404). It runs only through the Admin API, where `LocalAdminMiddleware` requires loopback and `GENERIC_ADMIN_ENABLED=1`. Setup stays permanently unavailable after initialization; `setup.status` stays public. |
| SSA-03 | Medium | **Implemented for the current enforcement boundary; frontend sources reconciled** | Deny-by-default `config/query-sources.php` + `QuerySourcePolicy`, enforced for every physical table/view of JSON Query Mode (source, join, subquery, union branch, CTE body) in `ScopedMetadataRepository`, for metadata listings, and for SQL Resource runtime source filters; CTE names stay local and must be plain identifiers. Authored SQL Resource SQL is not validated table by table. See [SSA-03 frontend source reconciliation](#ssa-03-frontend-source-reconciliation). |
| SSA-04 | Medium | **Fixed** | `auth.users.*`, `auth.apiKeys.*`, and `auth.roles.list` removed from the public API (404) and served only by the loopback Admin API with the Admin flag. `auth.frontendUsers.*` is unchanged. |
| SSA-05 | High | **Cleared — credential rotated** | The affected System Administrator password was rotated through the Admin Console on 2026-10-06 and login with the new password was confirmed by the operator. A `hash_equals` comparison of the current stored hash for the same account against the historical hash returned no match; no other stored record matches it either. The historical hash remains in public Git history but no longer corresponds to the account's credential. Git history is not rewritten. See [SSA-05](#ssa-05--an-administrator-password-hash-is-present-in-pushed-git-history-medium). |
| SSA-06 | Low | **Accepted limitation** | Documented in `docs/Limitations.md`; a regression test pins the current behavior (frontend access is not narrowed by SQL Resource scopes, backend roles are). |
| SSA-07 | Low | **Fixed** | SQL Resource runtime filters resolve only against registered query sources; explicit `source`/`having` mappings must reference a registered top-level source (derived-table aliases are denied). |
| SSA-08 | Low | **Documented** | `docs/Production-Security-and-Deployment.md` forbids same-host proxies in front of the loopback/`REMOTE_ADDR` gates. No proxy-header trust was added. |
| SSA-09 | Low | **Fixed** | Production `driver: auto` uses only ODBC Driver 18/17; a TLS or certificate failure stops driver fallback in every environment. Production System Health and production validation report `encrypt_disabled`, `trust_server_certificate_enabled`, and `legacy_driver_configured`. |
| SSA-10 | Informational | **No change (tests added)** | Quote doubling kept; regression tests cover embedded quotes, NUL bytes, and Unicode quote look-alikes. Public CASE values were confirmed to be bound parameters. |
| SSA-11 | Low | **Fixed** | `GENERIC_MAX_RESULT_ROWS` (default 10,000) caps unpaginated data reads; exceeding it returns `413 RESULT_TOO_LARGE` without truncation. Metadata, write OUTPUT, and paginated reads are not capped. |
| SSA-12 | Low | **Documented** | Provenance, versions, and SHA-256 of all 80 tracked files recorded in the [dependency review](Dependency-Security-Review.md#addendum-bundled-windows-php-runtime-v212-ssa-12) and [Windows-PHP-Runtime.sha256](Windows-PHP-Runtime.sha256). No binaries removed. |
| SSA-13 – SSA-20 | Informational | **Deferred** | Not in the approved remediation scope for this step. |

### SSA-03 frontend source reconciliation

A read-only frontend query-source inventory
(`Frontend/Generic-Reporting-Framework/docs/security/Frontend-Query-Source-Inventory.md`)
was completed on 2026-10-06 against the local frontend `dev` branch at
`859aeed`. That branch had no upstream tracking branch and nothing was fetched,
so the inventory is not verified against the current remote frontend `dev`
branch.

Enforcement boundary (unchanged): the registry applies to JSON Query Mode
sources, metadata listings, and dashboard/runtime filters resolved against SQL
Resources. The SQL inside authored widget SQL Resource files is server-owned and
is not validated table by table; no SQL Resource parsing was added.

| Source | Origin | Registry |
| --- | --- | --- |
| `CustomerTable` | Read directly by the frontend (Customer Report) | Registered |
| `ItemMasterTable` | Read directly by the frontend (Item Report, filter options) and by item widget SQL | Registered |
| `BillDetTable` | Existing widget SQL (Bill/Sales dashboards) | Registered |
| `BillMastTable` | Existing widget SQL (written `billmasttable` in one file; matched case-insensitively) | Registered |
| `PurMastTable` | Existing widget SQL (Bill dashboard) | Registered |
| `CategoryTable` | Existing widget SQL, joined to `BillDetTable` in 3 widget queries (including the `SalesData` CTE body) | **Added** in this follow-up |

The inventory found no frontend-built joins, subqueries, unions, CTEs, routine
calls, metadata usage, or dynamically selected database sources. `SalesData`
and `RankedData` are CTE aliases, not physical tables.

Pre-existing unresolved resources: the frontend references 26 widget SQL
Resources, of which 9 exist in `queries/widgets` and **17 are missing**. Their
physical tables cannot be determined from the frontend repository and were not
guessed or registered; those widgets already fail with `INVALID_SQL_RESOURCE`
independently of SSA-03. When their SQL is added, any tables used by runtime
source filters must be registered.

Regression coverage: `tests/StaticSecurityRemediationTest.php` (public API and
Admin boundary over HTTP, routine registry and authorization, query-source
policy across source/join/subquery/union/CTE, metadata filtering, SQL Resource
source filters, row limit, production driver selection and transport
warnings, literal edge cases, and the SSA-06 accepted limitation).

## Findings

### SSA-01 — Routine actions execute any stored procedure or function (High)

- **File:** `app/Repositories/Query/RoutineBuilder.php:5-47`;
  `app/Requests/QueryRequestValidator.php:79-85, 972-976`;
  `app/Middleware/AuthorizationMiddleware.php:19`;
  `app/Configuration/RuntimeConfiguration.php:79`
- **Code:** `"EXEC {$request['procedure']}"`, `"SELECT {$request['function']}("`,
  `"SELECT * FROM {$request['function']}("`
- **Control path:** `procedure` / `function` / `tableFunction` action →
  `validateSourceName` (regex `^[A-Za-z_][A-Za-z0-9_.]*$`) → interpolated
  routine name with bound `?` arguments. Authorization is
  `routine.execute` **or** `frontend.read`, with no resource scope.
- **Why it matters:** The name cannot inject SQL syntax, but any routine the
  database login can execute is reachable, including system procedures
  (for example `sp_rename`, `sp_executesql`, `xp_cmdshell` where enabled) and
  three-part cross-database names. The default **Read Only** role, every
  frontend user, and the public principal in authentication mode `none` hold
  one of these permissions. A "read" principal can therefore perform writes,
  DDL, or (through `sp_executesql` with a bound statement argument) arbitrary
  SQL, bypassing the write-resource registry and the read/write role model.
- **Existing mitigation:** Identifier regex; parameterized arguments;
  documented in `docs/Limitations.md` and `docs/Metadata-and-Routines.md`;
  reliance on least-privilege SQL Server credentials.
- **Exploitable:** Yes for any authenticated reader (or anyone in mode
  `none`). Impact is bounded only by the SQL login's grants. Not demonstrated
  against a live server in this review.
- **Remediation:** Add a server-side routine allowlist (registry like
  `config/write-resources.php`) with per-role scoping; reject `sys`, `master`,
  `msdb`, `sp_`/`xp_` prefixes and multi-part database names; consider
  removing `routine.execute` from Read Only and `frontend.read`; document
  required SQL login grants.
- **Confidence:** High (control path); impact depends on database grants.

### SSA-02 — First-run administrator creation is public on the API boundary (High)

- **File:** `api/index.php:103, 122, 128-129, 135-143`;
  `app/Services/SetupService.php:59-121`;
  `docs/Windows-IIS-Deployment.md` §17, §19.2
- **Code:** `$publicAuthenticationActions = array_merge($setupActions, $authActions);`
  `new AuthenticationMiddleware(true, $publicAuthenticationActions)`
- **Control path:** Unauthenticated `auth.csrf` (obtains a session and CSRF
  token) → `setup.createAdmin` on the **public** API → creates the System
  Administrator + Application Administrator identity when
  `installation.initialized` is false.
- **Why it matters:** Until setup completes, anyone who can reach `/api`
  can claim the first administrator. That identity can then use the public
  `auth.users.*` and `auth.apiKeys.*` actions (see SSA-04). The IIS guide makes
  `/api` reachable before the setup step.
- **Existing mitigation:** Setup succeeds once (lock + `initialized` flag);
  CSRF token required (but self-obtainable); password policy.
- **Exploitable:** Yes, during the pre-initialization window on any
  network-reachable deployment.
- **Remediation:** Serve setup actions only from the loopback Admin boundary
  (remove from `api/index.php`), and/or require a one-time setup secret
  generated on the server; document completing setup before exposing `/api`.
- **Confidence:** High.

### SSA-03 — JSON Query Mode can read any object the SQL login can read (Medium)

- **File:** `app/Requests/QueryRequestValidator.php:959-976`;
  `app/Repositories/Query/SelectBuilder.php`; `app/Middleware/AuthorizationMiddleware.php:20`
- **Control path:** `select`/`union` → `source.table` identifier (dots
  allowed) → metadata lookup → `SELECT ... FROM <identifier>`. Authorization is
  `data.read` or `frontend.read`, with no table scope.
- **Why it matters:** Readers (including frontend users and mode `none`) can
  query any table, view, catalog view, or other database visible to the login
  (for example `sys.*`, `INFORMATION_SCHEMA`, `otherdb.dbo.*`), not only
  application tables.
- **Existing mitigation:** Identifier validation, metadata checks, prepared
  values; documented in `docs/Validation-and-Errors.md:122-125`; least-privilege
  database login expected.
- **Exploitable:** Yes, subject to database grants.
- **Remediation:** Optional per-role table/view allowlist or schema
  restriction for JSON Query Mode; reject multi-part names with a database
  component and system schemas by default.
- **Confidence:** High.
- **Correction:** `MetadataRepository::tableExists()` matches
  `INFORMATION_SCHEMA.TABLES.TABLE_NAME` in the connected database, and every
  physical source, join, subquery, union branch, and CTE body passes through it.
  Qualified names such as `sys.objects`, `INFORMATION_SCHEMA.TABLES`, or
  `otherdb.dbo.X` were therefore already rejected. The real gap was that every
  table and view of the application database was readable without an
  application allowlist.

### SSA-04 — User and API-key administration is exposed on the public API (Medium)

- **File:** `api/index.php:105-121, 131-132, 161-176`
- **Control path:** `auth.users.*`, `auth.apiKeys.*`, `auth.roles.list`,
  `auth.frontendUsers.*` are dispatched by the public entry point, gated by
  session + `admin.manage` (or `frontend.users.manage`) + CSRF.
- **Why it matters:** The design keeps the Admin Console loopback-only, but a
  System Administrator session from any network location can create users,
  assign System Administrator, and mint API keys. A stolen admin credential or
  session is therefore usable remotely, and the loopback boundary only protects
  `admin.*` actions.
- **Existing mitigation:** Session authentication, `admin.manage`, CSRF,
  login throttling, last-administrator protection, audit logging.
- **Exploitable:** Not a bypass; it widens the attack surface for
  compromised administrator credentials.
- **Remediation:** Decide whether backend user/API-key management must be
  public. If not, keep only `auth.frontendUsers.*` (and self-service actions)
  on `api/index.php` and serve the rest from the loopback Admin API.
- **Confidence:** High (code); the design intent needs confirmation.

### SSA-05 — An administrator password hash is present in pushed Git history (Medium)

**Corrected severity: High.**

- **File:** `config/auth.json` at commit `6c50c0e` (removed in `6a92a7e`)
- **Control path:** That revision contains one user record with a bcrypt
  `passwordHash` and admin flag. The commit is reachable from `dev`,
  `origin/main`, `a/dev`, and `b/dev`.
- **Why it matters:** Anyone with repository read access can attempt offline
  cracking. If that password is still in use (here or elsewhere), it is at risk.
- **Existing mitigation:** bcrypt; current tracking excludes runtime config
  (`.gitignore`); the file shape has since changed.
- **Exploitable:** Only if the password is still in use and the repository
  is readable by an untrusted party. Repository visibility not verified.
- **Remediation:** Confirm whether the account or password is still in use
  anywhere and rotate it. History rewriting is optional and should be weighed
  against the shared remotes.
- **Confidence:** High (presence); exposure unknown.
- **Correction and incident record (no secret values):**
  - The hash in `6c50c0e` is byte-identical to the current stored hash of the
    same account, an enabled System Administrator in the review installation
    (compared locally; only a boolean result was produced). The password has
    therefore not been changed since the commit (2026-09-20).
  - The commit is reachable from `origin/main`, `origin/dev`, `a/*`, and `b/*`,
    and both GitHub repositories were anonymously readable on 2026-10-06.
  - Remediation: the administrator rotates the password through the Admin
    Console (`auth.users.changePassword`, which also increments `authVersion`
    and ends existing sessions) and anywhere the password was reused. After
    rotation, only a boolean check confirms that the stored hash no longer
    matches the historical hash.
  - Git history is intentionally not rewritten: the hash is already public in
    clones and forks, and rotation is what removes the risk.
  - Rotation completed (2026-10-06): the operator rotated the affected System
    Administrator password through the Admin Console and confirmed login with
    the new password. The current stored hash for the same account was then
    compared locally against the historical hash with `hash_equals`; the
    result was **no match** (and no other stored record matches it). Only the
    boolean result was produced; neither value was displayed or recorded.
  - Status: **Cleared — credential rotated.** The historical hash remains in
    public Git history, but it no longer corresponds to the account's
    credential. Any other system where the same password was reused is outside
    this repository and remains the operator's responsibility.

### SSA-06 — `frontend.read` skips SQL Resource scope checks (Low)

- **File:** `app/Services/AuthorizationService.php:38`;
  `app/Middleware/AuthorizationMiddleware.php:17`
- **Code:** `if ($permission === 'frontend.read' && $principal->frontendAccess) return;`
- **Control path:** `sql` action → `authorizeAny(['sql.execute','frontend.read'], $resource, 'sql')`.
  If `sql.execute` is denied for that resource, `frontend.read` returns before
  `sqlResources` is consulted.
- **Why it matters:** Any user with frontend access can run every SQL
  Resource, regardless of `sqlResources` restrictions on their roles.
- **Existing mitigation:** Default roles grant `sqlResources: ['*']`, so
  there is no impact with the shipped configuration.
- **Exploitable:** Only after an operator narrows resource scopes.
- **Remediation:** Apply the resource scope check to `frontend.read` (use the
  frontend role's `sqlResources`).
- **Confidence:** High.

### SSA-07 — Client-supplied SQL Resource filters can test non-exposed columns (Low, potential)

**Correction:** confirmed. Even without an explicit `expression`, `placement: "source"` resolves any column of the resource's top-level FROM tables (the shipped frontend relies on this for non-projected filter fields), and `LIKE` allows value inference.

- **File:** `app/Resources/SqlResourceRegistry.php:56-93`;
  `app/Repositories/SqlRepository.php:315-321`
- **Control path:** Request `execution.filters.<name>` with
  `placement: "source"` and any qualified identifier → injected as a `WHERE`
  predicate in the authored query's source scope with a bound value.
- **Why it matters:** A caller can filter on columns that the resource does
  not return (for example a joined table's sensitive column) and infer their
  values from row counts. No SQL injection is possible: expressions are
  identifier- or aggregate-regex constrained.
- **Existing mitigation:** Regex-constrained expressions, bound values,
  resource scoping, read-only single-statement resources.
- **Exploitable:** Depends on which columns resource SQL can reach.
- **Remediation:** Move filter mappings into server-owned resource metadata
  (or restrict client mappings to `columns`).
- **Confidence:** Medium.

### SSA-08 — Loopback and rate-limit identity rely solely on `REMOTE_ADDR` (Low, potential)

- **File:** `admin/index.php:3-7`; `admin/router.php:16-22`;
  `app/Middleware/LocalAdminMiddleware.php:13-16`;
  `app/Security/SecurityConfiguration.php:118-122`
- **Why it matters:** Behind a same-host reverse proxy (for example IIS ARR,
  a local TLS terminator, or a tunnel agent), every request arrives from
  `127.0.0.1`. The application-level loopback gate then allows remote users,
  and login/API throttling is shared by all clients (one client can lock out
  others).
- **Existing mitigation:** Templates use direct FastCGI (real client address)
  and web-server loopback ACLs for Admin; forwarded headers are deliberately
  not trusted.
- **Exploitable:** Only in non-template proxy topologies.
- **Remediation:** Document that Admin must not sit behind a local proxy;
  optionally add explicit trusted-proxy configuration for the client address.
- **Confidence:** Medium.

### SSA-09 — ODBC driver auto-detection can fall back to legacy drivers (Low, potential)

**Correction:** confirmed for the default configuration: new database settings default to `driver: auto` (`app/Services/AdminService.php`), and any connection error, including certificate validation, triggered the fallback chain.

- **File:** `database/drivers/SqlServerDriver.php:23-45, 148-203, 260-273`
- **Why it matters:** With `driver: auto`, a failed connection on ODBC 18
  (including a TLS certificate validation failure) is retried with ODBC 17/13/11,
  Native Client, and the legacy `SQL Server` driver. Older drivers have weaker
  TLS support and different certificate-validation behavior. `encrypt` and
  `trustServerCertificate` are admin-settable with no production warning.
- **Existing mitigation:** Each attempt passes the same `Encrypt` and
  `TrustServerCertificate` values; defaults are `encrypt: true`,
  `trustServerCertificate: false` (`app/Services/AdminService.php:216-217`);
  connection values are validated against `;{}` and newlines.
- **Exploitable:** Depends on which drivers are installed and their
  behavior; not demonstrated.
- **Remediation:** In production, require an explicit modern driver (or stop
  the fallback on TLS/certificate errors); flag `encrypt: false` or
  `trustServerCertificate: true` in production validation and health.
- **Confidence:** Medium.

### SSA-10 — Some query literals are inlined with quote doubling (Low, potential)

**Correction:** Informational. Public CASE values use the recursive expression path and are bound parameters; inlining remains only in legacy builder shapes, and no bypass of quote doubling exists.

- **File:** `app/Repositories/Query/SqlExpressionBuilder.php:41-56`;
  `app/Repositories/Query/SelectBuilder.php:1423-1440, 1802, 1830, 1910`
- **Why it matters:** CASE `then`/`else`, COALESCE defaults, and some window
  values are emitted as `'...'` literals with `'` doubled, not as `?`
  parameters. T-SQL has no backslash escapes, so doubling is sound, and no
  bypass was found (NUL truncation only yields syntax errors; numeric strings
  pass `is_numeric`). It is inconsistent with the "runtime values are
  prepared" principle and fragile to future changes.
- **Existing mitigation:** Escaping; validator restricts literal types to
  JSON scalars.
- **Exploitable:** No known path.
- **Remediation:** Bind these values as parameters as well.
- **Confidence:** Medium.

### SSA-11 — Unpaginated reads and routine results are not size-bounded (Low)

- **File:** `app/Requests/QueryRequestNormalizer.php:116-119`;
  `app/Repositories/QueryRepository.php:28-68`; `core/QueryEngine.php`
- **Why it matters:** Pagination is optional for `select`, `union`, and
  routines, and all rows are fetched into PHP memory. Any reader can request
  very large result sets (memory/CPU/database load).
- **Existing mitigation:** Query timeout, request rate limit, PHP
  `memory_limit`, `maxPageSize` when pagination is used.
- **Exploitable:** Yes, as resource exhaustion by an authenticated reader.
- **Remediation:** Apply a default page size or hard row cap when pagination
  is omitted.
- **Confidence:** High.

### SSA-12 — Bundled Windows PHP runtime is outside the dependency review (Low)

- **File:** `runtime/windows/php/` (80 tracked files)
- **Why it matters:** The repository ships PHP 8.5.10 with OpenSSL 3.5.7 and
  further third-party DLLs (ICU, libssh2, libpq, nghttp2, SQLite, …) plus
  `phpdbg.exe`, `deplister.exe`, and Apache SAPI DLLs. v2.1.1 reviewed only the
  WSL runtime. No provenance/checksum record exists, and the bundled `php.ini`
  enables `allow_url_fopen`.
- **Existing mitigation:** Used for local Windows development; production
  templates use a separately installed `C:\PHP`.
- **Exploitable:** No direct path; this is a supply-chain and maintenance gap.
- **Remediation:** Record the source URL/SHA-256 of the bundle, include it in
  dependency reviews, remove unused binaries, or download at setup instead of
  tracking it in Git.
- **Confidence:** High.

### SSA-13 — Password length allows more than bcrypt's 72-byte input (Informational)

- **File:** `app/Security/PasswordPolicy.php` (max 1024);
  `app/Security/PasswordHasher.php` (`PASSWORD_DEFAULT`)
- Bytes after 72 are ignored by bcrypt. Impact is negligible with a 12-character
  minimum; consider capping at 72 bytes or moving to Argon2id.

### SSA-14 — Public liveness discloses version, port, and start time (Informational)

- **File:** `api/health.php:22-26`; `app/Health/ApplicationHealthMonitor.php:48-58`
- `/health` (and direct `health.php` under IIS) returns `version`, `port`,
  `startedAt`, `uptimeSeconds` unauthenticated. `/health/live` is already
  trimmed. Consider trimming `/health` identically in production.

### SSA-15 — Login throttling is per IP + username only (Informational)

- **File:** `app/Security/LoginRateLimiter.php:84-87`
- One IP can spray many usernames, and distributed sources can each attempt
  one username. The general API rate limit partly offsets this. Consider an
  additional per-username and per-IP budget.

### SSA-16 — Anonymous sessions are created on demand (Informational)

- **File:** `app/Security/CsrfTokenService.php:20-29` via public `auth.csrf`
- Each anonymous call can create a session file. Rate limiting bounds the rate.
  Monitor session storage or issue CSRF tokens without server sessions before
  login.

### SSA-17 — CSRF allowlist excludes routine actions (Informational)

- **File:** `app/Middleware/CsrfProtectionMiddleware.php:8-56`
- `procedure`/`function`/`tableFunction` can have side effects (SSA-01) but
  are not CSRF-validated for session callers. Cross-site exploitation is
  blocked by the required `application/json` content type (preflight), exact
  CORS origins, and `SameSite=Lax`. Add them to the list for defense in depth.

### SSA-18 — AES-GCM envelopes carry no associated data (Informational)

- **File:** `app/Security/DatabaseCredentialEncryption.php:90-153`
- Random 96-bit nonce, 128-bit tag, strict base64 and length checks are
  correct. Password and whole-configuration envelopes share one key with no
  AAD, so they are interchangeable. Low value to an attacker; consider binding
  a purpose string as AAD in a future envelope version.

### SSA-19 — Dynamic controller dispatch in the public entry point (Informational)

- **File:** `api/index.php:203-228`
- `require_once` of `ucfirst($controller) . 'Controller.php'`, `new $controller()`,
  and `$instance->$action()` are reached only with values set by
  `QueryRequestNormalizer` (fixed `SQL`/`Query`/`Write`/`Metadata` controllers
  and an allowlisted action set). Safe today; replace with an explicit map so
  that future normalizer changes cannot widen it.

### SSA-20 — CI uses mutable third-party action tags and an older PHP (Informational)

- **File:** `.github/workflows/backend-tests.yml`
- `shivammathur/setup-php@v2` and `actions/checkout@v4` are tag-pinned, not
  SHA-pinned. CI runs PHP 8.2 while the review runtime is 8.4 and the bundled
  Windows runtime is 8.5. Pin actions by commit SHA and test the supported
  PHP versions.

## Reviewed and not a finding

| Area | Sink / concern | Why it is controlled |
| --- | --- | --- |
| Process execution | `proc_open`, `exec` in `ApiProcessManager.php:135, 275-331` | argv array (no shell); bind address fixed to `127.0.0.1` by validator and repository; PIDs are integers; development only |
| Process execution | `shell_exec` in `scripts/application-backup.php:19` | CLI-only, fixed `escapeshellarg` paths |
| Dynamic include | `require` in `SqlResourceDiscovery.php:16`, `ApplicationBackupManager.php:428`, `ApplicationHealthMonitor.php:355` | Fixed repository paths |
| eval / unserialize / XML | — | Not present in application code; no XML parsing |
| SQL identifiers | JSON Query, SQL Resource, write builders | Regex-validated identifiers, `[...]` quoting, operator allowlists, `(int)` casts for TOP/OFFSET/FETCH, metadata queries parameterized |
| Writes | `WriteRepository` and builders | Deny-by-default registry, writable/filterable column lists, mandatory filters for UPDATE/DELETE, unique-key check for UPSERT, parameterized values |
| DSN injection | `SqlServerDriver::buildDsn` | `server`/`database`/`username` reject `;{}`, CR/LF, NUL; driver allowlist; port integer |
| SQL Resource files | `SqlResourceDiscovery` | ID regex, `realpath` containment, excluded `system/` and dot-directories, duplicate detection |
| Backups | ZIP read/write, restore | Custom stored-only ZIP reader (no compression, size/entry/CRC limits, symlink and name checks), HMAC-SHA256 manifest signature, fixed entry set and order, per-file SHA-256 and schema validation, paths confined to `backups/`, strict filename/ID regexes |
| Error disclosure | `ExceptionHandler`, `Response` | Generic client messages and correlation IDs; ODBC/driver text stays in redacted logs |
| Logging | `Logger`, `OperationalLogger` | Key- and pattern-based redaction (passwords, API keys, CSRF, cookies, encryption/signing keys, environment secrets); SQL literals redacted; request bodies not logged |
| XSS | Admin and SQL Parser JS | Dynamic values pass `escapeHtml` or `textContent`; unescaped values are server-generated hex IDs, numbers, or regex-restricted usernames; strict CSP |
| Open redirect | `admin/router.php:56-61` | Path-only allowlisted `Location` |
| SSRF | `ApiProcessManager::health` | Fixed `http://127.0.0.1:<int port>/health` |

## Security controls already present

- **Authentication:** `password_hash`/`password_verify` with rehash, dummy-hash
  timing equalization, 12-character minimum, login throttling and lockout,
  generic credential errors, session regeneration on login, `authVersion`
  invalidation on password/role/identity changes, idle and absolute session
  expiry, strict/cookie-only sessions (`use_strict_mode`, `use_only_cookies`,
  `use_trans_sid=0`), HttpOnly, SameSite=Lax, Secure in production/HTTPS.
- **API keys:** `gsk_<id>_<secret>` with 256-bit secrets, hash-only storage,
  strict format check, constant-time ID comparison, owner/enabled/revoked
  checks on every request; legacy key needs ≥ 32 bytes and `hash_equals`.
- **Authorization:** Deny-by-default permissions, separate backend/frontend
  domains, per-request role resolution from storage, last-administrator
  protection, audit of denials.
- **CSRF/CORS:** Per-session 256-bit token with `hash_equals`, rotation at
  login; exact-origin allowlist (no `*`, no paths), `Vary: Origin`, invalid
  environment overrides fail closed; JSON-only content type forces preflight.
- **Input handling:** POST + `application/json` only; bounded body reader
  (stream read capped at limit + 1); unknown-property rejection throughout;
  recursion depth tracking in expression validation.
- **Secrets:** AES-256-GCM configuration encryption with keys outside the
  repository; backup HMAC signing key with 0600 enforcement; runtime
  configuration ignored by Git; atomic 0600 writes; locked JSON stores.
- **Web hardening:** Production templates with HSTS, CSP, frame/sniff/referrer
  headers, entry-point allowlists, sensitive-extension denial, loopback-only
  Admin site, separate FastCGI boundaries, `GENERIC_ADMIN_ENABLED` only on the
  Admin boundary, `expose_php` off, production `display_errors` off.
- **Operations:** Readiness never opens SQL connections; request-correlated
  audit logs; existing attack-oriented test suite (`tests/SecurityTestingTest.php`
  and others).

## Files inspected

- Entry points: `api/index.php`, `api/router.php`, `api/health.php`,
  `admin/index.php`, `admin/router.php`, `admin/api.php`,
  `sqlparser/index.php`, `sqlparser/router.php`
- Middleware: `app/Middleware/*` (authentication, local admin, admin
  authorization, authorization, CSRF, API rate limit, logging)
- Security: `app/Security/*` (API key authenticator, CSRF, security
  configuration, password hasher/policy, credential encryption and resolvers,
  login rate limiter), `app/Backup/BackupSigningKey.php`
- Services/controllers: `AuthSessionService`, `AuthService` (login),
  `AuthorizationService`, `ApiKeyService`, `SetupService`, `SetupController`,
  `QueryController`, `QueryService`, `BaseController`, `AdminService`
  (selected), `UserManagementService` (selected)
- Requests: `QueryRequestValidator`, `QueryRequestNormalizer`,
  `SqlRequestValidator`, `WriteRequestValidator`, `AdminRequestValidator`
- Data access: `core/QueryEngine.php`, `database/drivers/SqlServerDriver.php`,
  `database/factory/DriverFactory.php`, `app/Repositories/QueryRepository.php`,
  `SqlRepository.php`, `WriteRepository.php`, `MetadataRepository.php`,
  `Query/RoutineBuilder.php`, `Query/SqlExpressionBuilder.php`,
  `Query/SelectBuilder.php` (literal, CASE, CONCAT, TOP paths),
  `Write/UpsertBuilder.php`, `Write/WriteSqlBuilder.php`,
  `app/Resources/SqlResourceDiscovery.php`, `SqlResourceRegistry.php`,
  `queries/system/Columns.sql`, `config/write-resources.php`
- Files/backups: `app/Backup/ApplicationBackupManager.php`,
  `BackupRecoveryService.php` (validation paths), `SafeZipArchive.php`,
  `core/JsonFileStore.php`, `app/Configuration/RuntimeConfiguration.php`,
  `app/Repositories/AdminConfigurationRepository.php` (defaults, origins)
- Runtime/process: `app/Runtime/ApiProcessManager.php`,
  `scripts/application-backup.php`, `app/Http/RequestBodyReader.php`,
  `app/Http/AdminBasePath.php`, `app/Health/ApplicationHealthMonitor.php`
  (liveness/readiness)
- Errors/logging: `core/ExceptionHandler.php`, `core/Response.php`,
  `core/Logger.php`, `core/OperationalLogger.php`
- SQL Parser: `sqlparser/src/SqlParserRequestHandler.php`,
  `sqlparser/assets/js/app.js`
- Admin UI: `admin/assets/admin.js` (all `innerHTML` sinks and interpolations)
- Deployment/CI/repository: `deployment/iis/*.web.config.example` (API,
  Admin), `deployment/nginx/generic-sql-api.linux.example.conf`,
  `.gitignore`, `.github/workflows/backend-tests.yml`,
  `runtime/windows/php/` (version identification only), Git history for
  runtime configuration, key, and environment files

## Checks performed

- Pattern searches across non-test PHP for process execution, `eval`,
  `assert`, (un)serialization, XML parsers, dynamic calls/instantiation,
  dynamic `include`/`require`, superglobal and header reads, and
  `Location` headers, each traced to its source.
- Searches for SQL string construction and literal emission in builders;
  validator rules confirmed for every interpolated identifier.
- Git history: runtime configuration, key, `.env`, and log paths checked for
  committed content (shape only, no values printed); tracked files searched for
  private-key, API-key, and cloud-key patterns.
- Version identification of the bundled Windows PHP and OpenSSL.
- Baseline: the existing backend suite (`php tests/run.php`) was run without
  changes.
