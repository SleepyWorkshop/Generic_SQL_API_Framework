# Penetration-Test Preparation

Status: **Ready for scoping** — prepared for an external penetration test; the test itself has not been performed — 2026-10-06

This document prepares an external penetration test of the Generic SQL API
Framework backend. It is part of roadmap phase v2.1.4 of
[v2.1 — Security Verification & Operational Hardening](../Roadmap.md#v21--security-verification--operational-hardening).
It contains no credentials, keys, hashes, or other secrets. Test credentials
are provisioned and handed over separately (see
[Section 5](#5-test-accounts-and-data)).

## 1. Objectives

- Independently verify the authentication, authorization, session, CSRF, and
  API-key controls in a production-like deployment.
- Exercise the tests deferred from the [DAST report](DAST-Report.md#5-not-performed),
  especially authenticated testing over HTTPS against a SQL Server test
  database.
- Confirm that the Admin Console and SQL Parser cannot be reached outside
  their intended loopback or internal boundary.
- Identify issues that the static review, authorization testing, and DAST did
  not cover.

## 2. System overview

| Component | Purpose | Entry points | Authentication |
|---|---|---|---|
| Public API | Universal query, write, routine, metadata, SQL Resource, and frontend-user actions | `POST /api` (single JSON action endpoint); `GET /health/live`, `GET /health/ready` | Session, managed API key, or both, per `authentication.mode` |
| Admin Console | Setup, database configuration, backend users, roles, API keys, runtime and availability controls | `/admin/`, `POST /admin/api.php` | Loopback, `GENERIC_ADMIN_ENABLED=1`, session, and `admin.manage` |
| SQL Parser | Converts SQL to Universal API JSON; never executes SQL | `/sqlparser/` page and `POST` | None; loopback or internal only |
| Frontend | Static single-page application | `/` | Uses the public API session |

Request handling, middleware order, roles, and permissions are documented in
the [Authorization & API security inventory](Authorization-API-Security-Inventory.md).
Production hosting is documented in
[Production security and deployment](../Production-Security-and-Deployment.md)
and [Windows Server IIS deployment](../Windows-IIS-Deployment.md).

## 3. Scope

### In scope

- The public API, including all public actions, both authentication methods
  (session and managed API key), and the CSRF and rate-limit controls.
- The frontend user-management actions (`auth.frontendUsers.*`) and the
  backend and frontend role boundaries.
- Query, write, routine, metadata, and SQL Resource actions against a
  dedicated SQL Server test database, including injection testing.
- The session lifecycle: login, logout, rotation, idle and absolute timeouts,
  and revocation after authorization changes.
- The Admin Console, from the loopback host only, and verification that it
  is unreachable from any other address or through the reverse proxy.
- The SQL Parser input handling and its internal-only exposure.
- The production IIS or Nginx configuration of the test environment,
  including TLS, security headers, routing allowlists, and blocked paths.

### Out of scope

- Production systems, production data, and production credentials.
- Denial-of-service, load, and stress testing.
- Social engineering and physical access.
- The SQL Server instance, operating system, and network beyond what is
  reachable through the application.
- Third-party or external systems.

## 4. Rules of engagement

- Test only the dedicated test environment named at kickoff. It must not
  share configuration, credentials, or databases with production.
- Use only the accounts and API keys provisioned for the test. Do not create
  persistence beyond those accounts unless agreed.
- Credential guessing is limited to verifying the login lockout with a
  dedicated test account and a small number of attempts.
- Rate-limit testing must use bounded request counts that are sufficient to
  observe `429 RATE_LIMIT_EXCEEDED` or `LOGIN_RATE_LIMITED` and no more.
- Write, routine, and destructive SQL testing is limited to the test
  database, and the database must be restorable from a backup taken before
  testing.
- Stop and notify the system owner immediately if production data,
  credentials, or an unexpected Critical or High issue is found.
- The test window, source addresses, and contacts are agreed at kickoff.

## 5. Test accounts and data

Provision the following accounts in the test environment before kickoff. Do
not reuse production accounts or credentials. Hand credentials over through
an agreed secure channel, never through this document or the repository, and
rotate or delete them after the test.

| Account | Role | Domain | Purpose |
|---|---|---|---|
| Test System Administrator | `system-administrator` | Backend | Admin Console and full API |
| Test data operator | `data-operator` | Backend | Data writes and routines |
| Test read-only user | `read-only` | Backend | Least-privilege backend access |
| Test Application Administrator | `application-administrator` | Frontend | Frontend user management |
| Test frontend user | Frontend read access | Frontend | Least-privilege frontend access |
| Test API keys | One per relevant role, including a scoped key | API key | API-key authentication and resource scopes |
| Lockout account | Any non-administrator role | Backend | Login lockout verification only |

The test database must contain synthetic data only, and should include the
tables, views, routines, and SQL Resources needed to exercise the allowlists
and resource scopes.

## 6. Suggested test areas

| Area | Focus |
|---|---|
| Authentication | Login, logout, failure responses, lockout, username enumeration, session cookie flags over HTTPS |
| Sessions | Fixation, rotation on login, idle and absolute timeouts, revocation after role, access, or password changes |
| Authorization | Vertical and horizontal escalation across roles and domains; frontend administrators against backend identities; resource scopes |
| API keys | Scope enforcement, disabled and revoked keys, key versus session precedence, owner and role independence |
| CSRF | Session-authenticated writes, write routines, and frontend-user actions without or with a wrong token; cross-origin requests |
| CORS | Origin allowlist, credentials, preflight |
| Injection | Query expressions, filters, sorting, pagination, writes, routine parameters, SQL Resource runtime filters |
| Request validation | Content type, body size, JSON shape, unknown properties, type confusion |
| Error handling | No stack traces, paths, SQL text, or configuration in production responses |
| Admin boundary | Loopback enforcement, `GENERIC_ADMIN_ENABLED`, reverse-proxy and header-spoofing attempts |
| SQL Parser | Malformed and large inputs; confirmation that SQL is never executed; internal-only exposure |
| Transport and headers | TLS configuration, HSTS, CSP, frame, referrer, and nosniff headers; HTTP redirect site |
| Path handling | Traversal and encoding against the IIS or Nginx routing allowlists; blocked file extensions and directories |

## 7. Known findings and accepted risks

These items are already known. Report them only if the tester finds a new
impact or a bypass of the documented control.

- **Static analysis:** SSA-01 – SSA-12 are remediated, and SSA-13 – SSA-20 are
  deferred. Authored SQL Resource SQL is not validated table by table, and
  some widget SQL Resources referenced by the frontend are absent from the
  backend. See the
  [Static security analysis inventory](Static-Security-Analysis-Inventory.md).
- **Authorization (accepted):**
  - AAPI-05: password changes do not require the current password.
  - AAPI-06: API key privileges are independent of the owner's role.
  - AAPI-07: the public API accepts a JSON list body.

  See the
  [Authorization & API security inventory](Authorization-API-Security-Inventory.md).
- **DAST (accepted, development only):** DAST-03, DAST-04, and DAST-05. See
  the [DAST report](DAST-Report.md).
- **By design:**
  - Rate limiting and login lockout are exact only for workers that share
    one local filesystem.
  - `/health/ready` is public.
  - The Admin Console has no MFA (optional MFA is a planned enhancement).

## 8. Pre-engagement checklist

- [ ] Test environment deployed from the production templates, with
      `GENERIC_APP_ENV=production` and `deployment/php-production-security.ini`
      merged.
- [ ] TLS certificate for the test hostname installed.
- [ ] Synthetic SQL Server test database and a pre-test backup.
- [ ] Test accounts and API keys provisioned, and credentials handed over
      securely.
- [ ] Test window, tester source addresses, and contacts agreed.
- [ ] Logging verified, so that tester activity can be correlated
      afterwards.
- [ ] Backend regression suite (`php -n tests/run.php`) passing on the
      deployed commit.

## 9. Reporting

- Report each finding with its severity, affected endpoint or action, the
  steps to reproduce, the observed and expected behavior, and a recommended
  fix.
- Severity uses Critical, High, Medium, Low, and Informational, as in the
  other `docs/security/` reports.
- Do not include credentials, session identifiers, API keys, or other
  secrets in the report; redact them in evidence.
- Critical and High findings are reported to the system owner when they are
  found, not only in the final report.

## 10. After the test

- Rotate or delete all test accounts and API keys.
- Restore or discard the test database.
- Record each finding and its disposition in a new `docs/security/` report,
  and add regression tests for each remediated finding.
