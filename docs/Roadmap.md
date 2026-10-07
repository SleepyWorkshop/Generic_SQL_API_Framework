# Roadmap

This is the only place for planned, upcoming, and deferred backend work. Current
behavior is documented in the [documentation map](README.md); released changes
are in the [changelog](../CHANGELOG.md). Nothing here is part of the public
contract until it is implemented, tested, and documented.

| Milestone | Scope | Status |
|---|---|---|
| v1.0.0 | Core Generic SQL API Framework | Released |
| v2.0.0 | Platform expansion and security | Released (2026-10-05) |
| v2.1.0 | Security verification and operational hardening | Completed |
| v2.2 | Generic authorization and data-access simplification | Current — in development |
| v3.0 | Multi-database support | Upcoming |
| v3.1 | Developer experience and API integration | Upcoming |

## Completed

### v1.0.0 — Core Generic SQL API Framework

JSON-driven SQL Server SELECT generation with validation, normalization,
standard responses, CORS, logging, joins, grouping, CTEs, UNION, window
functions, subqueries, routines, and metadata actions.

### v2.0.0 — Platform expansion and security

The Admin Console; session and API-key authentication; fixed roles and resource
scopes; managed API keys; SQL Resource discovery; the Write API; the SQL Parser;
runtime and performance controls; audit and operational logging; application
backup and recovery; health and readiness; production error handling; IIS and
Nginx deployment templates and guides; and the database-independent regression
suite. See the [changelog](../CHANGELOG.md#200---2026-10-05).

### v2.1.0 — Security verification and operational hardening

Dependency review, static security analysis, authorization and API security
testing, passive DAST and penetration-test preparation, architecture and
operational hardening, and final repository-level verification (v2.1.1 –
v2.1.6). Results and every finding are in
[Security verification](security/Security-Verification.md). The external
penetration test and deployed-host validation were deliberately deferred; they
are listed under [Deferred](#deferred).

## Current

### v2.2 — Generic authorization and data-access simplification

Development version `2.2.0-dev` on the `dev` branch; not released.

Goal: keep the framework application-agnostic, so that any SQL application can
use the same backend without registering its tables, write targets, or routines.

- Remove the mandatory application-specific registries: `query-sources.php`,
  `write-resources.php`, and `routine-resources.php`, and the per-role
  `sqlResources`/`writeResources` scopes.
- Use one authorization path for sessions, API keys, the legacy key, and
  anonymous mode: authenticated principal → role → permission.
- Allow generic CRUD on any user table according to `data.write` and request
  validation; allow routine calls by name according to `routine.execute`
  (plus `data.write` for stored procedures).
- Keep filtering, sorting, and pagination generic and independent of
  authorization.
- Keep `sql-resources.php` for server-authored SQL Resource discovery.
- Keep SQL safety (identifier validation, catalog checks, parameterization),
  the database login's permissions as the final data boundary, and the Admin
  API as a separate management plane.

Status: implemented on `dev` with the regression suite passing; remaining before
release is review and live SQL Server validation of the new write and routine
paths.

## Upcoming

### v3.0 — Multi-database support

Move from the SQL Server-only implementation to a database-provider
architecture:

- a database registry and per-request database context;
- provider abstraction for connections, metadata, and SQL generation;
- database-aware resources, authorization, and metadata validation;
- Admin Console management of multiple databases;
- planned providers: Microsoft SQL Server, MySQL, PostgreSQL, and MariaDB;
- provider-specific and cross-database regression tests.

### v3.1 — Developer experience and API integration

- OpenAPI specification;
- Postman collection and client integration examples;
- CLI and project-initialization tooling;
- SDKs for commonly used languages (scope defined when work begins).

## Deferred

Known items without a scheduled milestone:

- Informational static-analysis findings SSA-13 – SSA-16 and SSA-18 – SSA-20;
  see
  [Security verification](security/Security-Verification.md#findings-register).
- **External penetration test** (deferred from v2.1.4): authenticated dynamic
  testing, session and rate-limit testing over HTTPS, injection testing against
  a SQL Server test database, TLS and deployed IIS or Nginx behavior, and the
  Admin loopback boundary behind production hosting. The handoff is ready:
  [Penetration-test preparation](security/Penetration-Test-Preparation.md).
- **Target-host validation** of IIS/FastCGI, Nginx/PHP-FPM, TLS, ACLs, session
  storage, and live SQL Server behavior.
- Per-column read authorization (ST-003) and a request filter-count limit
  (ST-004). Optional API-level resource isolation is intentionally not part of
  v2.2.
- Optional Admin Console MFA.
- Log rotation and retention tooling; currently a deployment responsibility.
- The widget SQL Resources referenced by the frontend but missing from
  `queries/widgets/` (see [Limitations](Limitations.md#sql-resource-mode)).

## Future direction (uncommitted)

Explicit transactions, query and metadata caching, query profiling, distributed
rate limiting and session state, webhooks and events, and multi-tenancy have been
considered. None is scheduled.

## Out of scope

Dashboards, charts, KPI widgets, report screens, and other presentation features
belong to separate frontend projects that consume this API.
