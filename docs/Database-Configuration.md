# Database Configuration

System Health manages a runtime availability gate, not a permanent SQL connection, and never starts or stops SQL Server. There is no connection pool: every database request opens and closes its own ODBC connection.

- **Connect** decrypts the saved configuration, opens one test connection, closes it, and only then enables new database requests.
- **Disconnect** denies new database requests (`503 DATABASE_UNAVAILABLE`) without opening a connection and without changing the registry, its encrypted credentials, or API/SQL Parser availability.
- **Restart** disables, retests, and re-enables only on success.
- A failed Connect or Restart leaves access disabled and returns `DATABASE_CONNECTION_FAILED`, or `DATABASE_CONFIGURATION_UNAVAILABLE` with a safe `reason` of `configuration_missing`, `encryption_key_missing`, or `configuration_invalid`.
- **Test Connection** on the **Databases** page tests a saved server profile (its `master` catalog, also while the profile is disabled) or a saved, enabled database through a temporary request-scoped connection, without changing the registry or availability. To check new settings before they serve requests, save the server profile disabled, test it, then enable it.

System Health reports the resulting state as `disabled`, `connected`, or `unhealthy` (enabled but failing its check); see [Monitoring and Health](Monitoring-and-Health.md).

Multiple databases are managed on the Admin Console's **Databases** page:
server profiles hold connection settings and encrypted credentials, and each
database context names its profile and SQL Server database. See
[Admin Console](Admin-Console.md#server-profiles-and-databases). The settings
below describe a server profile's connection. (The former Configuration →
Database tab was removed; its `admin.database.*` actions remain for the
default database.)

## Configuration file

Runtime database settings come from the ignored local database registry:

```text
database/config/databases.json
```

```json
{
  "version": 2,
  "defaultDatabase": "company",
  "servers": {
    "sql01": { "name": "SQL Server 01", "enabled": true, "connection": { "...": "encrypted envelope" } }
  },
  "databases": {
    "company": { "name": "Company", "server": "sql01", "enabled": true, "catalog": { "...": "encrypted envelope" } }
  }
}
```

Ids, display names, flags, and server references are plaintext so the
registry can be listed without the key. Each server's `connection` (the fields
below, without `database`) and each database's `catalog` (its SQL Server
database name) is a separate AES-256-GCM envelope (`version: 2`) whose
additional authenticated data binds it to its own id, so entries cannot be
swapped or renamed. The default database must exist and stay enabled on an
enabled server profile. Manage the registry through the Admin Console
(**Databases** page);
never edit it by hand.

A V2 `database/config/database.json` (plaintext or a `version: 1` envelope) is
still read until it is migrated: it serves as the `default` server profile and
`default` database. The first registry write (an Admin save) or
`php scripts/migrate-database-registry.php` migrates it, verifies the result,
and removes the V2 file. See [Upgrading to V3](Upgrading-to-V3.md).

Leaving the password field blank while editing retains an existing password;
passwords are never returned to the browser. Saving a new configuration requires
a password for SQL authentication. Windows integrated authentication stores no
required password and is available only when the backend runs on Windows.

## Connection fields

These are the fields of a server profile's connection (and of the V2
`database.json` plaintext form, retained only as migration input; `database`
becomes the database context's catalog):

| Field | Type | Required | Behavior |
|---|---|---:|---|
| `provider` | string | yes | Currently `sqlserver` |
| `driver` | string | no | `auto`, or an exact installed ODBC driver name |
| `server` | string | yes | Host, instance, or server address |
| `port` | number/string | no | Appended as `server,port` when supplied |
| `database` | string | yes | Database name |
| `authentication` | string | no | `sql` (default); `windows` only on Windows |
| `username` | string | SQL auth | Empty string if omitted |
| `password` | string | SQL auth | Empty string if omitted |
| `options.encrypt` | boolean-like | no | false (`Encrypt=no`) |
| `options.trustServerCertificate` | boolean-like | no | false (`TrustServerCertificate=no`) |
| `options.loginTimeoutSeconds` | integer 1-65534 | no | 15, the driver default (`ConnectTimeout=`) |

