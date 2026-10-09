# Upgrading to V3

This guide moves an installation from v2.1.0 to v3.0.0. V3 remains Microsoft
SQL Server only; it adds several servers and databases behind logical
database ids. Read it completely before upgrading production, and rehearse the
upgrade on a test installation first.

## What changes

- **Database registry.** Database settings move from
  `database/config/database.json` (one server and database) to
  `database/config/databases.json`: **server profiles** (connection,
  credentials, TLS options, login timeout) and **databases** (a logical id, a
  display name, the server profile, and the SQL Server database name, called
  the catalog). One database is the **default**. Each entry is encrypted
  separately with the same `GENERIC_SQL_API_ENCRYPTION_KEY`. See
  [Database configuration](Database-Configuration.md).
- **Logical database selection.** SELECT, set-operation, metadata, routine,
  write, and SQL Resource requests may name a registered database with
  `"database": "<id>"`. Requests without it use the default database and
  behave as in V2. Physical database names, servers, and credentials are never
  accepted from clients. See
  [JSON request reference](JSON-Request-Reference.md#database-selection).
- **Cross-database SELECT.** Databases of one server profile can be combined
  in one SELECT, set operation, or SQL Resource, executed as one statement on
  one connection. Databases of different server profiles cannot
  (`CROSS_SERVER_QUERY_NOT_SUPPORTED`); there are no linked servers. Azure SQL
  Database and Synapse dedicated pools reject cross-database queries
  (`CROSS_DATABASE_QUERY_NOT_SUPPORTED`).
- **Writes and routines** target exactly one database. There are no
  multi-target writes or distributed transactions.
- **Admin Console.** A **Databases** page manages server profiles and
  databases; System Health shows each server and database. The
  `admin.database.*` actions keep managing the default database. (Releases
  after 3.0.0 remove the Configuration → Database tab; the **Databases** page
  is the only database UI.)
- **Backups** use format 4, which contains `databases.json`. V2 recovery points
  (formats 2 and 3) can still be verified and restored; restoring one converts
  it into a registry.
- **Authorization is unchanged.** There is no per-database permission: a
  principal's role permissions apply to every registered database, and the
  database login's grants remain the data boundary.

## Compatibility changes

Check these before upgrading:

1. **SQL Resources with literal database names are rejected.** A resource may
   no longer contain names of three or more parts — `OtherDb.dbo.Table`,
   `[OtherDb].[dbo].[Table]`, `OtherDb..Table`, four-part linked-server names,
   or three-part column references such as `dbo.Customer.Id` (use an alias) —
   nor `OPENQUERY`, `OPENROWSET`, or `OPENDATASOURCE`. Address another
   registered database with a placeholder:
   `FROM {{database:inventory}}.dbo.Product`. See
   [SQL Resource authoring](SQL-Resource-Authoring.md#database-placeholders).
   Find affected files before upgrading, for example by searching `queries/`
   for `..`, `].[`, `OPENQUERY`, and names with two dots.
2. **A resource with placeholders runs on its first placeholder's database**:
   the connection opens there, and objects without a placeholder resolve
   there.
3. **A request `database` is rejected for a resource with placeholders**
   (`400 INVALID_REQUEST`); only placeholder-free resources can be pointed at
   a database by the request.
4. **SELECT source names are single identifiers.** `source.table` such as
   `dbo.Items` (always rejected by the V2 catalog check at run time) is now
   rejected during validation; use `"schema": "dbo"`. Aliases must be single
   identifiers.
5. **Error responses.** Some failures that V2 reported as `500 INTERNAL_ERROR`
   now have specific codes, for example `503 DATABASE_CONNECTION_FAILED`,
   `504 DATABASE_CONNECTION_TIMEOUT`, and `422 COLLATION_CONFLICT`. See
   [Errors and validation](Errors-and-Validation.md).
6. **Rate-limit state** can be relocated with `GENERIC_SECURITY_STORAGE_DIR`
   (default unchanged: `storage/security`).

Request shapes, action names, the write `table` field, and response envelopes
are otherwise unchanged.

## Before upgrading

1. **Preserve the encryption key.** The V3 registry is encrypted with the
   existing `GENERIC_SQL_API_ENCRYPTION_KEY`. Keep the worker's key exactly as
   it is; a new key cannot read the existing configuration. Never copy
   production keys or configuration into development or test environments.
2. **Create an application backup with V2** (Backup & Recovery → Create) and
   download it. It contains the V2 `database.json` and is the only way to roll
   back to V2 (see [Rolling back](#rolling-back)). Confirm SQL Server backups
   are current; application backups never contain database data.
3. **Check SQL Resources** against [Compatibility changes](#compatibility-changes).
4. **Rehearse on a test installation** with its own key, its own SQL Server
   test database, and a copy of the resources. SQL Server behavior (cross-
   database queries, collations, permissions) must be verified against a real
   SQL Server; the repository's test suite does not connect to one.

## Upgrade

1. Deploy the V3 code as for any update (on IIS, [Windows Server IIS
   deployment, section 22.1](Windows-IIS-Deployment.md#221-update-procedure)),
   keeping `database/config/`, the runtime configuration directory,
   `runtime/secrets/`, `logs/`, `storage/`, and backups.
2. Run `scripts/bootstrap-runtime-configuration.php` (with
   `GENERIC_RUNTIME_CONFIG_DIR` set as for the workers). It adds missing
   configuration without overwriting existing values; the database
   availability state is upgraded to its V3 format on its next change.
3. Recycle the workers. The V2 `database.json` keeps working: it is read as the
   `default` server profile and the `default` database, and requests behave as
   before.
4. **Migrate the database configuration** using one of:
   - the Admin Console: **Databases → Servers**, **Edit** the `default`
     server profile, leave the password blank, **Save**. The worker encrypts
     the registry with its own key. This is the recommended way on IIS;
   - `php scripts/migrate-database-registry.php` with the key already in the
     environment of the account running it (not on its command line), and
     write access to `database/config/`.

   Migration writes `databases.json`, verifies that it decrypts to exactly the
   V2 configuration, and only then removes `database.json`. If anything fails
   (missing or wrong key, invalid V2 configuration, unwritable directory),
   `database.json` is left unchanged and no registry is written. Running it
   again is harmless. If both files exist, the registry is authoritative and
   `database.json` is ignored.
5. Optionally rename the `default` server profile and database on the
   **Databases** page (their ids stay `default`) and add further servers and
   databases. The first database ever created is the default; change it with
   **Set Default**.
6. Create a V3 application backup (format 4).

## Verify

With a test client and a non-production principal:

- `GET /health/live` returns `200`; `GET /health/ready` returns `200` once the
  default database is connected (System Health → Database → Connect).
- The **Databases** page lists the server profile and database; **Test
  Connection** succeeds for both; System Health's Databases section shows them
  healthy.
- `{"action":"metadata.databases"}` lists the logical ids (no physical names).
- A V2-shaped `select`, a `metadata.columns`, a routine call, a SQL Resource,
  and a write on a test table behave as before; repeat them with
  `"database": "<id>"` for each added database.
- A same-profile cross-database SELECT works on the target SQL Server edition
  and fails with `CROSS_DATABASE_QUERY_NOT_SUPPORTED` on Azure SQL Database.

## Rolling back

There is no automatic rollback.

- **Back to the pre-upgrade V3 configuration:** restore a V3 application backup
  (Backup & Recovery), which replaces the registry and runtime configuration
  and rolls itself back if its health check fails.
- **Back to V2:** V2 cannot read `databases.json`, and migration removed
  `database.json`. Reinstall the V2 code, then restore the application backup
  created with V2 before the upgrade; it restores `database.json`. Servers,
  databases, and other configuration changed after the upgrade are not part of
  it. Keep the encryption key unchanged.
