# Logging

The backend writes two kinds of logs: human-readable operational diagnostics
per subsystem, and structured security audit records. Neither is exposed through
any API or Admin Console page.

## Files

The file is chosen by date in the runtime timezone on every write, so a running
worker moves to the next day's file automatically.

```text
logs/api/YYYY-MM-DD.txt        operational: public API
logs/admin/YYYY-MM-DD.txt      operational: Admin Console and Admin API
logs/database/YYYY-MM-DD.txt   operational: connection and query lifecycle
logs/sqlparser/YYYY-MM-DD.txt  operational: SQL Parser
logs/audit/YYYY-MM-DD.jsonl    security audit (JSON Lines)
```

| Variable | Effect |
|---|---|
| `GENERIC_OPERATIONAL_LOG_DIR` | Base directory for the operational `.txt` logs |
| `GENERIC_LOG_DIR` | Base directory for the audit log |

Production log directories must be outside every document root.

## Correlation

Every request has a random request ID, returned as `meta.requestId` in every
response (success and error) and in the `X-Request-ID` header. The same ID
appears in the operational logs of every subsystem the request touched and in
any audit record it produced. Session IDs are never used as correlation IDs.

```text
[2026-09-27 01:42:18] [INFO] [req_8f92ab] admin.restore.activation.start
    Recovery Point: 20260926T204317Z-b3be13e0c582
```

## Operational logs

- **API:** request start, completion, failure, and duration.
- **Admin:** authorized operations and restore stages (preview, confirmation,
  activation, post-restore health validation). The Admin UI can report only a
  fixed allowlist of restore and JavaScript failure events through
  `admin.operational.event`.
- **Database:** connection and query lifecycle, query phase (count, compatibility
  check, data query), safe result counts, timeout and failure categories, safe
  SQLSTATE, and duration. SQL is logged with literals redacted, plus parameter
  count and types, never parameter values.
- **SQL Parser:** input length and hash, parse/generation outcome, safe error
  location, and duration; raw SQL is not logged.

Each HTTP and CLI entry point registers its subsystem. PHP warnings, notices,
deprecations, and recoverable errors are captured by the central error handler
and written to that subsystem's log. Uncaught exceptions and fatal shutdown
errors are recorded once in the operational log and once as an audit event.
Native PHP error logging is disabled after bootstrap; failures before bootstrap
(and process or engine crashes) are left to IIS, PHP-FPM, or the service
manager's error log.

## Audit records

Each audit record is one JSON object per line:

```json
{
  "timestamp": "2026-01-01T00:00:00+00:00",
  "requestId": "safe-correlation-id",
  "recordType": "security_audit",
  "event": "auth.login",
  "outcome": "success",
  "severity": "INFO",
  "component": "authentication"
}
```

Optional allowlisted fields identify the actor type, stable user ID, username,
role, authentication method, API-key ID and fingerprint, source address, action,
resource name, target identity, reason category, PID, port, or elapsed time.
Untrusted strings are JSON-encoded and length-bounded and cannot create a
second record.

Events cover login success, failure, and throttling; session expiry and
invalidation; authorization denials; API-key lifecycle and invalid use; user
lifecycle and authorization changes; CSRF and API rate-limit rejections; security
and database configuration changes; database connection and query failures and
timeouts; Admin runtime lifecycle operations; and backup and restore. Severity
is `INFO` for routine success, `NOTICE` for security-relevant denials and
changes, `WARNING` for throttling and degraded behavior, and `ERROR` for
operational failures.

## Redaction

Logs never contain request bodies, raw SQL parameter values, passwords or
hashes, database credentials, connection strings, encryption or signing keys,
API-key secrets, `Authorization` or `Cookie` headers, CSRF tokens, session IDs,
or decrypted configuration. Callers log safe categorical metadata, and the
loggers additionally remove control characters, bound values, and apply
key- and pattern-based credential redaction. Web-server access-log formats must
also exclude credentials, cookies, and sensitive query strings.

## File security and failure behavior

On POSIX, log directories are created `0700` and files `0600`; on Windows apply
equivalent NTFS ACLs for the worker identity and authorized operators. Each
entry is appended under an exclusive lock (or one append-mode write if locking
is unavailable), which is safe for one host on a reliable local filesystem.

Logging fails open: directory, open, lock, write, or permission failures never
change the API response or recurse. Monitor log-path availability, free space,
and expected audit volume externally, because a failed sink cannot report its
own failure.

## Rotation and retention

The application never deletes, compresses, archives, or ships logs. Rotation,
retention, and central collection are deployment responsibilities:

- **Linux:** use `logrotate` or an equivalent for each subsystem directory.
  Rotate between daily files, or validate `copytruncate` against concurrent
  appends first. Keep archives restricted and outside web roots.
- **Windows:** use an approved scheduled task or log-management policy that
  moves only closed daily files and preserves restrictive ACLs.

Retention periods must be set by organizational policy. No SIEM or central
collector is configured.
