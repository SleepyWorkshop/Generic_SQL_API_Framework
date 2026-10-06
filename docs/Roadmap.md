# Generic SQL API Framework Roadmap

The Generic SQL API Framework is a backend platform for exposing SQL-based
data and operations through a secure, configurable API.

This roadmap reflects the actual implementation and the remaining planned
backend work. Historical roadmap items that were implemented later are shown
under the version where they were actually delivered.

Frontend dashboards, reporting interfaces, charts, and other presentation
features are outside the scope of this backend project.

Detailed implementation history is maintained in `CHANGELOG.md`.

---

# v1.0.0 — Core Generic SQL API Framework

Status: Completed

The first stable release established the core Generic SQL API platform.

## API Foundation

- Dynamic JSON-driven API processing
- Generic request validation and normalization
- Standardized JSON responses
- CORS support
- Global error handling
- Request and error logging
- Query execution statistics
- Repository-based query execution

## SQL Query Engine

- Dynamic SQL Server SELECT generation
- Fields and aliases
- DISTINCT
- TOP
- CASE expressions
- Arithmetic expressions
- Prepared filters
- JOIN support
- GROUP BY
- HAVING
- ORDER BY
- Compatibility-aware pagination
- Allow-listed SQL functions

## Advanced SQL

- CTE support
- UNION / UNION ALL
- Window functions
- Subqueries
- Stored procedures
- Scalar functions
- Table-valued functions
- Metadata actions
- SQL Server-specific query handling

## v1.0 Fixes

- Metadata-aware conversion of date strings to `YYYYMMDD` integer values for
  integer-family date columns
- Query construction and execution compatibility improvements

---

# v2.0.0 — Platform Expansion & Security

Status: Implemented / Current Development Line

v2 expanded the original SQL API into a complete backend platform with
administration, authentication, authorization, write operations, resource
discovery, security controls, operations, deployment support, and extensive
testing.

## Admin Console

- Unified loopback Admin Console
- First-run setup
- Encrypted database configuration
- User management
- Fixed roles
- Managed API keys
- CORS configuration
- Runtime configuration
- System health
- Development process controls and production application availability controls
- Hosting-mode-aware configuration (no Server tab in production)
- Deployment-path-independent Admin Console

## Authentication

- Session authentication
- Managed API-key authentication
- `none` authentication mode
- `session` authentication mode
- `api_key` authentication mode
- `session+api_key` authentication mode
- Session regeneration
- Session expiration
- Secure logout
- Session destruction

## Authorization

- Separate backend and frontend authorization domains
- Deny-by-default resource scopes
- Role-based authorization
- User role assignment
- SQL resource authorization
- Write-resource authorization
- Last-administrator protection
- Authorization-change session invalidation

## Managed API Keys

- `gsk_` API keys
- One-time key reveal
- Hash-only key storage
- Owner assignment
- Role assignment
- Enable/disable lifecycle
- Permanent revoke
- Key fingerprints
- Last-used metadata

## Generic SQL Resources

- Recursive SQL Resource discovery
- Safe path-derived resource IDs
- Traversal protection
- Collision protection
- Runtime filters
- Execution metadata
- Deterministic sorting
- Pagination
- Output/source/HAVING filter mappings
- SQL Resource capability validation
- SQL Resource security validation

## Write Operations

- INSERT
- UPDATE
- DELETE
- SQL Server UPSERT
- Deny-by-default write-resource registry
- Resource-level action permissions
- Writable-column controls
- Filterable-column controls
- UPSERT key configuration
- Live SQL Server metadata validation
- Prepared DML values
- Affected-row responses
- Identity responses
- Safe constraint-error classification

## SQL Engine Expansion

- Recursive query expressions
- Expanded SQL function coverage
- CTE support
- Window functions
- Set operations
- SQL Server structural numeric expressions
- Prepared runtime values
- Modular query builders
- `QueryRepository` retained as the execution facade

## SQL-to-Universal-JSON Parser