See `database/config/database.example.json` for a placeholder-only example. The earlier password-only encrypted object remains readable so existing deployments can migrate, but new deployments should encrypt the complete configuration.

## Encryption

Each registry entry is an envelope of this form (V2 `database.json` files use
the same shape with `"version": 1` and no binding):

```json
{
  "encrypted": true,
  "version": 1,
  "algorithm": "AES-256-GCM",
  "nonce": "<base64 nonce>",
  "ciphertext": "<base64 ciphertext>",
  "tag": "<base64 authentication tag>"
}
```

The ciphertext is the serialized complete configuration. Provider, driver, server/IP, port, database, authentication mode, username, password, connection options, and any other properties do not appear outside the authenticated ciphertext. Every encryption uses a fresh random 12-byte nonce and a 16-byte authentication tag.

The key is a random 32-byte value represented in Base64 and supplied separately through `GENERIC_SQL_API_ENCRYPTION_KEY`. It must never be stored in `database.json`, source control, logs, or command output retained as an ordinary file.

On production hosts, inject the key into the IIS FastCGI application-pool or
PHP-FPM pool/service environment. On IIS it belongs on the API and Admin FastCGI
registrations; [Windows Server IIS deployment](Windows-IIS-Deployment.md#113-create-and-install-the-database-encryption-key)
shows how to generate it without displaying it and install it through IIS
Manager. Do not add it to `web.config`, an Nginx server
block, a launcher command line, a repository `.env`, or a file below a served
document root. Environment access is not a complete secret boundary: an
administrator or process with permission to inspect the PHP worker can recover
the key, so restrict service-account, process-debugging, and configuration-file
access at the operating-system layer.

Runtime resolution is centralized:

```text
databases.json
  -> validate the registry structure (no key needed)
  -> read external key
  -> authenticate and decrypt the server profile's and database's envelopes
  -> DatabaseContext
  -> existing driver validation and connection path
```

Authentication, malformed-payload, wrong-key, version, and algorithm failures stop before a connection is attempted. Fixed error messages omit decrypted configuration, connection strings, encrypted internals, and key material.

## Admin-managed encryption and migration

For local development, run `start-windows.bat` or `./start-linux.sh`, open the
Database page, optionally test the plaintext settings, and save. This safely
migrates the current values through the same resolver and encryption component.
No `database.json.backup` is created. The launcher prepares an ignored local key
when necessary; Admin Console validates the submitted values, encrypts the whole
configuration, verifies the encrypted round trip, and atomically saves it.
Production hosts provide `GENERIC_SQL_API_ENCRYPTION_KEY` from their
secret manager before starting the application.

Database configuration reads participate in the same adjacent-file shared lock
used by atomic configuration writes. New temporary and destination files are
restricted before encrypted bytes are written (`0600` on POSIX; the deployment
identity and NTFS ACL remain authoritative on Windows). Failed writes remove
temporary files, and migration never creates a plaintext backup. Treat manual
`*.bak`, `*.backup`, `*.old`, and `*.tmp` copies as credentials: keep them out of
web roots and source control and delete them securely according to local policy.

## Windows authentication

The decrypted configuration may use `authentication: "windows"` only on
Windows. In that mode the driver adds `Trusted_Connection=yes` and supplies empty
ODBC credentials; the PHP process account must have SQL Server access. Under IIS
that is the application-pool identity (`IIS APPPOOL\<pool>` for a local SQL
Server, or the web server's computer account `DOMAIN\HOST$` for a remote one);
see [Windows Server IIS deployment](Windows-IIS-Deployment.md#16-prepare-sql-server). Linux
Admin responses expose only SQL authentication, crafted Admin requests selecting
Windows authentication are rejected, and the driver enforces the same boundary.

## ODBC and validation

The host needs PHP's `odbc` extension and a compatible SQL Server ODBC driver.
With `driver: "auto"`, the framework tests drivers newest-first and keeps the
first successful connection. In production, automatic selection uses only
ODBC Driver 18 and 17; development keeps the full supported list. A TLS or
certificate-validation failure is never retried with another driver. With an
explicit driver, only that driver is attempted. In production, System Health
(database card `warnings`) and `scripts/validate-production.php`
(`databaseTransport`) report `encrypt_disabled`,
`trust_server_certificate_enabled`, and `legacy_driver_configured`. Windows retains the ODBC cursor library used by the bundled
runtime; unixODBC platforms use the SQL Server driver's cursor implementation so
statement execution is not rejected with capability error `IM001`.

Use **Test Connection** in Admin Console for the supported workflow. It tests
the submitted form values (reusing the stored password when the password field
is left blank), validates platform compatibility, opens one temporary
connection, and closes it in `finally`. **Connect** performs the same check
against the saved encrypted configuration. `scripts/check-database.php`
remains a developer/deployment diagnostic, not a setup requirement; run from a
shell it uses that shell's identity and environment, not the IIS worker's.

## Security and troubleshooting

- Keep `GENERIC_SQL_API_ENCRYPTION_KEY` separate from the encrypted file and restrict access to both.
- Never commit `databases.json`, `database.json`, plaintext configuration copies, or encryption keys.
- A missing-key error means the PHP worker does not see `GENERIC_SQL_API_ENCRYPTION_KEY`.
- An invalid-key error means the environment value is not strict Base64 for exactly 32 bytes.
- A decryption failure means authentication failed, the payload is malformed, or the key/payload pair does not match.
- Unsupported version/algorithm errors require migration to version `1` and `AES-256-GCM`.
- OpenSSL must be enabled in CLI and hosted PHP runtimes.

Encryption protects all stored connection settings when the configuration file alone is disclosed. A process or operator able to read both the key and ciphertext can decrypt it, because the application must recover the configuration in memory to connect.

## Secret rotation

To rotate the SQL login password, edit the server profile on the **Databases**
page over the loopback-protected Admin Console, enter the new password, save,
and **Test Connection**. The
save creates a fresh nonce and replaces the complete encrypted envelope. Then
restart or reconnect database runtime access and revoke the prior SQL password
after validation.

There is no automatic encryption-key rotation and no re-encryption tool. Every
registry entry is sealed separately, so rotating the key means re-entering
configuration under the new key: create an Admin backup, provision the new
Base64-encoded 32-byte key to every worker and recycle them, then on the
**Databases** page re-save every server profile (entering its password again)
and every database (entering its catalog again). Entries not yet re-saved are
unreadable (`readable: false`) and their databases fail with
`DATABASE_CONFIGURATION_ERROR`, so plan a maintenance window and verify
connection health before destroying the old key. Losing the old key before
re-entry makes the existing ciphertext unrecoverable. Keep an approved,
access-controlled recovery copy of the matching key and encrypted
configuration; never back up plaintext configuration.

A V2 plaintext configuration and the former password-only encrypted format
remain readable until migration. Files are not migrated merely by loading
them; migrate explicitly (see [Upgrading to V3](Upgrading-to-V3.md)) so
plaintext does not persist indefinitely.

The query timeout is separate application configuration: the runtime setting
`query.timeoutSeconds` (default 45 seconds, overridable by
`DB_QUERY_TIMEOUT_SECONDS`) is requested when the ODBC driver supports
`SQL_QUERY_TIMEOUT`; unsupported drivers fall back to the PHP
execution limit. It does not control the login, HTTP proxy, or browser timeouts.

The login timeout is `options.loginTimeoutSeconds` (Admin Console: **Login
timeout**), default 15 seconds. It is sent as the `ConnectTimeout` keyword,
which ODBC Driver 18.7 and later honor; older drivers ignore the keyword and
keep their own 15-second default. A login that times out returns
`504 DATABASE_CONNECTION_TIMEOUT`; other connection failures return
`503 DATABASE_CONNECTION_FAILED` or `503 DATABASE_AUTHENTICATION_FAILED`.
