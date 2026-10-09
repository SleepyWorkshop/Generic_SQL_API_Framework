# Backup and recovery

The framework provides one application-configuration backup system shared by the
Admin Console and CLI. It extends `ApplicationBackupManager`; it is not a SQL
Server backup engine, scheduler, cloud-sync client, or arbitrary file archiver.

> **Application Backup is not SQL Server Database Backup.** Use SQL
> Server-native full, differential, and transaction-log backups for database
> data and test those restores independently.

## Scope and ZIP format

The official artifact is `backup-<recovery-point-id>.zip`. The current
(format 4, V3) ZIP has exactly:

```text
manifest.json
signature.json
config/auth.json
config/installation.json
config/admin.json
config/authorization.json
config/api-keys.json
database/config/databases.json
```

These are logical paths: the `config/*.json` entries are read from and
restored to the runtime configuration directory (`GENERIC_RUNTIME_CONFIG_DIR`),
and `database/config/databases.json` to its fixed location. The database
registry is backed up as stored — server profiles, databases, the default
database, enabled flags, and every encrypted envelope — and must validate as a
registry. Recovery points of formats 2 and 3 (V2), which contain
`database/config/database.json` instead, remain verifiable and restorable:
restoring one converts its database configuration into the registry and
removes the V2 file, so the result is always a V3 installation. Password
hashes and API-key secret hashes are configuration state, but plaintext
passwords and one-time raw API-key secrets are never stored and therefore cannot
enter a backup. The manifest contains the recovery-point ID, UTC creation time,
application and format versions, application-configuration scope, creation
trigger (`manual` or `scheduled`), non-personal creator type (`user` or
`scheduler`), logical file paths, schema versions, byte sizes, and SHA-256 checksums. It contains no
configuration values or secrets.

The strict allowlist excludes database/signing encryption keys, PHP sessions,
availability/PID/process/lock/rate-limit state, logs, uploads, exports,
temporary files, backup files, unrelated configuration, and SQL Server data.
Runtime availability is host state and is neither backed up nor restored.

## Integrity and authenticity

SHA-256 file checksums detect missing or changed content. A HMAC-SHA256
signature over the canonical manifest proves that the recovery point was
produced with this installation's trusted backup-signing key. The signing key is
resolved from `GENERIC_BACKUP_SIGNING_KEY` (base64, 32 bytes),
`GENERIC_BACKUP_SIGNING_KEY_FILE`, or the owner-restricted
`runtime/secrets/backup-signing.key`; it is never written to the ZIP, manifest,
signature document, database configuration, logs, or Admin response.

The database encryption key is also separate. Verification for recovery
requires both the backup signing key and the matching
`GENERIC_SQL_API_ENCRYPTION_KEY`. A missing or wrong key fails safely. Preserve
both keys through separate approved custody channels. Authenticity protects
portable artifacts from modification; it does not protect live files against a
privileged infrastructure administrator who can replace application code,
configuration, or keys.

ZIP processing has no optional PHP extension dependency. Only stored entries
are accepted. Validation rejects corrupt archives, encryption/compression,
duplicate or unexpected entries, `..` traversal, absolute/UNC/drive paths,
backslashes, linked entries, malformed local/central records, CRC mismatches,
oversized entries, excessive total expansion, and excessive archive/entry
counts. Untrusted ZIPs are never extracted into live configuration.

## Admin Console workflow

Only a session-authenticated System Administrator (shown as **Super Admin**) can
reach **Backup & Recovery**. The backend enforces `admin.manage`; hiding controls
is not the security boundary. Creation, download, upload/preview, and restore
actions are CSRF protected and use the normal sanitized Admin error model.

**Create Backup** acquires the shared lifecycle lock, validates the allowlist and
keys, builds and signs the manifest, writes a private temporary ZIP, verifies it,
and atomically publishes it. The browser receives the completed ZIP as a Blob
and invokes its normal download/save behavior. No server path is exposed.

**Restore Backup** is a standard `<input type="file" accept=".zip">`. The browser
and operating system provide the native Windows/Linux picker. Selection uploads
the ZIP into a controlled owner-restricted temporary area, but never restores it.
The server verifies structure, signature, manifest, checksums, JSON schemas, the
encrypted database envelope, and key compatibility, then returns a secret-free
preview showing changed/unchanged files, versions, compatibility, and
verification/authenticity state. A second explicit **Restore Configuration**
confirmation is mandatory.

The browser preserves the verified upload token through confirmation and captures
the restore button before awaiting the native dialog. Confirming immediately
shows **Restoring configuration...**, prevents a duplicate submission, and sends
only the server-issued token. Success is displayed only after activation and the
health callback complete. A safe failure includes the response request ID. If a
restored `auth.json` invalidates the current session through identity, role,
enabled-state, or `authVersion` changes, the console returns to sign-in without
bypassing session validation.

Confirmed restore re-verifies the upload, validates every staged value, and activates configuration under the
shared lock using the existing atomic per-document writer. If any activation or
post-restore configuration/encryption health check fails, the prior complete
configuration set is rewritten and the operation reports failure. Sessions,
runtime state, logs, and SQL Server data are untouched.

Recovery-point history is file-backed and exposes only ID, filename, timestamp,
size, application/format version, recovery-point type, and verification and
authenticity results. Version-2 recovery points without creation metadata remain
valid and are shown as **Legacy**. No database or in-process scheduler is introduced. Uploaded restore
files are short-lived and cleaned after restore/failure or expiry.

## Manual and scheduled recovery points