- Standalone non-executing SQL parser
- Lexical parsing
- Capability analysis
- Validation
- Structured errors
- Browser-based parser interface
- Database-independent parsing operation

## Runtime & Performance Controls

- Configurable SQL query timeout
- Pagination limits
- Request body limits
- API rate limits
- Login rate limits
- Idle session expiration
- Absolute session expiration
- Runtime configuration through the Admin Console

## Logging & Auditing

- Request-correlated JSON Lines logging
- Security and audit events
- Sensitive-data redaction
- Concurrency-safe log writes
- Production-safe error envelopes
- Correlation IDs for production errors

## Backup & Recovery

- Application configuration backups
- Checksum verification
- Encryption verification
- Safe external restore staging
- Configuration migration
- File-locking protections
- Secure temporary-file handling

## Health & Operations

- Public liveness checks
- Public readiness checks
- Authenticated detailed health checks
- Configuration health
- Database health with disabled, connected, and unhealthy states
- Process health (managed processes in development, web-server-owned
  application availability in production)
- Logging health
- Encryption health
- Backup health (reported separately; never affects overall status or readiness)
- Cross-platform lifecycle management
- Stale-process recovery
- Duplicate-process recovery
- Restart cleanup
- Dynamic port handling

## Security Hardening

- Hardened session cookies
- Cookie-only session transport
- Login session regeneration
- CSRF rotation
- Session expiration
- Concurrent-session protections
- Database/configuration file locking
- Temporary-file permission hardening
- Secure key handling
- Secret rotation guidance
- Log redaction
- Exact-origin CORS validation
- Authentication throttling
- Fixed-host HTTPS redirects
- HSTS
- CSP and security headers
- Sensitive-file web denials
- Trusted-proxy boundaries

## Production Deployment

- IIS/FastCGI deployment templates
- Nginx/PHP-FPM deployment templates
- TLS/security-header examples
- Production PHP configuration
- Deployment validation tooling
- Windows operator guidance
- Linux operator guidance
- Production process ownership through IIS/FastCGI or Nginx/PHP-FPM
- Development-only local process management
- Deployment-independent Admin Console base paths

## Production Hardening

Status: Completed

Five production phases aligned the Admin Console, health model, and
deployment templates with IIS/FastCGI and Nginx/PHP-FPM ownership of listeners
and workers. Each phase was delivered with regression tests.

| Phase | Scope | Status |
|---|---|---|
| Phase 1 | Production configuration and IIS ownership | Completed |
| Phase 2 | API and SQL Parser application availability | Completed |
| Phase 3 | Application database availability | Completed |
| Phase 4 | Production System Health | Completed |
| Phase 5 | Admin Console cleanup | Completed |

- **Phase 1 — Production configuration and IIS ownership:** development port
  settings are no longer presented as production listener settings;
  `admin.server.save` is rejected in production; every IIS boundary
  (`/api`, `/admin`, `/sqlparser`) has its own FastCGI registration that sets
  `GENERIC_APP_ENV=production` explicitly.
- **Phase 2 — API and SQL Parser availability:** production Start/Stop/Reload
  change only application availability, enforced at each entry point with a
  safe `503 SERVICE_UNAVAILABLE`; no process, port, or PID is created, while
  development keeps its real process managers.
- **Phase 3 — Database availability:** Connect verifies the saved encrypted
  configuration with one closed test connection before enabling access;
  Disconnect only disables access; failures return safe configuration or
  connection reasons; connections remain request-scoped with no pool.
- **Phase 4 — System Health:** production services report web-server
  ownership with no fabricated PID/port; database and encryption health use
  explicit states; readiness adds the API availability check and honours cached
  connectivity failures without opening SQL connections; backup health reuses
  the existing backup service.
- **Phase 5 — Admin Console cleanup:** filesystem and session-directory
  diagnostics were removed from System Health; production configuration has no
  Server tab and safely falls back to Database.

