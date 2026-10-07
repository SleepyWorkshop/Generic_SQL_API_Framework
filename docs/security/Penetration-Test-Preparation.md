# Penetration-test preparation

Status: **Ready for scoping.** The external penetration test has not been
performed.

This document is the handoff for an external penetration test of the Generic
SQL API Framework backend. It contains no credentials, keys, hashes, or other
secrets; test credentials are provisioned and handed over separately (see
[Test accounts and data](#test-accounts-and-data)).

Background: [Security model](Security-Model.md) describes the controls under
test, and [Security verification](Security-Verification.md) records what has
already been verified and every known finding.

## Objectives

- Independently verify authentication, authorization, session, CSRF, and API-key
  controls in a production-like deployment over HTTPS.
- Perform the dynamic testing that repository-level verification could not:
  authenticated testing against a SQL Server test database, session and
  rate-limit behavior over real TLS, and deployed IIS or Nginx behavior.
- Confirm that the Admin Console and SQL Parser cannot be reached outside their
  loopback or internal boundary.
- Identify issues the static review, authorization testing, and passive DAST did
  not cover.

## System overview

| Component | Purpose | Entry points | Authentication |
|---|---|---|---|
| Public API | Query, write, routine, metadata, SQL Resource, session, and frontend-user actions | `POST /api` (single JSON action endpoint); `GET /health/live`, `GET /health/ready` | Session, managed API key, or both, per `authentication.mode` |
| Admin Console | Setup, database configuration, backend users, roles, API keys, runtime and availability controls, backups | `/admin/`, `POST /admin/api.php` | Loopback, `GENERIC_ADMIN_ENABLED=1`, session, and `admin.manage` |
| SQL Parser | Converts SQL to Universal API JSON; never executes SQL | `/sqlparser/` page and `POST` | None; loopback or internal only |
| Frontend | Static single-page application | `/` | Uses the public API session |

Request surfaces, middleware order, and the role model are in
[Security model](Security-Model.md#request-surfaces). Production hosting is in
[Production security and deployment](../Production-Security-and-Deployment.md)
and [Windows Server IIS deployment](../Windows-IIS-Deployment.md).

## Scope

### In scope

- The public API: all public actions, session and managed API-key
  authentication, and the CSRF and rate-limit controls.
- Frontend user management (`auth.frontendUsers.*`) and the backend and frontend
  role boundaries.
- Query, write, routine, metadata, and SQL Resource actions against a dedicated
  SQL Server test database, including injection testing.
- The session lifecycle: login, logout, rotation, idle and absolute timeouts,
  and revocation after authorization changes.
- The Admin Console from the loopback host, and verification that it is
  unreachable from any other address or through the web server.
- SQL Parser input handling and its internal-only exposure.
- The test environment's production IIS or Nginx configuration: TLS, security
  headers, routing allowlists, and blocked paths.

### Out of scope

- Production systems, production data, and production credentials.
- Denial-of-service, load, and stress testing.
- Social engineering and physical access.
- The SQL Server instance, operating system, and network beyond what is
  reachable through the application.
- Third-party or external systems.

## Rules of engagement

- Test only the dedicated test environment named at kickoff. It must not share
  configuration, credentials, or databases with production.
- Use only the accounts and API keys provisioned for the test. Do not create
  persistence beyond those accounts unless agreed.
- Credential guessing is limited to verifying login lockout with a dedicated
  test account and a small number of attempts.
- Rate-limit testing uses bounded request counts sufficient to observe
  `429 RATE_LIMIT_EXCEEDED` or `429 LOGIN_RATE_LIMITED`, and no more.
- Write, routine, and destructive SQL testing is limited to the test database,
  which must be restorable from a backup taken before testing.
- Stop and notify the system owner immediately if production data, credentials,
  or an unexpected Critical or High issue is found.
- The test window, source addresses, and contacts are agreed at kickoff.

## Test accounts and data

Provision these in the test environment before kickoff. Do not reuse production
accounts. Hand credentials over through an agreed secure channel, never through
this document or the repository, and rotate or delete them after the test.

| Account | Role | Domain | Purpose |
|---|---|---|---|
| Test System Administrator | `system-administrator` | Backend | Admin Console and full API |
| Test data operator | `data-operator` | Backend | Data writes and routines |
| Test read-only user | `read-only` | Backend | Least-privilege backend access |
| Test Application Administrator | `application-administrator` | Frontend | Frontend user management |
| Test frontend user | Frontend access only | Frontend | Least-privilege frontend access |
| Test API keys | One each for `read-only`, `data-operator`, and `api-administrator` | API key | API-key authentication and role confinement |
| Lockout account | Any non-administrator role | Backend | Login lockout verification only |

The test database must contain synthetic data only, and include the tables,
views, procedures, functions, and SQL Resources needed to exercise every
action. Give the test database login deliberately limited grants so testers can
confirm that the login's permissions bound what each role reaches.

## Existing automated coverage

The regression suites run against isolated, temporary deployments with the
database unavailable. They are not DAST and do not replace this test. The last
column is the gap this test should cover.

| Area | Automated coverage | Not covered by the suites |
|---|---|---|
| Authentication | Partial | Unicode, long, and duplicate-field credentials; disabled- and deleted-account login over HTTP; production hosting |
| Session management | Partial | Secure cookie over real TLS; fixation and timeouts observed over HTTP |
| Authorization | Covered | Behavior with a live SQL Server database |
| CSRF | Covered | Browser-based cross-site requests |
| API-key security | Covered | Behavior behind production hosting |
| Rate limiting | Partial | Per-client isolation over HTTP; multi-worker deployments |
| Input validation / injection | Partial | Injection against a live SQL Server; HTML or script content round-tripped through stored data |
| Error and information disclosure | Partial | Production responses through IIS or Nginx and FastCGI; real database errors |
| SQL Parser | Partial | SQL comments, non-JSON content types, broader malformed input |
| Admin API | Covered | Loopback boundary behind production hosting |
| Path / file access | Partial | IIS and Nginx routing in a deployed environment |
| Security headers / transport | Partial | Live TLS; headers emitted by deployed IIS or Nginx; `TRACE` in production |

## Suggested test areas

| Area | Focus |
|---|---|
| Authentication | Login, logout, failure responses, lockout, username enumeration, cookie flags over HTTPS |
| Sessions | Fixation, rotation on login, idle and absolute timeouts, revocation after role, access, or password changes |
| Authorization | Vertical and horizontal escalation across roles and domains; frontend administrators against backend identities; identical decisions for sessions and API keys |
| API keys | Role confinement, disabled and revoked keys, key versus session precedence, owner state |
| CSRF | Session-authenticated writes, stored procedures, and frontend-user actions without or with a wrong token; cross-origin requests |
| CORS | Origin allowlist, credentials, preflight |
| Injection | Query expressions, filters, sorting, pagination, write table and column names, routine names and parameters, SQL Resource runtime filters; attempts to reach `sys` objects, other databases, or system procedures |
| Request validation | Content type, body size, JSON shape, unknown properties, type confusion |
| Error handling | No stack traces, paths, SQL text, or configuration in production responses |
| Admin boundary | Loopback enforcement, `GENERIC_ADMIN_ENABLED`, proxy and header-spoofing attempts |
| SQL Parser | Malformed and large inputs; confirmation that SQL is never executed; internal-only exposure |
| Transport and headers | TLS configuration, HSTS, CSP, frame, referrer, and nosniff headers; HTTP redirect site |
| Path handling | Traversal and encoding against the IIS or Nginx routing allowlists; blocked extensions and directories |

## Known findings and accepted risks

Report these only if a new impact or a bypass of the documented control is
found. Details are in [Security verification](Security-Verification.md).

- **Open:** ST-003 (no API-level table or column authorization; the database
  login's grants are the data boundary) and ST-004 (no filter-count limit).
- **Superseded by design (v2.1.0):** SSA-01, SSA-03, and SSA-07. There are no
  table, write, or routine registries; report only a way past the compensating
  controls (catalog confirmation, system-object rejection, permission checks).
- **Deferred informational:** SSA-13 – SSA-16 and SSA-18 – SSA-20. SSA-14 means `health.php` paths other than the
  two probes, including direct `/api/health.php` under IIS, return version,
  port, and start time.
- **Accepted:** AAPI-05 – AAPI-07; development-only DAST-03 – DAST-05; SAOH-04 – SAOH-08.
- **By design:** authored SQL Resource SQL is not checked table by table; rate
  limiting and login lockout are exact only for workers sharing one local
  filesystem; `/health/ready` is public; the Admin Console has no MFA.
- **Known implementation gap (not a security finding):** the frontend references
  widget SQL Resources that do not exist in `queries/widgets/`; they fail with
  `INVALID_SQL_RESOURCE`.

## Pre-engagement checklist

- [ ] Test environment deployed from the production templates, with
      `GENERIC_APP_ENV=production`, `GENERIC_RUNTIME_CONFIG_DIR` outside the
      code tree, and `deployment/php-production-security.ini` merged.
- [ ] TLS certificate for the test hostname installed.
- [ ] Synthetic SQL Server test database and a pre-test backup.
- [ ] Test accounts and API keys provisioned, and credentials handed over
      securely.
- [ ] Test window, tester source addresses, and contacts agreed.
- [ ] Logging verified, so that tester activity can be correlated by request ID.
- [ ] `php -n tests/run.php` passing on the deployed commit.

## Reporting

- Report each finding with its severity (Critical, High, Medium, Low,
  Informational), affected endpoint or action, reproduction steps, observed and
  expected behavior, and a recommended fix.
- Redact credentials, session identifiers, API keys, and other secrets in all
  evidence.
- Report Critical and High findings to the system owner when found, not only in
  the final report.

## After the test

- Rotate or delete all test accounts and API keys.
- Restore or discard the test database.
- Record each finding and its disposition in
  [Security verification](Security-Verification.md), and add a regression test
  for each remediated finding.
