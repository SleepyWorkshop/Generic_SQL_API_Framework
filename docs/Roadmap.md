# Roadmap

This is the only place for planned, upcoming, and deferred backend work. Current
behavior is documented in the [documentation map](README.md); released changes
are in the [changelog](../CHANGELOG.md). Nothing here is part of the public
contract until it is implemented, tested, and documented.

| Milestone | Scope | Status |
|---|---|---|
| v1.0.0 | Core Generic SQL REST API Framework | Completed (2026-07-27) |
| v2.0.0 | Platform expansion and security | Completed (2026-10-05) |
| v2.1.0 | Security verification, operational hardening, and generic authorization | Completed |
| v3.0.0 | Multi-database support for SQL Server | Completed (2026-10-09) |
| v3.1 | Developer experience and API integration | Upcoming |

## Completed

### v1.0.0 — Core Generic SQL REST API Framework

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

### v2.1.0 — Security verification, operational hardening, and generic authorization

- **Security verification and hardening (v2.1.1 – v2.1.6):** dependency
  review, static security analysis, authorization and API security testing,
  passive DAST and penetration-test preparation, architecture and operational
  hardening, and final repository-level verification. Results and every finding
  are in [Security verification](security/Security-Verification.md).
- **Generic authorization model:** the application-specific registries
  (`query-sources.php`, `write-resources.php`, `routine-resources.php`) and
  per-role `sqlResources`/`writeResources` scopes were removed. Sessions, API
  keys, the legacy key, and anonymous mode share one path: principal → role →
  permission. The database login's permissions are the data access boundary.
- **Generic data access:** JSON queries on any catalog-confirmed table or view,
  generic CRUD on any user table, and routine calls by name, with filtering,
  sorting, and pagination as generic, non-authorization features.
  `sql-resources.php` remains for server-authored SQL Resource discovery.
- **Documentation and naming:** documentation reorganized and the project
  renamed Generic SQL REST API Framework.

The external penetration test and deployed-host validation were deliberately
deferred; they are listed under [Deferred](#deferred).

### v3.0.0 — Multi-database support for SQL Server

Several SQL Server server profiles and databases behind logical database ids,
with same-server cross-database SELECT. SQL Server remains the only engine.
See the [changelog](../CHANGELOG.md#300---2026-10-09) and
[Upgrading to V3](Upgrading-to-V3.md).

| # | Scope | Status | Commit |
|---|---|---|---|
| 1 | Architecture and database-context design (no code) | Completed | — |
| 2 | Encrypted database registry, V2 migration, runtime state, backup format 4 | Completed | `0768228` |
| 3 | Database context resolution, connection manager, login timeout | Completed | `b93cb3b` |
| 4 | Database references, access policy, and query planning | Completed | `3467ae9` |
| 5 | SQL Server identifiers, qualified objects, and source resolution | Completed | `c566c46` |
| 6 | Same-server cross-database SELECT | Completed | `34e4a20` |
| 7 | Database-aware metadata and `metadata.databases` | Completed | `d1c51ce` |
| 8 | Database-aware SQL Resources and routines | Completed | `a0d14d4` |
| 9 | Database-aware writes with one target database | Completed | `1d0bfd5` |
| 10 | Health and Admin database management | Completed | `828202c` |
| 11 | Security, isolation, and regression campaign | Completed | `26703dd` |
| 12 | Migration verification, documentation, and release preparation | Completed | release commit (`v3.0.0`) |

Deferred by design or not verified (see [Limitations](Limitations.md#multi-database-v3)):
other database engines; queries across server profiles, linked servers, and
federation; multi-target writes and distributed transactions; per-database
authorization; persistent connections and pools; cross-request metadata
caching and cross-database metadata federation; cross-database write sources;
encryption-key re-encryption tooling. Execution against a real SQL Server was
not part of the repository verification.

## Upcoming

### v3.1 — Developer experience and API integration

- OpenAPI specification;
- Postman collection and client integration examples;
- CLI and project-initialization tooling;
- SDKs for commonly used languages (scope defined when work begins).

## Deferred

Known items without a scheduled milestone:

- **SQL Server integration testing** of V3 on a dedicated non-production SQL
  Server: two databases on one instance (cross-database SELECT, collations,
  other databases' catalog views), SQL Resource placeholders, routines,
  writes, Azure SQL Database edition detection, and health checks.
- Encryption-key re-encryption tooling for the database registry.
- Informational static-analysis findings SSA-13 – SSA-16 and SSA-18 – SSA-20;
  see
  [Security verification](security/Security-Verification.md#findings-register).
- **External penetration test** (deferred from v2.1.4): authenticated dynamic
  testing, session and rate-limit testing over HTTPS, injection testing against
  a SQL Server test database, TLS and deployed IIS or Nginx behavior, and the
  Admin loopback boundary behind production hosting. The handoff is ready:
  [Penetration-test preparation](security/Penetration-Test-Preparation.md).
- **Target-host validation** of IIS/FastCGI, Nginx/PHP-FPM, TLS, ACLs, session
  storage, and live SQL Server behavior, including the generic write and
  routine paths.
- Per-column read authorization (ST-003) and a request filter-count limit
  (ST-004). API-level resource isolation is intentionally not part of the
  v2.1.0 architecture; data access is bounded by database permissions.
- Optional Admin Console MFA.
- Log rotation and retention tooling; currently a deployment responsibility.
- The widget SQL Resources referenced by the frontend but missing from
  `queries/widgets/` (see [Limitations](Limitations.md#sql-resource-mode)).

## Future direction (uncommitted)

Explicit transactions, query and metadata caching, query profiling, distributed
rate limiting and session state, webhooks and events, multi-tenancy, other
database engines (MySQL, PostgreSQL, MariaDB), cross-server queries, and
per-database authorization have been considered. None is scheduled.

## Out of scope

Dashboards, charts, KPI widgets, report screens, and other presentation features
belong to separate frontend projects that consume this API.