The production documentation and validation milestone that followed these
phases (the [Windows Server IIS deployment](Windows-IIS-Deployment.md) guide,
consolidated hosting/security/database/health/backup documentation, and an
updated roadmap and documentation tests) is also complete. Remaining
verification work, such as live IIS and SQL Server validation on target hosts
and security scanning, is planned under v2.1.

## Testing

- API contract testing
- Query testing
- Write-operation testing
- Authentication testing
- Authorization testing
- Session testing
- API-key testing
- Security-boundary testing
- Concurrency testing
- Backup/recovery testing
- Health testing
- Error-handling testing
- Production-template testing
- Attack-oriented security testing
- Cross-platform regression coverage

---

# Historical Roadmap Reconciliation

The original roadmap proposed several separate versions for CRUD, advanced
SQL, security, performance, dashboard, reporting, multi-database support,
developer experience, and enterprise capabilities.

The implementation evolved differently.

Features that were originally planned for separate versions but were later
implemented are recorded under the actual version where they were delivered.

## Originally Planned Features

| Original Roadmap Item | Current Status |
|---|---|
| v1.1 CRUD Operations | Implemented in v2.0 |
| INSERT | Implemented |
| UPDATE | Implemented |
| DELETE | Implemented |
| UPSERT | Implemented |
| v1.2 Advanced Database Features | Implemented across v1.0 and v2.0 |
| Stored Procedures | Implemented |
| UNION / UNION ALL | Implemented |
| CASE | Implemented |
| CTE | Implemented |
| Window Functions | Implemented |
| Advanced SQL Functions | Implemented / Expanded |
| v2.0 Security | Implemented in v2.0 |
| Login / Sessions | Implemented |
| API Keys | Implemented |
| RBAC / Authorization | Implemented |
| Audit Logging | Implemented |
| Query Timeout | Implemented |
| Rate Limiting | Implemented |
| Health Monitoring | Implemented |
| Backup / Recovery | Implemented |
| Production Deployment | Implemented |
| v2.2 Performance Controls | Partially implemented; remaining items planned |
| Multi-Database Support | Planned |
| OpenAPI | Planned |
| Postman Collection | Planned |
| SDKs | Planned |
| CLI Tooling | Planned |
| Project Generator | Planned |
| Webhooks / Callbacks | Planned |
| Multi-Tenancy | Planned / Uncommitted |

---

# v2.1 — Security Verification & Operational Hardening

Status: Planned

The core security architecture is already implemented in v2.0.
This phase focuses on deeper verification and remaining operational security
work.

## v2.1.1 — Dependency Security Review

Status: Completed

The application declares no Composer, npm, vendored, or external-include
dependencies; its dependency surface is the host PHP runtime, extensions,
operating-system libraries, and ODBC stack. Outdated runtime components found in
the development/security-review environment were updated and the backend
regression suite passed. See the
[Dependency security review](security/Dependency-Security-Review.md).

## v2.1.2 — Static Security Analysis

Status: Completed

A manual static review of the backend, Admin Console, SQL Parser, deployment
templates, and repository history found 20 findings (SSA-01 – SSA-20). The
approved remediation scope, SSA-01 – SSA-12, is complete: deny-by-default
routine and query-source registries, public-API removal of setup and identity
management, SQL Resource runtime-filter source checks, production ODBC
transport hardening, an unpaginated result-row limit, regression coverage, and
documented or accepted limitations. The exposed administrator credential
(SSA-05) was rotated and verified. Authored SQL Resource SQL is not validated
table by table, the widget SQL Resources referenced by the frontend but absent
from the backend remain unresolved, and SSA-13 – SSA-20 are deferred. See the
[Static security analysis inventory](security/Static-Security-Analysis-Inventory.md).

## Security Verification

- Dependency security review (completed in v2.1.1)
- Static security analysis (completed in v2.1.2)
- Authorization security testing
- API security testing
- DAST / security scanning
- Penetration-test preparation
- Security architecture review
- Final security verification report

