# Generic SQL API Framework

Generic SQL API Framework is a PHP backend that converts validated JSON requests
into SQL Server operations over ODBC. It provides a stable API for structured
queries, reviewed SQL reports, CRUD, routines, and metadata without accepting raw
SQL from clients or requiring a controller for every resource.

The last released version is **v1.0.0**. The current repository contains the
**v2.0.0 development line, which is unreleased**. See [CHANGELOG.md](CHANGELOG.md)
for release history and [Roadmap.md](docs/Roadmap.md) for future work.

## Capabilities

- JSON Query Mode for validated SELECT, joins, grouping, HAVING, sorting,
  pagination, CTEs, set operations, windows, CASE, arithmetic, and allow-listed
  SQL Server functions.
- SQL Resource Mode for recursively discovered, server-owned `.sql` files with
  validated execution metadata, filters, sorting, and pagination.
- Deny-by-default write resources for single-object INSERT, UPDATE, DELETE, and
  SQL Server UPSERT operations.
- Stored procedures, scalar functions, table-valued functions, and database
  metadata actions.
- Configurable `none`, `session`, `api_key`, and `session+api_key` authentication.
- Fixed backend/frontend roles, resource scopes, managed API keys, administrator
  user management, CSRF protection, and request/login rate limits.
- Encrypted database configuration, audit/security logging, backup verification,
  health/readiness reporting, runtime controls, and production-safe errors.
- A non-executing SQL-to-Universal-JSON parser for supported SQL shapes.
- IIS/FastCGI and Nginx/PHP-FPM production deployment templates.

Microsoft SQL Server through ODBC is the only supported database provider.
Driver stubs for other databases are not selectable production implementations.

## HTTP surfaces

| Surface | Entry point | Purpose | Boundary |
| --- | --- | --- | --- |
| Application API | `api/index.php` | Query, SQL resource, CRUD, routine, metadata, and application auth actions | Configured normal API authentication and authorization |
| Public health | `api/health.php` | Lightweight liveness/readiness | Deliberately minimal public response |
| Admin Console/API | `admin/index.php`, `admin/api.php` | Setup, configuration, users, keys, health, and local lifecycle | Loopback plus System Administrator session; mutations require CSRF |
| SQL parser | `sqlparser/index.php` | Convert supported SQL to request JSON without execution | Separate local/deployment boundary; no database access |

Send API requests as `POST` with `Content-Type: application/json`. When API Key
authentication is selected, send the managed secret only in:

```http
X-API-Key: gsk_...
```

Do not send API keys as bearer tokens. Administrator endpoints always require an
administrator session regardless of the normal API authentication mode.

## Action model

The public data actions are:

- `select`, `union`, and `unionAll` for structured reads;
- `sql` for reviewed SQL resources;
- `insert`, `update`, `delete`, and `upsert` for registered write resources;
- `procedure`, `function`, and `tableFunction` for routines;
- `metadata.tables`, `metadata.columns`, `metadata.views`,
  `metadata.procedures`, and `metadata.schema` for metadata.

A minimal structured query is:

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": ["I.ItemCode", "I.Description"],
  "filters": [
    { "field": "I.Active", "operator": "=", "value": 1 }
  ],
  "sort": [{ "field": "I.ItemCode", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 50 }
}
```

Clients submit only the documented public contract. Validation and normalization
produce a private execution model before controller, service, repository, and
builder code runs. Runtime values remain prepared parameters.

`sql` is not a raw-SQL endpoint. It resolves an identifier to a reviewed file
under `queries/`; `queries/system/` is excluded from public discovery and is used
internally for metadata. Optional execution metadata in
`config/sql-resources.php` defines approved frontend controls without accepting
SQL fragments.

Writes are separately allowlisted in `config/write-resources.php`. Deployments
must explicitly declare tables, actions, writable/filterable columns, UPSERT
keys, and optional identity columns. The repository includes the explicit
`crud-test` mapping for `dbo.ApiCrudTest`; replace or remove it for deployments
that do not provide that test table. Every unregistered resource is denied.

See [API.md](docs/API.md), [Action Reference](docs/Action-Reference.md), and the
[Capability Matrix](docs/Capability-Matrix.md) for the exact contract.

## Architecture

```text
HTTP entry point
  -> authentication, throttling, authorization, CSRF, logging, availability
  -> request validator
  -> request normalizer
  -> controller -> service -> repository
  -> query/write builders -> QueryEngine -> Database/ODBC -> SQL Server
  -> Response / ExceptionHandler