Configuration changes do not automatically create a backup. User,
authentication, authorization, API-key, database, runtime, security, and restore
mutations proceed through their existing validation, atomic-write, and audit
paths without creating or requiring a recovery point.

Recovery points are created manually or by the configured scheduler. Manual
creation records `trigger=manual` and `createdBy=user`. The operating system can
invoke the scheduled CLI, which records `trigger=scheduled` and
`createdBy=scheduler`. The application does not run a PHP daemon and saving a
schedule does not install an operating-system task.

The Admin **Automatic Backups** settings are stored in `admin.json` (schema
version 6) in the runtime configuration directory: `enabled` is boolean, `frequency` is `hourly`, `daily`, or
`weekly`, `time` is `HH:MM`, and `retention` is an integer from 1 through 365.
The page reports the latest scheduled attempt and the next configured run.

The shared lock serializes backup/restore operations; existing configuration
locks serialize their mutations. Temporary names are never reported as recovery
points, so interrupted creation/restore cannot expose a completed artifact.

## CLI

All paths must be absolute and resolve beneath the repository's dedicated
`backups/` directory:

```text
php scripts/application-backup.php create <absolute-new-backup.zip>
php scripts/application-backup.php scheduled-create
php scripts/application-backup.php verify <absolute-backup.zip>
php scripts/application-backup.php stage-restore <absolute-backup.zip> <absolute-new-target>
```

`create` produces and re-verifies a caller-selected manual ZIP.
`scheduled-create` reads the validated schedule, refuses to run when disabled,
and uses the managed backup directory and shared recovery service. Configure it
in Windows Task Scheduler or Linux cron/systemd at the desired interval; the OS
controls when it runs. `verify` modifies nothing.
`stage-restore` consumes the ZIP directly and copies only the verified allowlist,
manifest, and signature into a new staging directory under `backups/`. Manual extraction
is neither required nor supported.

Example Linux cron entry (daily at 02:00, with absolute paths):

```cron
0 2 * * * /usr/bin/php /opt/generic-sql-api/Backend/scripts/application-backup.php scheduled-create
```

A systemd timer may invoke the same command. On Windows, create a Task Scheduler
task whose program is the production `php.exe` and whose arguments are the
absolute script path followed by `scheduled-create`. Run it as the restricted
application service identity, set the Backend directory as **Start in**, and
provide the same signing/encryption-key environment available to the hosted
application. Align the OS trigger with the saved frequency/time; the saved
configuration validates and reports intent but does not wake or install a task.

## System Health

System Health shows a **Backup** card built from this same service. It verifies
only the newest recovery point (signature, checksums, and schema, without
requiring the database key) and reports the recovery-point count, latest backup
time, schedule, next run, and last scheduled attempt. Its states are `healthy`
(`verified`), `not_configured` (`no_backups`: no recovery point yet, which is not
a failure), `degraded` (`backup_failed`, `backup_overdue`), and `unhealthy`
(`storage_unavailable`, `configuration_invalid`, `verification_failed`,
`signing_key_unavailable`). Backup state never changes the overall System Health
status or readiness, never creates storage, backups, or keys, and never returns
paths or key material. See [Monitoring and Health](Monitoring-and-Health.md).
For the IIS identity, NTFS permissions, and Task Scheduler notes, see
[Windows Server IIS deployment](Windows-IIS-Deployment.md#21-backups-and-restore).

## Audit events

Structured JSONL audit records include request ID and safe actor context where
available. Events include `backup.created`, `backup.verified`, `backup.failed`,
`backup.scheduled.failed`, `restore.previewed`, `restore.started`,
`restore.completed`, and `restore.failed`. Records may contain operation,
outcome, reason, and recovery-point ID, but never archives, full configuration,
paths, passwords, keys, raw API keys, decrypted credentials, or sessions.
The separate Admin operational log records the detailed preview, confirmation,
activation, and health-check lifecycle for troubleshooting.

## Storage, retention, and operations

Runtime recovery points are stored in the Backend repository's dedicated
`backups/` directory. The directory is tracked only through `.gitkeep`; all
generated ZIPs, restore uploads, schedule status, and staging artifacts are
ignored by Git. Path resolution rejects destinations outside this directory,
including traversal and symlink escapes. Keep the Backend repository itself
outside public document roots and restrict the `backups/` directory to the
application service identity.
After each successful manual or scheduled creation, retention keeps the newest
configured number of valid managed recovery points. The just-created recovery
point is protected, invalid/unrelated files are ignored, and deletion is limited
to recognized recovery-point filenames inside the managed directory. Failed
scheduled attempts produce audit/operational records and status but no fake
Verified recovery point. The framework has no cloud synchronization, internal
scheduler daemon, or remote deletion feature. Copy verified ZIPs to access-separated
off-host and, where policy requires, immutable/offline organizational storage.
Apply NTFS ACLs on Windows or owner-only `0700` directories and `0600` files on
Linux. Define retention from recovery objectives, regulation, and tested
destruction policy.

After a disaster, deploy the matching trusted application release, provision
the backup signing and database encryption keys separately, verify the ZIP,
preview/stage it, restore configuration, start with empty sessions and fresh
runtime availability, then validate authentication, authorization, audit
logging, database connectivity, API/Parser health, and representative reads and
writes. Restore SQL Server independently when database recovery is required.

Production validation must still exercise IIS/NTFS and Nginx/PHP-FPM/POSIX
identities, browser download/file-picker behavior, key custody and rotation,
off-host transfer, interrupted operations, full host rebuild, and an isolated
end-to-end restore. A backup is proven only after a successful restore exercise.
