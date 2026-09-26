# Audit and security logging

The backend writes security audit records to
`Backend/logs/audit/YYYY-MM-DD.jsonl`. Each record is one compact JSON object per
line. Human-readable API, Admin, database, and SQL Parser diagnostics are kept
in separate subsystem directories as documented in
[Operational logging](Operational-Logging.md). Logs are never exposed through an
API or Admin Console page.

## Audit record format

Every security audit record contains:

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
role, authentication method, safe API-key ID/fingerprint, source address,
action, resource name, target identity, reason category, PID, port, or elapsed
time. The current request correlation ID is reused across API, Admin, database,
and runtime events; session IDs are never used as correlation identifiers.
Untrusted strings are JSON-encoded, length-bounded, and cannot create a second
record.

Implemented categories include authentication success/failure/rate rejection,
session expiration and invalidation, authorization denial, API-key lifecycle
and invalid authentication, user lifecycle and authorization changes, CSRF
rejection, API rate rejection, security configuration changes, database
connection/query failure and timeout, and Admin runtime lifecycle operations.
Severities distinguish routine success (`INFO`), security-relevant denial or
mutation (`NOTICE`), throttling/degraded behavior (`WARNING`), and operational
failure (`ERROR`). Ordinary bad passwords are not classified as critical.

## Data minimization and redaction

Audit records intentionally exclude passwords and hashes, raw API keys,
Authorization/Cookie headers, session IDs, CSRF tokens, encryption keys,
connection strings, decrypted database configuration, request bodies, raw SQL
parameters, and database result data. API-key events use only stored IDs and
fingerprints. Configuration events name the category and outcome rather than
serializing configuration values.

Callers construct safe categorical events, and the logger applies boundary
redaction for common credential/header forms as defense in depth. Query
diagnostics continue to replace literal SQL values and log only parameter counts
and types. Web-server access-log formats must also omit credentials, cookies,
Authorization values, and query strings containing sensitive data.

## File security, concurrency, and failure behavior

New application log directories are created with mode `0700` and log files are
restricted to `0600` on POSIX. Windows deployments must apply an equivalent NTFS
ACL for the IIS application-pool identity and authorized operators. The supplied
IIS/Nginx boundaries do not route `Backend/logs`; directory browsing is disabled,
and backup/temporary/log extensions are denied as defense in depth.

Workers use an exclusive advisory lock for each complete append. If advisory
locking is unavailable, the logger performs one append-mode write so a record
is not deliberately split across writes. This is suitable for the documented
single-host local filesystem. It is not a distributed logging guarantee and
must not be assumed safe on filesystems with unreliable append/lock semantics.

Logging is fail-open for application availability: directory creation, open,
lock, write, flush, and permission failures are contained and do not recursively
log or change the API response. Operators must monitor log-path availability,
free space, ownership, and write failures externally because the application
cannot reliably report a failure through the failed sink itself.

## Rotation and retention

The date-based filename separates new records daily but does not delete,
compress, archive, or ship old logs. Rotation and retention are deployment
responsibilities:

- Linux deployments should configure `logrotate` or an equivalent service for
  `Backend/logs/audit/*.jsonl`, retaining files according to organizational incident,
  privacy, and storage requirements. Use `copytruncate` only after validating
  concurrent append behavior, or rotate between daily filenames. Archives must
  retain restricted ownership/modes and remain outside web roots.
- Windows deployments should use an approved scheduled task or log-management
  policy running under an account allowed to read the directory. Move/archive
  only closed daily files, preserve restrictive NTFS ACLs, and never publish the
  archive below an IIS content root.

Retention must be explicitly approved; this project does not prescribe a legal
retention period. Deleting an old closed file does not affect current requests.
Production alerting should detect disk exhaustion and loss of expected audit
volume. Centralized collectors or SIEM integration may be evaluated separately;
none is implemented by this phase.