## Optional Security Enhancements

- Optional Admin MFA
- Additional authentication hardening where required
- Expanded security regression coverage

## Operational Improvements

- Log rotation and cleanup improvements
- Additional production diagnostics
- Additional operational validation
- Live IIS/FastCGI, Nginx/PHP-FPM, TLS, and SQL Server validation on target
  hosts (template and application behavior are already covered by tests)

---

# v3.0 — Multi-Database Support

Status: Planned

The framework will evolve from its current SQL Server-oriented implementation
toward a database-provider architecture.

## Database Platform

- Database Registry
- Database Context
- Database provider abstraction
- Shared database configuration model
- Database-specific connection handling
- Database-aware metadata
- Database-aware SQL generation

## Planned Database Providers

- Microsoft SQL Server
- MySQL
- PostgreSQL
- MariaDB

## Resource Integration

- Database-aware Resource Mapping
- Database-specific Resource discovery
- Database-aware authorization
- Database-aware metadata validation
- Database-aware query capabilities

## Admin Console

- Database registry management
- Database configuration
- Database health status
- Database lifecycle controls where supported

## Testing

- Provider-specific regression tests
- Cross-database API contract tests
- Metadata compatibility tests
- Query capability tests
- Authorization tests across database contexts

---

# v3.1 — Developer Experience & API Integration

Status: Planned

This phase focuses on making the backend easier for external developers and
applications to integrate.

## API Documentation

- OpenAPI specification
- API reference documentation
- Resource documentation
- Authentication documentation
- Error-response documentation

## Developer Tools

- Postman collection
- API examples
- Client integration examples
- CLI tooling
- Configuration/project initialization tooling

## SDKs

- SDK support for commonly used languages

SDK scope will be defined when implementation begins.

---

# v4.0 — Backend Platform & Enterprise Capabilities

Status: Planned / Uncommitted

Future backend capabilities may include the following.

## Transactions

- Explicit transaction APIs
- Transaction lifecycle management
- Transaction-safe write workflows

## Performance

- Query caching
- Metadata caching
- Query profiling
- Performance diagnostics
- Large-result handling strategies

## Distributed Operations

- Distributed rate limiting
- Distributed session/runtime coordination
- Scalable logging and operational controls

## Integration

- Webhooks
- Callback mechanisms
- Backend event system

## Enterprise

- Organization management
- Multi-tenant architecture
- Tenant-aware authorization
- Enterprise administration
- Advanced system monitoring

These items are future direction and are not part of the current product
contract until implemented, tested, and documented.

---

# Out of Scope for This Backend

The following are intentionally outside the scope of the Generic SQL API
Framework:

- Dashboard UI
- Charts
- KPI widgets
- Frontend reporting screens
- Report visualization
- Frontend layout management
- Frontend-specific analytics presentation

These capabilities can be implemented in separate frontend projects that
consume this API.

---

# Roadmap Status

| Version | Scope | Status |
|---|---|---|
| v1.0.0 | Core Generic SQL API Framework | Completed |
| v2.0.0 | Platform Expansion & Security | Implemented / Current |
| v2.0.0 Production Hardening | Production Phases 1–5 and production documentation | Completed |
| v2.1 | Security Verification & Operational Hardening | Planned |
| v2.1.1 | Dependency Security Review | Completed |
| v2.1.2 | Static Security Analysis | Completed |
| v3.0 | Multi-Database Support | Planned |
| v3.1 | Developer Experience & API Integration | Planned |
| v4.0 | Backend Platform & Enterprise Capabilities | Planned / Uncommitted |

---

# Roadmap Principles

- The roadmap describes backend capabilities only.
- Completed implementation is recorded according to the version in which it
  was actually implemented.
- Historical proposals are not treated as released versions.
- Features that are not implemented remain explicitly marked as planned.
- New features become part of the public contract only after implementation,
  testing, and documentation are completed.
- Frontend applications remain separate consumers of the Generic SQL API.