```

Builders validate identifiers and assemble SQL; repositories orchestrate
metadata and execution; `QueryEngine` owns prepared ODBC execution and cleanup.
SQL Resource Mode follows its own reviewed-file path and does not expand the JSON
Query expression allowlist. See [Architecture.md](docs/Architecture.md).

## Configuration and security

The local Admin Console is the normal setup path. It atomically bootstraps ignored
runtime configuration, manages users and keys, validates database settings, and
stores the complete database configuration in an AES-256-GCM envelope. The
Base64-encoded 32-byte `GENERIC_SQL_API_ENCRYPTION_KEY` remains outside that
envelope and outside version control.

Security boundaries include:

- exact-origin CORS and bounded JSON request bodies;
- host-only Secure/HttpOnly/SameSite session cookies in production;
- session-bound CSRF tokens for browser mutations;
- hash-only, one-time-reveal managed API keys with `gsk_` secrets;
- fixed roles and deny-by-default resource authorization;
- prepared runtime values and allowlisted identifiers/functions;
- correlated, redacted errors and JSON Lines audit/security logs;
- request-scoped ODBC connections and configurable statement timeouts.

Plaintext database configuration remains readable only for compatibility.
Production deployments should use the encrypted form and externally managed key.
Never commit credentials, encryption keys, generated configuration, sessions,
runtime state, backups, or logs.

## Local development

Requirements are PHP 8.2 or newer, JSON, OpenSSL, sessions, ODBC, and a supported
Microsoft SQL Server ODBC driver when connecting to a database.

Windows includes a bundled PHP runtime:

```bat
start-windows.bat
```

Linux prefers `runtime/linux/php/php` when present and otherwise uses `php` from
`PATH`:

```bash
./start-linux.sh
```

Both launchers validate the runtime, bootstrap configuration, prepare an ignored
local encryption key, select the configured loopback Admin port, start the
managed API and SQL Parser, connect application database availability, verify
all three runtime states, and start the Admin Console. A failed component remains
accurately unavailable while the Admin control plane starts for recovery. The
PHP built-in server is for local development only.

For manual production provisioning, copy
`database/config/database.example.json` to the ignored `database.json`, complete
it, and follow [Database Configuration](docs/Database-Configuration.md).

## Testing

The normal suite is database-independent:

```bash
php tests/run.php
php -n tests/run.php
```

Tests use fake executors and metadata repositories; they do not require SQL
Server, ODBC, credentials, or a running service. Live SQL Server validation is
still required for deployment-specific drivers, permissions, constraints,
triggers, execution plans, and concurrency behavior.

Useful static checks:

```bash
find api admin app config core database scripts sqlparser tests -type f -name '*.php' -exec php -l {} \;
node --check admin/assets/admin.js
node --check sqlparser/assets/js/app.js
bash -n start-linux.sh
git diff --check
```

CI runs PHP 8.2 syntax checks and the database-independent suite.

## Operations and deployment

System Health exposes local API/parser lifecycle and database availability plus
safe configuration, filesystem, logging, session, encryption, and backup checks.
The public health route exposes only liveness/readiness fields. The Super Admin
Backup & Recovery page and CLI create, sign, verify, preview, and safely restore
ZIP application-configuration recovery points. They do not back up SQL Server;
database backup remains an operator responsibility.

Production hosting uses IIS with PHP FastCGI on Windows or Nginx with PHP-FPM on
Linux. The production web server owns TLS, redirects, security headers, process
lifecycle, worker concurrency, and sensitive-path denial. Do not expose the Admin
Console or SQL parser publicly, and do not use either development launcher as a
production process manager.

Read [Hosting](docs/Hosting.md),
[Production Security and Deployment](docs/Production-Security-and-Deployment.md),
[Monitoring and Health](docs/Monitoring-and-Health.md), and
[Backup and Recovery](docs/Backup-and-Recovery.md) before deployment.

## Repository structure

| Path | Responsibility |
| --- | --- |
| `api/`, `admin/`, `sqlparser/` | HTTP boundaries and local UIs |
| `app/Controllers`, `app/Services` | Application orchestration |
| `app/Requests`, `app/Middleware` | Contract and security enforcement |
| `app/Repositories`, `app/Resources` | SQL construction, persistence, and resource discovery |
| `app/Security`, `app/Runtime`, `app/Health`, `app/Backup` | Operational and security services |
| `core/`, `database/` | Execution, responses, logging, and SQL Server driver |
| `config/` | Tracked examples/registries and ignored generated configuration |
| `queries/` | Internal metadata SQL and discoverable reviewed SQL resources |
| `deployment/` | IIS, Nginx, PHP-FPM, and production PHP examples |
| `scripts/` | Runtime, backup, validation, and provisioning utilities |
| `tests/` | Standalone database-independent regression suites |
| `docs/` | Detailed API, security, deployment, and maintenance documentation |

Start with the [documentation map](docs/README.md). Maintainers and coding agents
should also read the [AI Development Guide](docs/AI-Development-Guide.md).
