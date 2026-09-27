# Monitoring and health

The monitoring implementation extends the runtime lifecycle health model with HTTP
signals and authenticated diagnostics; it does not provide alerting, metrics
storage, tracing, process supervision, or an external monitoring platform.

## Endpoints and access boundaries

| Signal | Endpoint/action | Access | HTTP behavior | Cost |
|---|---|---|---|---|
| API liveness | `GET /health/live` | Public | `200` when PHP can respond | Constant-time; no configuration, filesystem, encryption, or SQL checks |
| Managed-process compatibility | `GET /health` | Loopback built-in API/parser lifecycle | Existing `200` response including managed port/start metadata | Same lightweight liveness path |
| API readiness | `GET /health/ready` | Public | `200` when ready, otherwise `503` | Local configuration/runtime checks; no SQL connection |
| Detailed health | Admin action `admin.health` | Authenticated System Administrator (`admin.manage`) | Existing Admin JSON envelope | Local checks, managed-process probes, and a short-cached database test |

The public responses contain only a status, safe category, service/version, and
the three readiness check results. They never return paths, credentials, keys,
connection strings, exception messages, SQL, environment values, sessions,
headers, or process command lines. Safe GET probes do not require CSRF tokens;
the authenticated Admin action continues to use the existing session,
authorization, CORS, and CSRF rules for its POST request.

## Liveness and readiness

Liveness answers only whether the request-handling PHP process can respond. It
does not imply that IIS, Nginx, every PHP worker, SQL Server, or the complete
host is healthy.

Readiness validates the supported runtime configuration file set and versions,
the writable runtime directory, the explicit database availability gate, and
the ability to decrypt and validate database configuration. It deliberately
does not open SQL connections. SQL Parser state is independent and cannot make
the API unready. Logging capability is a diagnostic/degraded state, not a
readiness blocker. Backup scheduling and recovery-point status are reported
only on Admin → Backup & Recovery and do not affect System Health.

The database readiness categories are `database_available`,
`database_disconnected`, `configuration_missing`, `configuration_invalid`, and
`encryption_key_missing`. Detailed checks can additionally report `connected`,
`authentication_failure`, or `database_unavailable`. Raw driver diagnostics are
never returned.

## Detailed health

System Health preserves Admin, API, SQL Parser, database, and PHP lifecycle
cards. In development, API and Parser cards show the managed PID, selected port,
start time, and process state. In production, those cards instead show external
infrastructure ownership plus Enabled/Disabled application runtime state and
update/reload timestamps; PID, port, and start time remain null because the
application does not own the web-server workers. The detailed response also adds
safe checks for:

- required configuration presence, JSON readability, and format versions;
- database availability and sanitized connectivity category;
- configuration, runtime, and temporary-directory availability;
- fail-open logging capability;
- PHP session-directory availability;
- encryption-key presence and usability, without exposing key material;
- Admin/API/SQL Parser running, stopped, stale, crashed, or unresponsive state
  as determined by the existing process managers.

When database access is enabled, detailed health may perform the existing
minimal connection test. Its safe result is cached locally for 15 seconds in
`runtime/health/`, keyed to the database configuration checksum. The response
marks cached results. The cache is concurrency-safe through `JsonFileStore`,
contains no credentials, and cannot survive a database configuration change.
Readiness never invokes this test, so frequent proxy probes cannot generate SQL
Server connection load.

Directory checks are shallow: exists/readable/writable plus `disk_free_space`.
They do not traverse files. Default warning and critical free-space thresholds
are 1 GiB and 256 MiB; these classify diagnostics only. A missing logging
directory is degraded because audit logging is intentionally fail-open. A
required runtime, configuration, session, or database dependency can be
unhealthy.

Removing backup scheduling from System Health does not affect restore safety.
Every activation still runs `ApplicationHealthMonitor::restoreSafety()` after
staging and validation; a failure triggers rollback to the previous
configuration.

## Hosting layers

Under IIS/FastCGI, IIS application-pool and FastCGI worker availability are
separate from application readiness. Apply the example rewrite rules, secure
the application pool identity, and monitor both the HTTP signals and SQL Server
using platform tooling.

Under Nginx/PHP-FPM, Nginx, the PHP-FPM pool, application readiness, and SQL
Server are four separate health layers. The production example maps only
`/health/live` and `/health/ready` to the health entry point. The application
does not inspect or control systemd, PHP-FPM workers, or Nginx.

The PHP built-in server uses `api/router.php`; `/health` remains compatible with
the existing process manager, while `/health/live` and `/health/ready` expose
the new semantics.

## External monitoring boundary

An uptime monitor, reverse proxy, or load balancer may call liveness/readiness.
Detailed diagnostics remain an Admin-only operator surface. A future adapter
could translate these signals for another monitoring product, but the framework
does not implement Prometheus, alerting, centralized collection, tracing, or a
Windows/Linux monitoring agent.

Production validation must exercise the deployed IIS or Nginx routes, service
identity permissions, session and log paths, configured disk thresholds,
database authentication failures, cache behavior across real workers, proxy
timeouts, and monitoring cadence. Local regression tests do not validate a
live SQL Server or multi-worker shared-filesystem deployment.

The workspace result and exact target-host procedures are recorded
in [Windows and Linux production validation](Production-Validation.md). In
production, API and SQL Parser infrastructure is explicitly externally managed
while application availability remains Admin-controlled. A disabled runtime is
an intentional application state, not a claim that IIS/Nginx/FastCGI or PHP-FPM
is down. External platform monitoring remains authoritative for those workers,
while application liveness stays independent of the enabled flag.
