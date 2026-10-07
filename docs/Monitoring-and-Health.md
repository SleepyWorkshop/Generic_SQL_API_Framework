# Monitoring and health

The backend exposes public liveness and readiness probes and an authenticated
detailed health check. It does not provide alerting, metrics storage, tracing,
process supervision, or an external monitoring platform.

## Endpoints and access boundaries

| Signal | Endpoint/action | Access | HTTP behavior | Cost |
|---|---|---|---|---|
| API liveness | `GET /health/live` | Public | `200` when PHP can respond | Constant-time; no configuration, filesystem, encryption, or SQL checks |
| Development process probe | `GET /health` | Used by the development process managers | `200` including version, port, and start metadata | Same lightweight liveness path |
| API readiness | `GET /health/ready` | Public | `200` when ready, otherwise `503` | Local configuration/runtime/application checks plus any cached connectivity result; no SQL connection |
| Detailed health | Admin action `admin.health` | Authenticated System Administrator (`admin.manage`) | Existing Admin JSON envelope | Local checks, managed-process probes, and a short-cached database test |

The probes are identified by their final path segments, so the internal IIS
routes `/api/health/live` and `/api/health/ready` behave exactly like
`/health/live` and `/health/ready`. Any other path that reaches `health.php`
returns the development process metadata. The Nginx example routes only the two
probes; the IIS API application also allows direct `health.php` requests, which
therefore return that metadata (SSA-14 in
[Security verification](security/Security-Verification.md)).

The probe responses contain only a status, safe category, service/version, and
the four readiness check results. They never return paths, credentials, keys,
connection strings, exception messages, SQL, environment values, sessions,
headers, or process command lines. Safe GET probes do not require CSRF tokens;
the authenticated Admin action continues to use the existing session,
authorization, CORS, and CSRF rules for its POST request.

## Liveness and readiness

Liveness answers only whether the request-handling PHP process can respond. It
does not imply that IIS, Nginx, every PHP worker, SQL Server, or the complete
host is healthy.

Readiness validates the supported runtime configuration file set and versions,
the writable runtime directory, the API application runtime, the explicit
database availability gate, and the ability to decrypt and validate database
configuration. It deliberately does not open SQL connections, so proxy probes
cannot generate SQL Server load. When the detailed check has already cached a
connectivity failure for the current configuration (15 seconds), readiness
reports that cached category; it never runs the test itself. In production a
disabled API application runtime (`api_disabled`) is not ready, because every
API request would return `503 SERVICE_UNAVAILABLE`; in development the check is
`process_managed`. SQL Parser state is independent and cannot make the API
unready. Logging capability is a diagnostic/degraded state, and backup state is
never a readiness blocker.

The application readiness categories are `api_enabled`, `api_disabled`,
`process_managed`, and `configuration_invalid`. The database readiness
categories are `database_available`,
`database_disconnected`, `configuration_missing`, `configuration_invalid`, and
`encryption_key_missing`. Detailed checks can additionally report `connected`,
`authentication_failure`, or `database_unavailable`. Raw driver diagnostics are
never returned.

## Detailed health

System Health preserves Admin, API, SQL Parser, database, and PHP lifecycle
cards. In development, Admin, API, and Parser cards show the managed PID,
selected port, start time, and process state. In production, `admin.health`
includes `hosting` (mode and detected web server), and the Admin Console, API,
and Parser cards instead show web-server ownership (for example IIS Managed)
plus, for API and Parser, Enabled/Disabled application runtime state and
update/reload timestamps. PID, port, and start time are null for all three
because the answering FastCGI/FPM worker is not owned by the application. The
database card reports `state` as `disabled` (Disconnected, reason
`application_access_disabled`), `connected`, or `unhealthy` with a safe reason.

The System Health page shows only operational cards: Admin Console, API Server,
SQL Parser, Database, PHP Runtime, Configuration, Logging, Encryption, and
Backup. Implementation-level filesystem and PHP session-directory diagnostics
are not part of System Health; sessions, configuration files, logs, and backups
keep working exactly as before, and a missing configuration file or runtime
directory is still reported through the Configuration check and readiness. The
detailed response contains these checks:

- `application`: the responding application version;
- `configuration`: required configuration presence, JSON readability, and
  format versions;
