# Admin Console

The Admin Console is the backend's administration application: first-run setup,
database configuration, users, roles, API keys, security and runtime settings,
System Health, availability controls, and application backups. It is served by
`admin/index.php` and its loopback-only API `admin/api.php`.

## Access boundary

- Reachable only from loopback, and only when the Admin worker has
  `GENERIC_ADMIN_ENABLED=1` (`LocalAdminMiddleware`). The development launchers
  set this; in production it is set only on the Admin FastCGI registration or
  Nginx location. Otherwise gated actions return `404`.
- Every gated action requires a System Administrator session (`admin.manage`).
  Mutations also require the session-bound `X-CSRF-Token`.
- API keys never authenticate Admin actions, and the normal API's
  authentication mode does not apply here.
- The public API rejects every `admin.*` action, `setup.createAdmin`,
  `auth.users.*`, `auth.apiKeys.*`, and `auth.roles.list` with `404`.
- The Admin API has no application-runtime or database-availability gate, so it
  remains usable when the API, SQL Parser, or database access is disabled.

The full security boundary is in
[Security model](security/Security-Model.md#request-surfaces).

The console derives its mount path from `SCRIPT_NAME`, so it works at a site
root, as an IIS application such as `/admin`, or under the Nginx example's
`/admin/` prefix. Behind a reverse proxy that strips a public prefix, set
`GENERIC_ADMIN_BASE_PATH` on the Admin worker. Host and forwarded headers are
never used to build Admin URLs.

## Hosting modes

| | Development (`start-windows.bat`, `start-linux.sh`) | Production (`GENERIC_APP_ENV=production`) |
|---|---|---|
| API and SQL Parser processes | Started and stopped by the application's local process managers (`php -S`) | Owned by IIS/FastCGI or Nginx/PHP-FPM |
| Start / Stop / Restart | Real process lifecycle; System Health shows PID, port, and start time | Labeled Enable / Disable / Reload; changes only application availability; no PID, port, or start time is reported |
| Disabled runtime | Process stopped | Execution requests return `503 SERVICE_UNAVAILABLE`; liveness and Admin stay reachable |
| Configuration → Server tab | Present (launcher port ranges, Admin port, loopback bind address) | Absent; requests for it open Database; `admin.server.save` returns `409 SERVER_CONFIGURATION_DEPLOYMENT_MANAGED` |

No Admin action invokes IIS, Nginx, FastCGI, PHP-FPM, systemd, a Windows
service, or SQL Server, and browser requests can select only fixed operations;
they cannot supply commands, paths, executables, or arguments.

Production availability is stored in `application-runtime-state.json` in the
runtime configuration directory. It is written atomically, serialized across
workers, survives restarts, starts enabled when first created, contains no
secrets, and is excluded from backups as host-specific state.

## Pages

- **System Health** is the runtime control plane. Cards: Admin Console, API
  Server, SQL Parser, Database, PHP Runtime, Configuration, Logging, Encryption,
  and Backup. Cards show status only; API, SQL Parser, and database lifecycle
  controls are in the separate Service Actions section. See
  [Monitoring and health](Monitoring-and-Health.md).
- **System Info** shows application, status, platform, PHP runtime,
  configuration and database status, and Admin/API/SQL Parser service status.
- **Databases** manages the database registry
  (`database/config/databases.json`), in two tabs. See
  [Server profiles and databases](#server-profiles-and-databases).
- **Configuration** has Server (development only), Database, Security, Runtime &
  Performance, and Advanced tabs.
  - **Database** edits, tests, and saves the default database's SQL Server
    settings (the `admin.database.*` actions). Test Connection uses the
    submitted form values and does not save them or change availability. See
    [Database configuration](Database-Configuration.md).
  - **Security** holds the API authentication mode, CORS origins, and session
    and CSRF information.
  - **Runtime & Performance** holds query timeout, rate limits, session
    expiration, request size, and pagination limits. See
    [Runtime and performance controls](Runtime-and-Performance-Controls.md).
  - **Advanced** shows read-only logging and debug-mode information.
- **Users** manages username, password, enabled state, backend role, frontend
  access, and frontend role. Hashes, sessions, and secrets are never shown.
- **Roles & Permissions** describes the fixed roles.
- **API Keys** creates and manages one-time-reveal keys.
- **Backup & Recovery** creates, verifies, downloads, previews, and restores
  application configuration recovery points. See
  [Backup and recovery](Backup-and-Recovery.md).

The console labels `system-administrator` as "Super Admin" and both
`application-administrator` and the API-key role `api-administrator` as
"Admin". See [Authentication and authorization](Authentication-and-Authorization.md).

## Server profiles and databases

The **Databases** page reads and changes only the registry; nothing is stored
elsewhere.

- **Servers**: a server profile is one SQL Server connection boundary with its
  encrypted credentials (driver, host, port, authentication, username,
  password, TLS options, login timeout). The list shows each profile's host,
  enabled state, login timeout, and its databases. Add/Edit leave the stored
  password in place when the field is blank. **Test Connection** connects to
  the profile's `master` catalog (no database has to be chosen, and disabled
  profiles can be tested) and reports SQL Server version, edition, and server
  name. A profile that still hosts databases cannot be deleted
  (`409 SERVER_PROFILE_IN_USE`), and the profile of the default database
  cannot be disabled.
- **Databases**: a database context is a logical id (what clients send as
  `database`), a display name, its server profile, the SQL Server database
  (catalog), and an enabled flag. Actions are Add, Edit, Enable/Disable, Set
  Default, Test Connection, Connect/Disconnect (its availability gate), and
  Delete.
- The default database must exist and stay enabled on an enabled profile: it
  cannot be disabled, deleted, or moved to a disabled profile until another
  default is chosen, and the first database created becomes the default
  (`409 DEFAULT_DATABASE_REQUIRED`).
- **Enabled** is configuration; **available** is the runtime gate opened by a
  verified connection. A failed test or connection never changes the registry,
  the default, or credentials, and never falls back to another database.
- Responses report a password only as `passwordConfigured`; connection
  strings, encrypted envelopes, and the encryption key are never returned.

## Database availability

Database controls operate an application access gate, never the SQL Server
service.

- **Connect** resolves the saved (encrypted) configuration, opens one test
  connection, closes it, and only then enables access. Requests continue to
  open their own request-scoped connections.
- **Disconnect** only disables access: database-dependent API requests return
  `503 DATABASE_UNAVAILABLE`; the registry, credentials, and API/SQL Parser
  availability are unchanged.
- **Restart** reconnects.
- A failed Connect or Restart leaves access disabled and returns
  `DATABASE_CONNECTION_FAILED`, `DATABASE_CONNECTION_TIMEOUT` when the login
  timeout expired, or `DATABASE_CONFIGURATION_UNAVAILABLE` with
  `reason` `configuration_missing`, `encryption_key_missing`, or
  `configuration_invalid`.

The `admin.database.*` actions keep managing the default database. Each
database has its own gate; the Databases page connects or disconnects one
database by id.

System Health reports the database `state` as `disabled` (reason
`application_access_disabled`), `connected`, or `unhealthy` with a safe
`reason`. It shows only the configured server, explicit port, and database
name.

## Configuration storage

The Admin configuration (`admin.json`, schema version 6) holds server (launcher)
settings, CORS, authentication mode, runtime settings, and the backup schedule.
Older schema versions migrate in place. Runtime settings apply to new requests
immediately without a restart. There is no global feature-toggle configuration:
reads, writes, metadata, SQL Resources, and routines are governed by
authorization.

Runtime configuration files live in the directory named by
`GENERIC_RUNTIME_CONFIG_DIR` (production: outside the code tree; development
launchers: `Backend/config`). They are created from built-in defaults on first
use and never overwritten. Development process state lives under `runtime/`.

## Admin API actions

All actions are `POST` JSON requests to `admin/api.php`. "Gate" means loopback,
`GENERIC_ADMIN_ENABLED=1`, a session, and `admin.manage`.

| Action | Purpose | Access | CSRF |
|---|---|---|:-:|
| `setup.status` | Whether the installation is initialized | Public | no |
| `auth.csrf`, `auth.session` | CSRF token; current session state | Public | no |
| `auth.login`, `auth.logout` | Session login and logout | Public | yes |
| `setup.createAdmin` | Create the first System Administrator; `409` once initialized | Gate (no session required) | yes |
| `auth.users.list` | List identities and assignments | Gate | no |
| `auth.users.create`, `.update`, `.changePassword`, `.enable`, `.disable`, `.delete`, `.assignAuthorization` | Manage identities | Gate | yes |
| `auth.apiKeys.list` | List key metadata | Gate | no |
| `auth.apiKeys.create`, `.enable`, `.disable`, `.revoke` | Manage API keys | Gate | yes |
| `auth.roles.list` | List fixed roles | Gate | no |
| `admin.health` (alias `admin.status`) | Detailed System Health | Gate | no |
| `admin.system.info` | System information | Gate | no |
| `admin.settings.get` | Redacted configuration (`server: null` in production) | Gate | no |
| `admin.database.get` | Safe database configuration | Gate | no |
| `admin.servers.list`, `admin.databases.list` | Server profiles; database contexts | Gate | no |
| `admin.databases.health` | Server profiles and their databases, each with its own health | Gate | no |
| `admin.backup.history`, `admin.backup.schedule` | Recovery points; schedule information | Gate | no |
| `admin.console.restart` | Revalidate Admin configuration and clear application bytecode caches | Gate | yes |
| `admin.api.start`, `.stop`, `.restart` | API process lifecycle (development) or Enable/Disable/Reload (production) | Gate | yes |
| `admin.sqlParser.start`, `.stop`, `.restart` | SQL Parser lifecycle, as above | Gate | yes |
| `admin.database.test`, `.save` | Test submitted values; save configuration | Gate | yes |
| `admin.database.connect`, `.disconnect`, `.restart` | Database availability gate | Gate | yes |
| `admin.servers.save` (`server`), `.enable`, `.disable`, `.delete`, `.test` (`id`) | Manage and test server profiles | Gate | yes |
| `admin.databases.save` (`database`), `.enable`, `.disable`, `.default`, `.delete`, `.test`, `.connect`, `.disconnect` (`id`) | Manage, test, and gate databases | Gate | yes |
| `admin.server.save` | Launcher ports and bind address (development only) | Gate | yes |
| `admin.cors.save` | Exact CORS origins | Gate | yes |
| `admin.authentication.save` | API mode: `none`, `session`, `api_key`, `session+api_key` | Gate | yes |
| `admin.runtime.save` | Runtime and performance settings | Gate | yes |
| `admin.backup.create`, `.download`, `.preview`, `.restore` | Create, download, preview, and restore recovery points | Gate | yes |
| `admin.backup.schedule.save` | Backup schedule | Gate | yes |
| `admin.operational.event` | Record an allowlisted Admin UI restore or JavaScript failure event in the operational log | Gate | yes |

Any other action returns `404`.
