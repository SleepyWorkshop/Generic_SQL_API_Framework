# Operational logging

Operational logs are human-readable diagnostics for request and application
lifecycle troubleshooting. They complement, and do not replace, the structured
security audit log.

## Files and correlation

The application selects a file from the current PHP/runtime timezone on every
write, so a running worker automatically starts using the next date's file:

```text
logs/api/YYYY-MM-DD.txt
logs/admin/YYYY-MM-DD.txt
logs/database/YYYY-MM-DD.txt
logs/sqlparser/YYYY-MM-DD.txt
logs/audit/YYYY-MM-DD.jsonl
```

`GENERIC_OPERATIONAL_LOG_DIR` may point operational diagnostics at another
protected base directory. `GENERIC_LOG_DIR` relocates the structured audit base;
production paths must remain outside every document root. Normal application
logging does not create a root-level date file or `php_errors.log`.

API, Admin, database, and parser events include the existing request ID. A
typical entry is:

```text
[2026-09-27 01:42:18] [INFO] [req_8f92ab] admin.restore.activation.start
    Recovery Point: 20260926T204317Z-b3be13e0c582
```

Use the response `meta.requestId` (also returned in `X-Request-ID`) to search the
same dated API and subsystem files. Only subsystems used by that request will
have matching entries. Security-relevant activity may also have a matching JSON
record under `logs/audit/`.

## Coverage

API logs record safe request start/completion/failure metadata and duration.
Admin logs record authorized operations and important restore stages, including
preview, confirmation receipt, activation, and post-restore health validation.
Database logs record connection/query lifecycle, safe result counts, timeout or
failure categories, and duration. SQL Parser logs record input length/hash,
parse/generation outcome, safe error location, and duration; raw SQL is not
copied into this diagnostic log.

The Admin browser reports only a fixed allowlist of restore and JavaScript
failure events through an authenticated, authorized, CSRF-protected endpoint.
It is not a general-purpose browser log collector and cannot submit arbitrary
fields or event names.

Each HTTP/CLI entry point registers its current `api`, `admin`, or `sqlparser`
context. Supported PHP warnings, notices, deprecations, and recoverable errors
are captured by the central error handler and written as text to that context.
Uncaught exceptions and supported fatal/shutdown errors are recorded once in the
same operational context and once as a security audit event. Database lifecycle
failures use the database log explicitly. Native PHP error logging is disabled
after application bootstrap to prevent duplicate generic files; startup/engine
failures before bootstrap remain the responsibility of IIS, Nginx/PHP-FPM, or
the service manager. PHP cannot capture every process or engine crash.

## Security and failure behavior

Never write request bodies, raw SQL values, passwords, database credentials,
connection strings, encryption/signing keys, API-key secrets, Authorization or
Cookie headers, CSRF tokens, or session IDs. The logger accepts lifecycle
metadata, restricts subsystems and levels, removes control characters, bounds
values, and applies recursive credential-name/content redaction as defense in
depth.

Directories are owner-only (`0700`) and files are owner-only (`0600`) on POSIX;
use equivalent NTFS ACLs on Windows. One complete entry is appended under an
exclusive lock. Logging failure is contained and never changes the application
result. The web server must continue denying access to `logs/`, and generated
logs remain Git-ignored.

Daily file selection is automatic; deletion is not. Operators own retention,
archival, disk monitoring, and any approved OS-level `logrotate`/scheduled-task
policy. Preserve permissions and never publish archived logs under a document
root.
