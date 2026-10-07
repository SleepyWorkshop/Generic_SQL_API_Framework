# Generic SQL API Framework

Generic SQL API Framework is a PHP backend that exposes a Microsoft SQL Server
database through one validated JSON API. Clients describe reads, reviewed
reports, writes, routine calls, and metadata lookups as JSON; the backend
validates them against a fixed contract and server-owned allowlists, builds
parameterized SQL, and returns a standard JSON envelope. Clients never send raw
SQL, and no per-resource controller is needed.

It is for teams that build reporting frontends and internal tools on SQL Server
and want one secured, configurable data API instead of hand-written endpoints.
Dashboards and report screens live in separate frontend projects.

## Release status

The latest completed release is **v2.1.0**, the security verification and
operational hardening milestone that followed **v2.0.0** (2026-10-05). The `dev`
branch is the **v2.2** development line (`2.2.0-dev`): generic authorization and
data-access simplification, listed under `[Unreleased]` in
[CHANGELOG.md](CHANGELOG.md). Planned work is in the [Roadmap](docs/Roadmap.md).

## Capabilities

- **JSON Query Mode:** validated SELECT with joins, grouping, HAVING, sorting,
  pagination, CTEs, UNION/UNION ALL, window functions, CASE, arithmetic, and an
  allowlist of SQL Server functions, on any table or view of the configured
  database.
- **SQL Resource Mode:** reviewed `.sql` files under `queries/`, discovered
  automatically and executed by ID with validated runtime filters, sorting, and
  pagination.
- **Write API:** single-object INSERT, UPDATE, DELETE, and UPSERT on any user
  table, validated against live column metadata.
- **Routines and metadata:** stored procedures, scalar and table-valued
  functions called by name, and table, column, view, procedure, and schema
  metadata.
- **Security:** `none`, `session`, `api_key`, or `session+api_key`
  authentication; one role-and-permission authorization path for every caller,
  with no application-specific table or resource registration; managed
  one-time-reveal API keys; CSRF; exact-origin CORS; login and API rate limits;
  encrypted database configuration; audit logging.
- **Operations:** a loopback Admin Console for setup, configuration, users, API
  keys, health, availability, and application backups; liveness and readiness
  probes; production-safe errors with request IDs.
- **SQL Parser:** a separate, non-executing tool that converts supported SQL
  into API request JSON.

**Database support:** Microsoft SQL Server through PHP ODBC is the only
supported provider. Other files in `database/drivers/` are empty stubs.

## Architecture

```text
Client
  → IIS + PHP FastCGI  |  Nginx + PHP-FPM  |  PHP built-in server (development)
  → api/index.php  (public API)    admin/api.php  (loopback Admin)    sqlparser/
  → middleware: availability, authentication, rate limit, CSRF, authorization
  → validator → normalizer → controller → service → repository → builders
  → QueryEngine → ODBC → SQL Server
```

| HTTP surface | Entry point | Access |
|---|---|---|
| Public API | `api/index.php` | Configured API authentication and role authorization |
| Health probes | `api/health.php` | Public, minimal `/health/live` and `/health/ready` |
| Admin Console and API | `admin/index.php`, `admin/api.php` | Loopback only, System Administrator session, CSRF |
| SQL Parser | `sqlparser/index.php` | Loopback or internal only; no database access |

A minimal request:

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": ["I.ItemCode", "I.Description"],
  "filters": [{ "field": "I.Active", "operator": "=", "value": 1 }],
  "sort": [{ "field": "I.ItemCode", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 50 }
}
```

Requests are `POST` with `Content-Type: application/json`. API keys are sent
only as `X-API-Key: gsk_...`. See [Architecture](docs/Architecture.md) and the
[HTTP API](docs/API.md).

## Deployment models

| Model | Use | Guide |
|---|---|---|
| `start-windows.bat` / `start-linux.sh` with PHP's built-in server | Local development only | [Local development](docs/Local-Development.md) |
| Windows Server, IIS, PHP FastCGI | Production | [Windows Server IIS deployment](docs/Windows-IIS-Deployment.md) |
| Linux, Nginx, PHP-FPM | Production | [Production security and deployment](docs/Production-Security-and-Deployment.md) |

Production requires PHP 8.2 or newer with ODBC, OpenSSL, session, JSON, and
OPcache; a Microsoft ODBC Driver for SQL Server; `GENERIC_APP_ENV=production`;
`GENERIC_RUNTIME_CONFIG_DIR` outside the code tree; and
`GENERIC_SQL_API_ENCRYPTION_KEY` supplied through the worker environment. The
Admin Console and SQL Parser must never be exposed publicly. Application backups
cover configuration only; SQL Server backup is an operator responsibility.

## Where to start

- **Frontend or API client developers:** [HTTP API](docs/API.md),
  [Action reference](docs/Action-Reference.md), and
  [Frontend integration](docs/Frontend-Integration.md).
- **Operators:** [Local development](docs/Local-Development.md) to try it, then
  the deployment guide for your platform and
  [Admin Console](docs/Admin-Console.md).
- **Security reviewers:** [Security model](docs/security/Security-Model.md) and
  [Security verification](docs/security/Security-Verification.md).
- **Maintainers and coding agents:** [AI development guide](docs/AI-Development-Guide.md),
  [Testing](docs/Testing.md), and [CONTRIBUTING.md](CONTRIBUTING.md).

The full [documentation map](docs/README.md) lists every document.

## Tests

```bash
php tests/run.php
php -n tests/run.php
```

The suite is database-independent and needs no SQL Server, ODBC, or credentials.
See [Testing](docs/Testing.md).

## Repository layout

| Path | Contents |
|---|---|
| `api/`, `admin/`, `sqlparser/` | HTTP entry points and the Admin and SQL Parser UIs |
| `app/` | Middleware, request validation, controllers, services, repositories, builders, security, runtime, health, backup |
| `core/`, `database/` | Query engine, responses, logging, and the SQL Server driver |
| `config/` | Shipped registries and `*.example.json` templates |
| `queries/` | SQL Resources and internal metadata SQL (`queries/system/`) |
| `deployment/` | IIS, Nginx, and production PHP templates |
| `scripts/` | Runtime control, backup, database check, and production validation CLIs |
| `runtime/` | Bundled development PHP runtimes and local runtime state |
| `tests/` | Database-independent regression suites |
| `docs/` | Reference, operations, and security documentation |

Licensed under the terms in [LICENSE](LICENSE).
