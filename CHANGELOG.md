# Changelog

All notable changes are recorded here. The project follows semantic versioning.

## [2.0.0] - Unreleased

### Added

- A unified loopback Admin Console for first-run setup, encrypted database
  configuration, users, fixed roles, managed API keys, CORS, runtime settings,
  health, and service/database availability controls (real processes in
  development, application availability in production).
- Session and managed API-key authentication with four normal API modes:
  `none`, `session`, `api_key`, and `session+api_key`.
- Separate backend and frontend authorization domains, deny-by-default resource
  scopes, last-administrator protection, and authorization-change session
  invalidation.
- One-time-reveal `gsk_` API keys with hash-only storage, owner/role assignment,
  enable/disable/revoke lifecycle, fingerprints, and last-used metadata.
- Single-object INSERT, UPDATE, DELETE, and SQL Server UPSERT actions backed by a
  deny-by-default write-resource registry and live metadata validation.
- Recursive SQL Resource discovery with safe path-derived IDs, collision and
  traversal protection, execution metadata, runtime filters, deterministic
  sorting, and pagination.
- Recursive query expressions, expanded function coverage, CTEs, windows,
  set operations, and SQL Server-safe structural numeric literals while runtime
  values remain prepared parameters.
- A standalone, non-executing SQL-to-Universal-JSON parser with lexical parsing,
  capability analysis, validation, browser UI, and structured errors.
- Configurable query timeout, pagination limits, request body limit, API/login
  rate limits, and idle/absolute session expiration.
- Request-correlated JSON Lines audit/security logging with redaction and
  concurrency-safe append behavior.
- Application configuration backup creation, checksum/encryption verification,
  and safe external restore staging.
- Public liveness/readiness plus authenticated detailed health checks for
  application, configuration, database, processes, logging, encryption, and
  backups.
- IIS/FastCGI and Nginx/PHP-FPM deployment templates, TLS/security-header
  examples, production PHP settings, validation tooling, Windows/Linux
  operator checklists, and a complete Windows Server IIS deployment guide.
- Database-independent regression coverage for API contracts, query/write SQL,
  authentication, authorization, sessions, API keys, security boundaries,
  concurrency, backup/recovery, monitoring, errors, and production templates.

### Changed

- Runtime configuration is bootstrapped atomically from tracked secret-free
  examples; supported older schemas migrate without discarding identities or
  authorization assignments.
- Database configuration can be stored as a complete AES-256-GCM authenticated
  envelope whose key is supplied outside the repository.
- Local launchers start the managed API and SQL Parser, connect database
  availability, verify all three, and keep the loopback Admin Console in the
  foreground; each component is then controlled independently from System
  Health.
- Query construction is split into focused builders while `QueryRepository`
  remains the execution facade.
- Production process ownership belongs to IIS/FastCGI or Nginx/PHP-FPM; local
  process managers remain development-only. Each IIS boundary has its own
  FastCGI registration that sets production mode explicitly; production Admin
  Enable/Disable/Reload changes only application availability; database
  Connect verifies one closed test connection before enabling access; System
  Health reports web-server ownership, explicit database/encryption state, and
  non-fatal backup health; readiness never opens a SQL connection; and the
  production Admin Console has no Server configuration tab.
- Production errors use a stable client-safe envelope and correlation ID while
  internal diagnostics remain in redacted logs.
- Documentation is organized by current product behavior, release history,
  future roadmap, and repository-specific maintenance guidance.

### Fixed

- SQL Resource CTE filtering/count/pagination, logical output/source/HAVING
  mappings, integer-backed date conversion, and authored OFFSET/FETCH conflicts.
- SQL Server ordering in window and legacy pagination contexts.
- SQL Server/ODBC type inference for validated structural numeric expression
  arguments without making runtime values raw SQL.
- UPSERT key propagation from server-owned write-resource configuration.
- Cross-platform lifecycle state, stale/duplicate process recovery, restart
  cleanup, dynamic ports, and configuration bootstrap behavior.
- Admin Console view initialization and explicit selection of all supported
  authentication modes.
- Strict exact-origin CORS validation and throttling before authentication to
  prevent unauthenticated protected-request rate-limit bypass.

### Security

- Hardened session cookies, strict cookie-only transport, login regeneration,
  logout destruction, CSRF rotation, expiration, and concurrent-session writes.
- Hardened database/configuration file locking, temporary-file permissions,
  migration, key handling, log redaction, and secret rotation guidance.
- Added fixed-host HTTPS redirects, HSTS and boundary-specific CSP/security
  headers, sensitive-file web denials, and explicit trusted-proxy boundaries.
- Added attack-oriented tests for authentication, authorization, API keys,
  CSRF/CORS, SQL/CRUD injection, traversal, secrets, backups, health, logging,
  and error disclosure.
- Documented the v2.1.1 dependency security review: the application has no
  Composer/npm or vendored dependencies, and outdated PHP, OpenSSL, curl,
  SQLite, and libxml2 runtime packages in the review environment were updated
  with the backend regression suite passing afterwards.
- Completed the v2.1.2 static security analysis and remediated, documented,
  or accepted findings SSA-01 through SSA-12: deny-by-default routine and
  query-source registries (reconciled with the sources used by the frontend),
  Admin-only setup and identity/API-key/role management, registered-source
  checks for SQL Resource runtime filters, production ODBC Driver 18/17
  transport hardening and warnings, a `GENERIC_MAX_RESULT_ROWS` limit for
  unpaginated reads, Windows PHP runtime provenance and checksums, and security
  regression coverage. The exposed administrator credential (SSA-05) was
  rotated and verified to no longer match the historical hash. Authored SQL
  Resource SQL is not validated table by table, and SSA-13 through SSA-20
  remain deferred.
- Completed the v2.1.3 authorization and API security testing.
  - Fixed: Application Administrators can no longer take over or alter
    backend-only accounts, and System Administrator accounts can no longer be
    managed through the public API (AAPI-01, AAPI-02).
  - Also hardened: registered write routines require CSRF; frontend user
    management minimizes backend identity profiles and gives uniform refusals
    for missing accounts; the System Administrator role is rejected for
    anonymous and legacy-key principals; non-string public actions are
    rejected.
  - Accepted and documented: password changes without the current password,
    API key roles, and list bodies.
  - All 64 planned authorization and API tests pass, 56 over HTTP and 8 at the
    enforcement layer.

## [1.0.0] - Initial release

### Added

- JSON API routing, controller/service layers, public request validation and
  normalization, CORS, and standard response envelopes.
- SQL Server SELECT generation with fields/aliases, DISTINCT/TOP, CASE,
  arithmetic, allow-listed functions, prepared filters, joins, grouping/HAVING,
  sorting, and compatibility-aware pagination.
- Window functions, filter subqueries, CTEs, UNION/UNION ALL, stored procedures,
  scalar functions, table-valued functions, and metadata actions.
- SQL execution timing, returned-row counts, and request/error logging.

### Fixed

- BETWEEN date strings can be converted to `YYYYMMDD` integers for
  integer-family date columns discovered through metadata.

Future work is maintained in [docs/Roadmap.md](docs/Roadmap.md).
