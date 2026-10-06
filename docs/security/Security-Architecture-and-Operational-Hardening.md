# Security Architecture and Operational Hardening

Review status: **Completed** — architecture and operational review complete; 3 findings resolved, 5 accepted; target-host verification remains operator-owned — 2026-10-06

This document records roadmap phase v2.1.5 (Security Architecture & Operational
Hardening) of
[v2.1 — Security Verification & Operational Hardening](../Roadmap.md#v21--security-verification--operational-hardening).
It is a review of the repository's code, deployment templates, and
documentation:

- **Not a DAST phase:** no active scanning, penetration-style testing, or
  deployed-host access was performed.
- **Deferred testing stays deferred:** the authenticated, session, rate-limit,
  and injection dynamic testing deferred in v2.1.4 remains with the external
  penetration test (see the [DAST report](DAST-Report.md) and
  [Penetration-test preparation](Penetration-Test-Preparation.md)).
- **No secrets:** no password, hash, API key, encryption key, or other secret
  is reproduced here.

## Contents

1. [Architecture](#1-architecture)
2. [Trust boundaries](#2-trust-boundaries)
3. [Secrets and configuration](#3-secrets-and-configuration)
4. [PHP runtime](#4-php-runtime)
5. [IIS and Nginx](#5-iis-and-nginx)
6. [Filesystem and least privilege](#6-filesystem-and-least-privilege)
7. [Database](#7-database)
8. [Sessions](#8-sessions)
9. [Logging and auditing](#9-logging-and-auditing)
10. [Backups](#10-backups)
11. [Error handling](#11-error-handling)
12. [Health and monitoring](#12-health-and-monitoring)
13. [Deployment](#13-deployment)
14. [Findings](#14-findings)
15. [Accepted risks and remaining work](#15-accepted-risks-and-remaining-work)
16. [Verification](#16-verification)

## 1. Architecture

```text
Browser / API client
    │  HTTPS (TLS terminated by IIS or Nginx; HSTS and browser headers set there)
    ▼
IIS (URL Rewrite, request filtering, ipSecurity) / Nginx (fixed locations)
    │  FastCGI to fixed entry points only
    ├── /api        api/index.php, api/health.php            public
    ├── /admin      admin/index.php, admin/api.php, assets   loopback only
    └── /sqlparser  sqlparser/index.php, assets              loopback / internal only
    ▼
Middleware: runtime gate → authentication → rate limit → frontend-user gate
            → CSRF → controllers → authorization → database availability → validation
    ▼
Services: authentication, sessions, users, API keys, query, write, routines, Admin
    ▼
Authorization: role permissions and deny-by-default resource scopes
    ▼
Storage: runtime JSON (GENERIC_RUNTIME_CONFIG_DIR), encrypted database.json,
         PHP sessions, logs, backups, rate-limit counters
SQL Server: ODBC, one request-owned connection, prepared parameters
```

The Admin API adds its own action allowlist, `LocalAdminMiddleware` (loopback
`REMOTE_ADDR` and `GENERIC_ADMIN_ENABLED=1`), and `AdminAuthorizationMiddleware`
(`admin.manage`) before CSRF. The SQL Parser is a separate boundary: it has no
authentication, no database access, and no configuration access, and it never
executes SQL. Its sources contain no file, network, or ODBC calls. The request
flow and role model are documented in detail in the
[Authorization & API security inventory](Authorization-API-Security-Inventory.md).

## 2. Trust boundaries

| Boundary | Enforced by | Notes |
|---|---|---|
| Transport | IIS or Nginx TLS; `HTTPS` passed by the server, never taken from forwarded headers | `Secure` cookies follow production mode or direct HTTPS only |
| Authentication | `AuthenticationMiddleware`: session cookie, managed API key, or both, per `authentication.mode` | API keys never authenticate identity management or the Admin API |
| Authorization | `AuthorizationService` role permissions and resource scopes; `FrontendUserAuthorizationMiddleware` and `UserManagementService` for frontend administration | Client-supplied role or identity fields are ignored (v2.1.3) |
| Admin API | IIS `ipSecurity` or Nginx `allow`/`deny` to loopback; `LocalAdminMiddleware`; `GENERIC_ADMIN_ENABLED=1` set only on the Admin worker; `admin.manage`; CSRF | Uses `REMOTE_ADDR` only; must not sit behind a same-host proxy |
| SQL Parser | Loopback or internal binding; fixed routing to `index.php` and two assets | Shares no authentication with the API or Admin |
| Database | Request-scoped ODBC connection; allowlisted sources, routines, and write resources; prepared parameters | Database credentials are decrypted only in request memory |
| Filesystem | Fixed FastCGI targets; no generic `*.php` routing; state directories outside web roots | See [Section 6](#6-filesystem-and-least-privilege) |
| Secrets | Worker environment (FastCGI registration or FPM pool) for the encryption key; AES-256-GCM `database.json`; hashed passwords and API keys | See [Section 3](#3-secrets-and-configuration) |
| Session | PHP file sessions, cookie-only, strict mode, host-only cookie, idle and absolute timeouts, authentication-version revocation | See [Section 8](#8-sessions) |
| Logging | Allowlisted audit fields and redaction; logs never served | See [Section 9](#9-logging-and-auditing) |

Privilege escalation between layers:

- **Frontend-managed users and Application Administrators:**
  - they cannot reach backend-only or System Administrator accounts (AAPI-01,
    AAPI-02);
  - they cannot assign backend roles;
  - they cannot reach Admin actions, which are not routed by the public API.
- **Backend users:** they reach Admin actions only with `admin.manage`, from
  loopback, on the Admin worker.
- **API keys:** keys are confined to their role and scopes, cannot hold the
  System Administrator or Application Administrator role, and cannot manage
  identities.
- **Configuration and SQL execution:** SQL text comes only from allowlisted
  sources and authored SQL Resources, and values are always parameters.

This review found no new escalation path between layers. Two process-level
observations, shared worker identity and single-host state, are recorded as
SAOH-04 and SAOH-08.

## 3. Secrets and configuration

| Secret or sensitive value | Where it lives | Exposure controls |
|---|---|---|
| `GENERIC_SQL_API_ENCRYPTION_KEY` | IIS: environment of the `api` and `admin` FastCGI registrations in `applicationHost.config` (Administrators and SYSTEM only). Linux: PHP-FPM pool or service environment | Never in `web.config`, the repository, scripts, or command lines; not set for the SQL Parser on IIS; never returned by health or Admin responses (`UnifiedAdminConsoleTest`) |
| Database credentials | `database/config/database.json` as an authenticated AES-256-GCM envelope | Decrypted in request memory only; Admin returns `passwordConfigured`, never the password, ciphertext, or connection string |
| User passwords | `auth.json` as `password_hash(PASSWORD_DEFAULT)` hashes with rehash on login | Never logged or returned; hashes are excluded from responses |
| API keys | `api-keys.json` as `password_hash` secret hashes plus a 12-character SHA-256 fingerprint | Raw key shown once; logs carry only the ID and fingerprint |
| Session identifiers and CSRF tokens | PHP session storage outside web roots | Never logged; CSRF tokens are 256-bit and compared in constant time |
| Backup-signing key | `GENERIC_BACKUP_SIGNING_KEY`, `GENERIC_BACKUP_SIGNING_KEY_FILE`, or owner-restricted `runtime/secrets/backup-signing.key` | Never written to a ZIP or manifest (see SAOH-05) |
| Legacy shared API key (optional) | `GENERIC_SQL_API_KEY` in the worker environment | Keys shorter than 32 characters are refused |

Repository review:

- **Tracked files:** `.gitignore` excludes `database/config/database.json`,
  `runtime/secrets/`, `storage/`, logs, backups, and `.env`. Only
  `*.example.json` configuration is tracked.
- **Secret scan:** a scan of tracked files found no private keys, API keys,
  encryption keys, or credential hashes. The only match is
  `AuthService::DUMMY_PASSWORD_HASH`, a fixed placeholder used to equalize
  login timing for unknown users; it is not a credential.
- **Tests:** test fixtures use synthetic `fake-*` or per-run values.
- **Frontend assets:** `VITE_*` values are documented as public, and no
  secret is built into frontend or Admin assets.

On IIS, environment variables set in PowerShell or the system environment do
not reach FastCGI workers (Windows Process Activation Service). The guide
therefore sets every variable on the FastCGI registration and recycles the
pool. This is the only way the worker receives the key without placing it in
`web.config` or on a command line.

## 4. PHP runtime

`deployment/php-production-security.ini` is merged into the production
`php.ini`:

| Setting | Value | Why it is appropriate |
|---|---|---|
| `display_errors`, `display_startup_errors`, `html_errors` | Off | No diagnostics in responses; `api/index.php` also forces `display_errors=0` in production |
| `log_errors`, `error_reporting` | On, `E_ALL` | Server-side PHP error log outside web roots |
| `expose_php` | Off | No PHP version header. The bundled Linux development runtime was aligned in v2.1.4 (DAST-01) |
| `zend.exception_ignore_args` | On | Exception traces carry no argument values |
| `file_uploads` | Off | The application has no multipart uploads; backup restore uses a JSON body |
| `post_max_size`, `upload_max_filesize` | 10M | Matches the IIS `maxAllowedContentLength` and Nginx `client_max_body_size` of 10 MB |
| `max_execution_time`, `max_input_time`, `memory_limit` | 60, 60, 256M | Bounded per-request resources; the application also has a query timeout |
| `session.use_cookies`, `use_only_cookies`, `use_strict_mode`, `use_trans_sid` | 1, 1, 1, 0 | Cookie-only, strict sessions with no URL identifiers; the application also enforces these at session start |
| `session.cookie_secure`, `cookie_httponly`, `cookie_samesite` | 1, 1, Lax | Defense in depth; the application sets the same values |
| `session.cookie_lifetime`, `gc_maxlifetime` | 0, 28800 | Browser-session cookie; the application aligns garbage collection with its absolute timeout |
| `opcache.validate_timestamps` | 0 | Deterministic code after deployment; a pool recycle or FPM reload is required ([Section 13](#13-deployment)) |
| `session.save_path`, `error_log` | Host-specific | Set per host to directories outside web roots (IIS guide section 6.3) |

Considered and not changed:

- **`disable_functions`:** the development process manager uses `proc_open`,
  and backup and health code are shared between modes. Production mode
  disables process management in the application. A host may add a
  `disable_functions` list after validating it against its own use, but the
  repository cannot choose one that is safe for every deployment.
- **`allow_url_fopen`:** production code paths make no remote URL requests,
  but no evidence shows that leaving the default creates an exploitable path.
  It is left to host policy.
- **`open_basedir`:** layout-specific. It is not set by the template and is
  left to host policy.

## 5. IIS and Nginx

Template review results (`deployment/iis/*.web.config.example`,
`deployment/nginx/*.conf`):

| Check | Result |
|---|---|
| Only intended entry points execute | Pass. IIS URL Rewrite allowlists `index.php`/`health.php` (API), `api.php` plus pages (Admin), and `index.php` (SQL Parser); everything else returns 404. Nginx has no generic `location ~ \.php$`; every PHP location sets a fixed `SCRIPT_FILENAME` |
| Config, logs, backups, hidden files | Pass. They are not under any application path; IIS denies sensitive extensions and hides `src` (parser) and `.git` (frontend); Nginx denies dotfiles and sensitive extensions |
| Directory listing | Pass. `directoryBrowse enabled="false"`; Nginx has no `autoindex` |
| Unsupported methods | Pass. The application returns 405 for non-POST API and Admin requests and for non-GET/HEAD health requests (v2.1.3 coverage) |
| Admin loopback | Pass. IIS `ipSecurity allowUnlisted="false"` with `127.0.0.1` and `::1`; Nginx loopback listeners plus `allow`/`deny`; `LocalAdminMiddleware` in the application |
| FastCGI environment scope | Pass on IIS: one registration per boundary; `GENERIC_ADMIN_ENABLED` and the encryption key are not set for the parser. Nginx sets `GENERIC_ADMIN_ENABLED` per Admin location, but the encryption key is in the shared pool environment (SAOH-04) |
| PHP version disclosure | Pass. `expose_php = Off`; the application also removes `X-Powered-By` |
| Static assets | Pass. Only the named Admin and parser assets and the built frontend are served |
| `/api`, `/admin`, `/sqlparser` boundaries | Pass. Separate IIS applications or Nginx servers with separate handlers and headers |
| Health routing | The IIS API application routes `/api/health/live` and `/api/health/ready` to `health.php`, which classified them incorrectly (SAOH-03, resolved) |

## 6. Filesystem and least privilege

The PHP worker identity needs:

| Location | Access | Contents |
|---|---|---|
| Code: `api/`, `admin/`, `sqlparser/`, `app/`, `core/`, `queries/`, `database/drivers/`, `config/` | Read only | Application code and the shipped PHP configuration, including the query-source, routine, write-resource, and SQL Resource allowlists |
| `runtime/windows/`, `runtime/linux/` | Read only | Bundled development PHP runtimes, not used by IIS or PHP-FPM |
| Runtime configuration directory (`GENERIC_RUNTIME_CONFIG_DIR`) | Read/write | `auth.json`, `authorization.json`, `api-keys.json`, `admin.json`, `installation.json`, availability state, lock files |
| `database/config/` | Read/write | Encrypted `database.json` saved by the Admin Console |
| `runtime/` (other contents) | Read/write | Health cache, backup lock, `runtime/secrets` signing key |
| `storage/` | Read/write | Rate-limit counters |
| `logs/`, PHP error log | Read/write | Operational and audit logs |
| `backups/` | Read/write | Application recovery points |
| PHP session directory | Read/write | Session files |
| `.git/`, other temporary files | No access | — |

The IIS guide previously granted the application pool Modify on
`Backend\config` and all of `Backend\runtime` (SAOH-01, SAOH-02). The guidance
now:

- moves runtime configuration to `state\config` through
  `GENERIC_RUNTIME_CONFIG_DIR`;
- keeps `Backend\config` and the development runtimes read-only;
- includes migration steps for existing installations.

`scripts/validate-production.php` now reports whether the runtime
configuration directory is outside the code tree. Windows ACLs are never
changed automatically. The guide provides the `icacls` commands for operators.

## 7. Database

- **Least privilege:**
  - the IIS guide grants `db_datareader` only;
  - it adds `db_datawriter` only when write resources are registered, and
    `EXECUTE` only when routines are exposed;
  - it recommends narrower schema, view, or procedure grants where possible;
  - `db_owner` is never required: the application runs no DDL, and SQL
    Resources reject `INTO`, multiple statements, and DML (v2.1.2 SSA
    remediation).
- **Authentication:**
  - Windows authentication through `IIS APPPOOL\GenericSQLAPI`, or the web
    server's computer account for a remote SQL Server, is recommended;
  - with SQL authentication, the password is typed interactively and stored
    only in the encrypted `database.json`.
- **Transport:**
  - ODBC Driver 18 encrypts by default;
  - `trustServerCertificate` defaults to off;
  - production driver selection does not fall back to legacy drivers (v2.1.2);
  - transport warnings are reported by the validator and Admin health without
    secrets.
- **Network:** SQL Server exposure is limited to the database port from the
  application host (IIS guide section 15). Host firewalls and SQL Server
  configuration are deployment-owned.
- **Backups:** SQL Server data is backed up only by native SQL Server backups;
  application backups never contain database data.

The breadth of `db_datareader` is recorded as SAOH-06. No database
permissions were changed by this review.

## 8. Sessions

Existing regression tests show the session architecture meets every requirement
in scope. No new tests were needed.

| Requirement | Evidence |
|---|---|
| Session ID regenerated at login; prior session deleted; strict mode rejects old IDs | `SecurityHardeningTest`, `SecurityTestingTest`, `AuthenticationFlowTest` |
| Logout destroys server-side state; logged-out cookie replay rejected | `SecurityHardeningTest`; over HTTP in `AuthorizationApiCoverageTest` |
| Password, rename, role, access, enable/disable, and deletion changes revoke sessions | `SecurityTestingTest`, `AdminUserManagementTest`; over HTTP in `AuthorizationApiCoverageTest` |
| Cookie host-only, path `/`, session lifetime, `HttpOnly`, `SameSite=Lax`, `Secure` in production | `SecurityHardeningTest`, `HttpsSecurityTest`, `SecurityTestingTest` |
| Idle and absolute timeouts | `SecurityHardeningTest`, `SecurityTestingTest`, `ApiProtectionTest` |
| Storage outside web roots, worker-only access, cleanup at or beyond the absolute timeout | Documented in [Production security and deployment](../Production-Security-and-Deployment.md#session-storage-and-lifetime) and IIS guide section 6.3; target-host verification is operator-owned |

## 9. Logging and auditing

Audit events are emitted for:

- login success, failure, and rate rejection;
- logout, and session expiry and invalidation;
- user creation, update, enable/disable, password changes, and deletion;
- role and authorization changes and authorization denials;
- API-key creation, enable/disable, revocation, and invalid use;
- CSRF rejection;
- API rate rejection;
- security and database configuration changes;
- database connection tests;
- runtime lifecycle operations;
- backup and restore.

Records use allowlisted fields. They exclude passwords and hashes, raw API
keys, session IDs, CSRF tokens, encryption keys, connection strings, request
bodies, and SQL parameter values. The logger also redacts common credential
and header forms (`SecurityTestingTest`, `SecurityAuditLoggingTest`). Log
files are created `0700`/`0600` on POSIX; Windows uses the NTFS grants in
IIS guide section 10.

Operational and security logs are kept separate
(`logs/{api,admin,database,sqlparser}` and `logs/audit`). Rotation, retention,
and central collection are deployment responsibilities (SAOH-07). See
[Audit and security logging](../Audit-and-Security-Logging.md).

## 10. Backups

- **Contents:** application backups contain only the runtime JSON
  configuration and the encrypted `database.json`.
- **Exclusions:** the manifest's fixed exclusion list includes the database
  encryption key, the backup-signing key, sessions, process, availability and
  rate-limit state, logs, and SQL Server data, and is validated on restore.
- **Plaintext:** password and API-key values appear only as hashes, and the
  database password appears only inside the encrypted envelope.
- **Integrity:** SHA-256 integrity and a manifest signature from the
  installation's signing key are verified before preview and restore.
- **Storage:** archives are written only inside `backups/`, which is outside
  every document root and not routed by any template.
- **Restore authorization:** restore is an Admin action that requires
  loopback, `admin.manage`, CSRF, and explicit confirmation. Restored files
  are validated by the same repositories as normal writes, and the previous
  configuration returns if activation or its health check fails.
- **Retention:** retention is configurable (1–365) and documented in
  [Backup and recovery](../Backup-and-Recovery.md).
- **Key custody:** the signing key's default location is discussed as
  SAOH-05.

## 11. Error handling

`ProductionErrorHandlingTest` and `SecurityTestingTest` verify that unexpected,
database, credential, and timeout errors map to fixed codes. Responses contain
no paths, SQL, ODBC diagnostics, environment variable names, credentials, or
stack traces. Buffered accidental output cannot corrupt the JSON envelope.

Production disables displayed errors in the INI, and the API also forces it at
runtime. IIS `httpErrors` uses `DetailedLocalOnly` with `PassThrough`, so
application JSON errors are not replaced. No change was needed.

## 12. Health and monitoring

| Signal | Access | Exposure | Database connection |
|---|---|---|---|
| `/health/live` | Public | `status`, `service`, `version` | None |
| `/health/ready` | Public | Overall status and four categorical checks | None (cached connectivity category only) |
| `/health` | Development router only | Managed process port and start time | None |
| `admin.health` | Admin: loopback, `admin.manage`, CSRF | Detailed safe categories and process state; no secrets | Short-cached connection test |

SAOH-03 (resolved): under the IIS API application, `/api/health/ready` was
answered by the liveness branch. It always returned 200 with process metadata,
so a monitor probing it could never see "not ready". Probes are now identified
by their final path segments.

Logging, encryption, and backup health are reported in Admin detailed health,
not publicly. No Grafana or external monitoring configuration exists in the
repository. External monitoring and alerting are deployment responsibilities
([Monitoring and health](../Monitoring-and-Health.md)).

## 13. Deployment

The IIS update procedure (IIS guide section 22):

- creates an application backup first;
- optionally disables the API;
- copies the release without overwriting state;
- re-runs the configuration bootstrap and NTFS commands;
- recycles the application pool, which is required because
  `opcache.validate_timestamps = 0`;
- tells operators to hard-refresh browsers so new Admin and parser
  JavaScript loads;
- re-verifies the deployment before re-enabling the API.

Rollback keeps previous release folders and restores configuration only
through verified application backups. Secrets are injected through the
FastCGI or FPM environment and never through files in the release.

With runtime configuration in `state\config` (SAOH-01), copying a release over
`Backend` can no longer overwrite users, keys, or settings. Re-running the
NTFS commands after each copy keeps `Backend\config` and the development
runtimes read-only.

Operational risks that remain deployment-owned:

- a skipped recycle serves stale bytecode;
- a skipped re-ACL after adding folders;
- browser-cached Admin assets after an update.

## 14. Findings

| ID | Category | Severity | Status |
|---|---|---|---|
| SAOH-01 | Filesystem / least privilege | Medium | Resolved |
| SAOH-02 | Filesystem / least privilege | Low | Resolved |
| SAOH-03 | Health / monitoring | Low | Resolved |
| SAOH-04 | Architecture / process isolation | Informational | Accepted |
| SAOH-05 | Backups / key custody | Informational | Accepted |
| SAOH-06 | Database / least privilege | Informational | Accepted |
| SAOH-07 | Logging / operations | Informational | Accepted |
| SAOH-08 | Architecture / scaling | Informational | Accepted |

No Critical or High finding was identified.

### SAOH-01 — Runtime state shared a writable directory with executable configuration (Medium)

- **Current behavior (before):**
  - the IIS guide granted the application pool Modify on `Backend\config`
    and set no `GENERIC_RUNTIME_CONFIG_DIR`, so users, keys, and settings were
    written there;
  - that directory also holds `constants.php`, `app.php`, and the
    query-source, routine, write-resource, and SQL Resource allowlists, which
    every request executes;
  - the Linux guidance pointed `GENERIC_RUNTIME_CONFIG_DIR` at
    `Backend/config` as well.
- **Risk:** the worker identity could rewrite code and authorization
  allowlists it executes. Any file-write flaw, or anyone acting as the pool
  identity, could turn into code execution or an allowlist bypass. No such
  flaw is known; this is a least-privilege defect in the documented
  production layout.
- **Recommendation and change:**
  - the IIS and production hosting guides now place runtime configuration in
    a separate state directory through `GENERIC_RUNTIME_CONFIG_DIR`, set on
    all three FastCGI registrations and for command-line scripts;
  - `Backend\config` is read-only for the pool;
  - migration steps are included for existing installations;
  - `ProductionValidator` reports `runtimeConfiguration` as
    `inside_code_tree` (operator validation required) or
    `outside_code_tree`, without printing paths.
- **Regression test:** `tests/OperationalHardeningTest.php` (validator
  results, sibling-prefix handling, no path output, IIS and hosting
  guidance).
- **Status:** Resolved in the repository. Existing installations must apply
  the migration.

### SAOH-02 — Development PHP runtimes were writable by the production pool (Low)

- **Current behavior (before):** Modify on `Backend\runtime` also covered the
  81 tracked files under `runtime\windows` and `runtime\linux` (PHP binaries,
  extensions, and `php.ini`).
- **Risk:** IIS executes `C:\PHP`, not these files, so there is no direct
  production execution path. A writable code tree is still unnecessary.
- **Change:** the NTFS commands reset both folders to read and execute, and
  the permission table lists them.
- **Regression test:** `tests/OperationalHardeningTest.php`.
- **Status:** Resolved.

### SAOH-03 — Readiness at `/api/health/ready` always reported healthy (Low)

- **Current behavior (before):**
  - `api/health.php` treated only the exact path `/health/ready` as
    readiness;
  - the IIS API application routes `^health/(live|ready)$` to `health.php`,
    so a request to `/api/health/ready` (the internal route shown in the IIS
    guide) took the liveness branch;
  - that branch returned 200 with process port metadata, even when the
    database was disconnected.
- **Risk:** monitoring integrity. A load balancer or monitor probing the
  internal path could never observe "not ready", and the response included
  slightly more metadata than the public liveness contract.
- **Change:**
  - `health.php` identifies probes by their final segments
    (`/health/live`, `/health/ready`) at any mount path;
  - only the bare development `/health` still returns process metadata;
  - the monitoring doc notes the internal routes.
- **Regression test:** `tests/OperationalHardeningTest.php` runs
  `health.php` with `/health/ready`, `/api/health/ready`, a deeper mount, a
  query string, liveness at both mounts, and non-probe lookalikes. Without
  the fix, `/api/health/ready` returned 200.
- **Status:** Resolved. Whether IIS presents the pre-rewrite or rewritten
  `REQUEST_URI` for the top-level `/health/*` rewrite, both forms are now
  handled.

### SAOH-04 — Boundaries share one worker identity (Informational)

- **Current behavior:**
  - on IIS, the `/api`, `/admin`, and `/sqlparser` applications share the
    `GenericSQLAPI` pool identity, and with it filesystem rights and any
    Windows-authenticated SQL Server login;
  - the Nginx example uses one PHP-FPM pool, so the encryption key in the pool
    environment is also present in parser and Admin workers.
- **Risk:** a compromise of the unauthenticated, loopback-only SQL Parser
  would run with the application's file and database rights. The parser has
  no file, network, or database calls, and v2.1.4 limited its executable
  surface (DAST-02).
- **Recommendation:** where the parser is deployed, give it a dedicated IIS
  application pool or FPM pool with no access to state directories and no
  database login, or do not deploy it on production hosts.
- **Status:** Accepted as a documented deployment option. No hosting
  architecture change.

### SAOH-05 — Default backup-signing key shares the host and identity with the archives (Informational)

- **Current behavior:** without `GENERIC_BACKUP_SIGNING_KEY` or
  `GENERIC_BACKUP_SIGNING_KEY_FILE`, the key is created in
  `runtime/secrets/backup-signing.key`, which the same worker identity that
  writes `backups/` can read.
- **Risk:** signatures detect tampering of archives stored or moved
  elsewhere, but not by a party that already controls the worker identity.
- **Recommendation:** supply the signing key from the secret vault through
  the environment, or a key file outside the code tree, and keep a vault copy
  (already required for recovery).
- **Status:** Accepted, as designed and documented in
  [Backup and recovery](../Backup-and-Recovery.md).

### SAOH-06 — `db_datareader` grants read on every table (Informational)

- **Current behavior:** the guide's baseline database grant is
  `db_datareader`. Application allowlists restrict which sources are queried.
- **Risk:** if an application-layer control failed, the database login would
  not limit reads.
- **Recommendation:** use schema-, view-, or procedure-level grants for the
  exposed data (already recommended in IIS guide section 16.3).
- **Status:** Accepted operational recommendation. No database change by
  this review.

### SAOH-07 — Log rotation, retention, and central collection are external (Informational)

- **Current behavior:** daily log files are never deleted or shipped by the
  application, and logging fails open.
- **Recommendation:** configure OS-level rotation and retention, disk and
  audit-volume alerting, and optionally a SIEM, as documented in
  [Audit and security logging](../Audit-and-Security-Logging.md).
- **Status:** Accepted, as a deployment responsibility.

### SAOH-08 — Security state is single-host (Informational)

- **Current behavior:** sessions, rate-limit counters, lock files, and
  runtime configuration are local files with advisory locks.
- **Risk:** running multiple hosts or network filesystems would split rate
  limits and sessions.
- **Recommendation:** keep a single application host, or design shared
  stores before scaling out.
- **Status:** Accepted, as documented in
  [Production security and deployment](../Production-Security-and-Deployment.md#runtime-concurrency-and-resource-ownership).

## 15. Accepted risks and remaining work

- **External penetration test (deferred from v2.1.4):** authenticated,
  session, rate-limit, and injection dynamic testing; TLS; deployed IIS and
  Nginx behavior; and the Admin boundary behind production hosting. See
  [Penetration-test preparation](Penetration-Test-Preparation.md).
- **Operational recommendations:**
  - apply the SAOH-01 migration on existing installations;
  - optionally isolate the parser pool (SAOH-04);
  - supply the signing key from a vault (SAOH-05);
  - narrow database grants (SAOH-06);
  - configure log rotation and alerting (SAOH-07);
  - verify ACLs, session storage, and the effective `php.ini` on each target
    host.
- **Accepted risks:**
  - SAOH-04 to SAOH-08;
  - earlier accepted items: AAPI-05 to AAPI-07, SSA-13 to SSA-20 (deferred),
    and the development-only DAST-03 to DAST-05.

## 16. Verification

- **Regression suites:** `php -n tests/run.php` and `php tests/run.php` pass.
- **Targeted security suites:** pass, including the new
  `OperationalHardeningTest`.
- **Mutation checks:** nine checks against the new controls (health suffix
  matching, health lookalike rejection, validator classification, prefix
  separator, path non-disclosure, and four guidance assertions) were all
  caught. Each file was restored and its checksum verified.
- **Repository checks:** PHP lint, documentation tests, `git diff --check`,
  and a secret scan of the changed files pass.

Files changed:

- `api/health.php` (SAOH-03)
- `app/Deployment/ProductionValidator.php` (SAOH-01)
- `docs/Windows-IIS-Deployment.md`, `docs/Production-Security-and-Deployment.md` (SAOH-01, SAOH-02)
- `docs/Monitoring-and-Health.md` (SAOH-03)
- `tests/OperationalHardeningTest.php`, `tests/run.php`
- `docs/security/Security-Architecture-and-Operational-Hardening.md` (this document)
- `docs/Roadmap.md`, `CHANGELOG.md`
