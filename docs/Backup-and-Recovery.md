# Backup and recovery

Phase 4.14 provides one application-configuration backup system shared by the
Admin Console and CLI. It extends `ApplicationBackupManager`; it is not a SQL
Server backup engine, scheduler, cloud-sync client, or arbitrary file archiver.

> **Application Backup is not SQL Server Database Backup.** Use SQL
> Server-native full, differential, and transaction-log backups for database
> data and test those restores independently.

## Scope and ZIP format

The official artifact is `backup-<recovery-point-id>.zip` (automatic safeguards
use `backup-pre-change-<recovery-point-id>.zip`). The version-2 ZIP has exactly:

```text
manifest.json
signature.json
config/auth.json
config/installation.json
config/admin.json
config/authorization.json
config/api-keys.json
database/config/database.json
```

The database document must remain an AES-256-GCM encrypted envelope. Password
hashes and API-key secret hashes are configuration state, but plaintext
passwords and one-time raw API-key secrets are never stored and therefore cannot
enter a backup. The manifest contains the recovery-point ID, UTC creation time,
application and format versions, application-configuration scope, logical file
paths, schema versions, byte sizes, and SHA-256 checksums. It contains no
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

Confirmed restore re-verifies the upload, creates a verified pre-change recovery
point, validates every staged value, and activates configuration under the
shared lock using the existing atomic per-document writer. If any activation or
post-restore configuration/encryption health check fails, the prior complete
configuration set is rewritten and the operation reports failure. Sessions,
runtime state, logs, and SQL Server data are untouched.

Recovery-point history is file-backed and exposes only ID, filename, timestamp,
size, application/format version, pre-change status, and verification and
authenticity results. No database or scheduler is introduced. Uploaded restore
files are short-lived and cleaned after restore/failure or expiry.

## Automatic pre-change recovery points

Critical user/authentication, authorization, API-key, database, and Admin
runtime/security configuration writers invoke the shared pre-change service.
Once installation is initialized and the full recoverable allowlist exists, the mutation proceeds only after a
verified ZIP is finalized. Failure blocks the mutation. API-key `lastUsedAt`
telemetry and password-hash rehash maintenance are not policy changes and do not
create recovery points. A re-entrancy guard prevents recursive backup creation.

The shared lock serializes backup/restore operations; existing configuration
locks serialize their mutations. Temporary names are never reported as recovery
points, so interrupted creation/restore cannot expose a completed artifact.

## CLI

All paths must be absolute and outside the application root:

```text
php scripts/application-backup.php create <absolute-new-backup.zip>
php scripts/application-backup.php verify <absolute-backup.zip>
php scripts/application-backup.php stage-restore <absolute-backup.zip> <absolute-new-target>
```

`create` produces and re-verifies a ZIP. `verify` modifies nothing.
`stage-restore` consumes the ZIP directly and copies only the verified allowlist,
manifest, and signature into a new external staging directory. Manual extraction
is neither required nor supported.

## Audit events

Structured JSONL audit records include request ID and safe actor context where
available. Events include `backup.created`, `backup.verified`, `backup.failed`,
`backup.pre_change`, `restore.previewed`, `restore.started`,
`restore.completed`, and `restore.failed`. Records may contain operation,
outcome, reason, and recovery-point ID, but never archives, full configuration,
paths, passwords, keys, raw API keys, decrypted credentials, or sessions.
The separate Admin operational log records the detailed preview, confirmation,
activation, and health-check lifecycle for troubleshooting.

## Storage, retention, and operations

Set `GENERIC_BACKUP_DIR` to a protected path outside every document root
and repository. The default is an adjacent `backups` directory for local use.
The framework deliberately has no cloud synchronization, automatic retention
scheduler, or remote deletion feature. Copy verified ZIPs to access-separated
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