- `database`: database availability and sanitized connectivity category; in
  production it adds `warnings` (`encrypt_disabled`,
  `trust_server_certificate_enabled`, `legacy_driver_configured`) for weakened
  SQL Server transport settings without changing the status;
- `logging`: fail-open logging capability;
- `encryption`: encryption-key presence and usability, without exposing key material
  (`configured`, `missing`, `key_invalid`, `invalid` for a wrong key or
  tampered envelope, or `configuration_missing`);
- `backup`: backup state from `BackupRecoveryService::health()` (see below);
- `processes`: Admin/API/SQL Parser running, stopped, stale, crashed, or
  unresponsive state as determined by the existing process managers in
  development, or application availability in production.

When database access is enabled, detailed health may perform the existing
minimal connection test. Its safe result is cached locally for 15 seconds in
`runtime/health/`, keyed to the database configuration checksum. The response
marks cached results. The cache is concurrency-safe through `JsonFileStore`,
contains no credentials, and cannot survive a database configuration change.
Readiness never invokes this test, so frequent proxy probes cannot generate SQL
Server connection load.

The logging check and the readiness runtime check are shallow directory checks:
exists/readable/writable plus `disk_free_space`. They do not traverse files.
Default warning and critical free-space thresholds are 1 GiB and 256 MiB. A
missing or low-space logging directory is degraded because audit logging is
intentionally fail-open; a missing, unwritable, or critically full runtime
directory makes the API unready. A required configuration or database
dependency can be unhealthy.

Backup health is reported as a separate `backup` check that never changes the
overall System Health status or readiness, because backups are not part of
request serving. It reuses the existing backup service: storage availability,
the validated schedule, the recovery-point count, the newest recovery point's
signature/integrity verification (only that archive, without requiring the
database key), the last scheduled attempt, and the next scheduled run. Statuses
are `healthy` (`verified`), `not_configured` (`no_backups`; a fresh installation
is not a failure), `degraded` (`backup_failed`, `backup_overdue`), and
`unhealthy` (`storage_unavailable`, `configuration_invalid`,
`verification_failed`, `signing_key_unavailable`). It never creates storage,
backups, or signing keys and never returns paths or key material. Database
Connect, Disconnect, Restart, and backup restore clear the cached connectivity
result; any database configuration change invalidates it through its checksum.

Every restore activation still runs `ApplicationHealthMonitor::restoreSafety()`
after staging and validation; a failure triggers rollback to the previous
configuration.

## Hosting layers

Under IIS/FastCGI, IIS application-pool and FastCGI worker availability are
separate from application readiness. Apply the example rewrite rules, secure
the application pool identity, and monitor both the HTTP signals and SQL Server
using platform tooling. [Windows Server IIS deployment](Windows-IIS-Deployment.md#19-verify-the-deployment)
lists the expected production health results.

Under Nginx/PHP-FPM, Nginx, the PHP-FPM pool, application readiness, and SQL
Server are four separate health layers. The production example maps only
`/health/live` and `/health/ready` to the health entry point. The application
does not inspect or control systemd, PHP-FPM workers, or Nginx.

In development the PHP built-in server uses `api/router.php`; `/health` serves
the process managers, while `/health/live` and `/health/ready` behave as in
production.

## External monitoring boundary

An uptime monitor, reverse proxy, or load balancer may call liveness/readiness.
Detailed diagnostics remain an Admin-only operator surface. The framework does
not implement Prometheus, alerting, centralized collection, tracing, or a
Windows/Linux monitoring agent.

Production validation must exercise the deployed IIS or Nginx routes, service
identity permissions, session and log paths, configured disk thresholds,
database authentication failures, cache behavior across real workers, proxy
timeouts, and monitoring cadence. Local regression tests do not validate a
live SQL Server or multi-worker shared-filesystem deployment.

Target-host procedures are in
[Production security and deployment](Production-Security-and-Deployment.md#operator-checklists). In
production, API and SQL Parser infrastructure is explicitly externally managed
while application availability remains Admin-controlled. A disabled runtime is
an intentional application state, not a claim that IIS/Nginx/FastCGI or PHP-FPM
is down. External platform monitoring remains authoritative for those workers,
while application liveness stays independent of the enabled flag.
