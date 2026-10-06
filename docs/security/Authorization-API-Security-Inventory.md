# Authorization & API Security Inventory

Review status: **All findings dispositioned (AAPI-01 – AAPI-08); v2.1.3 test plan not yet complete** — 2026-10-06

This document records the read-only first step of roadmap phase v2.1.3
(Authorization & API Security Testing) of
[v2.1 — Security Verification & Operational Hardening](../Roadmap.md#v21--security-verification--operational-hardening).
It maps the backend authorization and API boundary as implemented at commit
`2436a8f` and proposes the v2.1.3 test plan. AAPI-01 and AAPI-02 have since
been resolved, and AAPI-03 – AAPI-08 have received final dispositions (see
[Remediation status](#remediation-status)). No code, configuration, test, or
authentication state was changed. No password, hash, API key, session
identifier, token, or other secret is reproduced here.

## 1. Scope

- **Entry points:** the public API (`api/index.php`) and the Admin API
  (`admin/api.php`). The SQL Parser (`sqlparser/`) is a non-executing,
  unauthenticated converter with no data, identity, or configuration access
  and is out of scope except where noted.
- **Code traced:** the middlewares in `app/Middleware/`; authentication,
  session, CSRF, API key, authorization, user management, setup, and admin
  services; controllers; request validators; the role model; and
  `config/authorization.json` validation.
- **Method:** each action was traced from the dispatch code, not inferred
  from its name. Candidate authorization defects were confirmed against the
  service layer in an isolated, temporary credential store outside the
  repository (synthetic users, deleted after the run). No live account,
  production data, or HTTP state-changing request was used.
- **Not covered:** dynamic testing against a deployed host, DAST, penetration
  testing, and SQL Server-dependent behavior.
- **v2.1.2 context:** resolved findings (public `setup.createAdmin`, public
  backend identity/API-key/role management, routine authorization,
  query-source allowlist, SQL Resource filter sources, ODBC fallback, result
  limit, credential rotation) are not reopened. Findings below are new.

Terminology: **SA** = System Administrator (`system-administrator`, backend);
**AA** = Application Administrator (`application-administrator`, frontend);
**gate** = `LocalAdminMiddleware` (`GENERIC_ADMIN_ENABLED=1` and
`REMOTE_ADDR` is `127.0.0.1` or `::1`).

## 2. API Route Inventory

Both APIs dispatch on a JSON `action` property. There are **73 distinct
actions**: 29 on the public API and 49 on the Admin API, of which 5
(`setup.status`, `auth.csrf`, `auth.login`, `auth.session`, `auth.logout`)
exist on both (78 endpoint/action pairs).

Common to every action on both APIs: `POST` only (`405 METHOD_NOT_ALLOWED`),
`Content-Type: application/json` (`415 UNSUPPORTED_MEDIA_TYPE`), a JSON object
body (`400 INVALID_JSON` / `400 INVALID_REQUEST`), and the API rate limiter
after authentication. The public API additionally rejects disallowed `Origin`
values (`403 CORS_ORIGIN_DENIED`), answers `OPTIONS` with `204`, and, in
production, returns `503 SERVICE_UNAVAILABLE` when the API application is
disabled (`ApplicationRuntimeMiddleware`).

### Public API (`api/index.php`)

| Endpoint/action | HTTP method | Public/Admin | Authentication | Authorization | CSRF | Availability |
|---|---|---|---|---|---|---|
| `setup.status` | POST | Public | None | None | No | Runtime enabled |
| `auth.csrf` | POST | Public | None (starts a session) | None | No | Runtime enabled |
| `auth.login` | POST | Public | Credentials | None | **Yes** | Runtime enabled; login rate limit |
| `auth.session` | POST | Public | None (reports session state) | None | No | Runtime enabled |
| `auth.logout` | POST | Public | None | None | **Yes** | Runtime enabled |
| `auth.frontendUsers.list` | POST | Public | **Session only** (any API mode) | `frontend.users.manage` | No | Runtime enabled |
| `auth.frontendUsers.create` | POST | Public | Session only | `frontend.users.manage` + persisted actor is SA or AA | **Yes** | Runtime enabled |
| `auth.frontendUsers.update`, `.enable`, `.disable`, `.delete`, `.changePassword`, `.assignRole` | POST | Public | Session only | `frontend.users.manage` + persisted actor/target policy (§12) | **Yes** | Runtime enabled |
| `select`, `union`, `unionAll` | POST | Public | API mode (below) | `data.read` or `frontend.read`; query-source registry | No | Runtime + database |
| `sql` | POST | Public | API mode | `sql.execute` with `sqlResources` scope, or `frontend.read` (no scope, SSA-06) | No | Runtime + database |
| `procedure`, `function`, `tableFunction` | POST | Public | API mode | Routine registry + `routine.execute` + listed role; `data.write` for write routines | **Write routines only** (session; AAPI-03) | Runtime + database |
| `insert`, `update`, `delete`, `upsert` | POST | Public | API mode | `data.write` with `writeResources` scope | **Yes** (session only) | Runtime + database |
| `metadata.tables`, `.columns`, `.views`, `.procedures`, `.schema` | POST | Public | API mode | `metadata.read` or `frontend.read`; source filtering | No | Runtime + database |

**API mode** (`SecurityConfiguration::authenticationMode()`): `none` creates an
anonymous principal with `publicRoles`; `api_key` requires a valid
`X-API-Key` (`401`, or `503 AUTHENTICATION_UNAVAILABLE` when no key is
configured); `session` requires a session; `session+api_key` accepts a valid
key and otherwise falls back to the session. Any action beginning with
`auth.` or `admin.` always requires a session, regardless of mode.

**Rejected before runtime and authentication checks** (`404 NOT_FOUND`):
every `admin.*` action, `setup.createAdmin`, `auth.users.*`,
`auth.apiKeys.*`, and `auth.roles.list`. Any other unknown action continues
through authentication and `AuthorizationMiddleware` (which requires
`admin.manage`) and is then rejected by `QueryRequestValidator` (`400`).

### Admin API (`admin/api.php`)

An explicit allowlist of 49 actions is enforced first; any other action
returns `404`. API keys never authenticate Admin actions, because every
non-public Admin action begins with `admin.` or `auth.` and therefore takes the
session path. The Admin API has no application-runtime or database
availability gate. `GENERIC_ADMIN_ENABLED` affects only the gated actions.

| Endpoint/action | HTTP method | Public/Admin | Authentication | Authorization | CSRF | Availability |
|---|---|---|---|---|---|---|
| `setup.status`, `auth.csrf`, `auth.session` | POST | Admin, **not gated** | None | None | No | Always |
| `auth.login`, `auth.logout` | POST | Admin, **not gated** | None / credentials | None | **Yes** | Always |
| `setup.createAdmin` | POST | Admin, gated | None | None; `409` once initialized | **Yes** | Gate |
| `auth.users.list` | POST | Admin, gated | Session | `admin.manage` | No | Gate |
| `auth.users.create`, `.update`, `.enable`, `.disable`, `.delete`, `.changePassword`, `.assignAuthorization` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |
| `auth.apiKeys.list` | POST | Admin, gated | Session | `admin.manage` | No | Gate |
| `auth.apiKeys.create`, `.enable`, `.disable`, `.revoke` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |
| `auth.roles.list` | POST | Admin, gated | Session | `admin.manage` | No | Gate |
| `admin.status`, `admin.health`, `admin.system.info`, `admin.database.get`, `admin.settings.get`, `admin.backup.history`, `admin.backup.schedule` | POST | Admin, gated | Session | `admin.manage` | No | Gate |
| `admin.console.restart`, `admin.api.start`/`.stop`/`.restart`, `admin.sqlParser.start`/`.stop`/`.restart` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |
| `admin.database.connect`, `.disconnect`, `.restart`, `.test`, `.save` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |
| `admin.server.save`, `admin.cors.save`, `admin.authentication.save`, `admin.runtime.save` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |
| `admin.backup.create`, `.download`, `.preview`, `.restore`, `admin.backup.schedule.save` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |
| `admin.operational.event` | POST | Admin, gated | Session | `admin.manage` | **Yes** | Gate |

### Counts

| Category | Count |
|---|---:|
| Distinct actions | 73 (78 endpoint/action pairs) |
| Public API actions | 29 |
| Admin API actions | 49 (44 gated, 5 session actions not gated) |
| Actions requiring no authentication | 6 distinct: the 5 session actions on both APIs, plus gated, pre-initialization `setup.createAdmin` |
| Authenticated with no further permission check | 0 |
| Permission-protected actions | 67 (16 public data, 8 frontend user, 43 Admin) |
| Actions with role checks beyond permissions | 10: 3 routine actions (registry roles) and 7 frontend user mutations (persisted SA/AA policy) |
| CSRF-protected action names | 47 (13 reachable publicly, 36 on Admin; `auth.login` and `auth.logout` on both), plus the 3 routine actions when the resolved routine is registered with `access: write` (AAPI-03) |

## 3. Authentication Flow

1. **Entry checks:** method, content type, body size, JSON object
   (`RequestBodyReader`). On the public API: origin and blocked-action `404`.
   On the Admin API: action allowlist, then the gate.
2. **`AuthenticationMiddleware`** (`app/Middleware/AuthenticationMiddleware.php`)
   clears `PrincipalContext` and `GENERIC_AUTH_PROVIDER`, then:
   - **Configured public actions** (session actions; on Admin, also
     `setup.createAdmin`) return without a principal.
   - **`auth.*` and `admin.*` actions** → `requireSession()`.
   - **Mode `none`** → anonymous `Principal` with `publicRoles`, and
     provider `none`.
   - **Modes `api_key` / `session+api_key`** → `resolveApiKey()`. Managed
     `gsk_<16 hex>_<43 b64url>` keys are checked by `ApiKeyService::authenticate`
     (format, enabled, not revoked, `password_verify`, owner enabled). If that
     fails, the legacy `GENERIC_SQL_API_KEY` (at least 32 characters,
     `hash_equals`) is tried with `legacyApiKeyRoles`. A key principal has the
     key's single role and `frontendAccess=false`.
   - **Otherwise** → session.
3. **`requireSession()`:**
   - `AuthSessionService::resume()` uses only an existing cookie, then
     enforces idle and absolute expiry.
   - `isAuthenticated()` requires the userId (32 hex), username and
     authVersion (at least 1).
   - The persisted user is reloaded by id. A missing user, a disabled user, a
     username mismatch or an `authVersion` mismatch destroys the session
     (`401`).
   - The principal's roles come from the **persisted** `backendRole` and
     `frontendRole`, never from the request.
4. **Login** (`AuthService::login`):
   - The rate limiter is keyed by IP + username.
   - Unknown users are compared against a dummy bcrypt hash, so response
     timing is uniform.
   - Failures return a generic `401 INVALID_CREDENTIALS`, with the number of
     attempts remaining while the limiter is enabled; lockout returns
     `429 LOGIN_RATE_LIMITED`.
   - Outdated hashes are rehashed on success.
   - `establish()` calls `session_regenerate_id(true)` and stores the
     identity and timestamps; the controller rotates the CSRF token.
5. **Session state / logout:** `auth.session` revalidates the same way and
   returns an unauthenticated snapshot instead of an error. `auth.logout`
   destroys the session and expires the cookie.
6. **Invalidation:** every identity change increments `authVersion` (password,
   username/profile, enable/disable, authorization, frontend access), which
   invalidates all of the user's sessions on their next request. Deletion
   invalidates them because the reload by id fails.
7. **Failure handling:** each `401 AUTHENTICATION_REQUIRED` first consumes the
   unauthenticated rate-limit bucket (`429` once exceeded) and writes an
   audit event. Invalid API keys are logged by a 12-character fingerprint of
   the provided value; the key itself is never logged.

Authentication state is **established** in `AuthService::login`
(session) and `AuthenticationMiddleware` (`PrincipalContext`). It is
**consumed** by `ApiRateLimitMiddleware`,
`FrontendUserAuthorizationMiddleware`, `AdminAuthorizationMiddleware`,
`AuthorizationMiddleware`, `QuerySourcePolicy` (registry roles), and
`UserManagementService` (frontend actor). That service re-reads the actor
from storage rather than trusting the principal.

## 4. Authorization Flow

**Where:**
- `AdminAuthorizationMiddleware`: `admin.manage` for 43 Admin actions.
- `FrontendUserAuthorizationMiddleware`: `frontend.users.manage` for the 8
  public `auth.frontendUsers.*` actions.
- `AuthorizationMiddleware`: the public data actions.
- Service-level checks: `UserManagementService` frontend actor/target policy,
  `QuerySourcePolicy`, `SqlRepository` filter sources, and `RoutineResourceRegistry`.

**Identity passed:** the `Principal` from `PrincipalContext`, built only by
`AuthenticationMiddleware`.

**Roles and permissions:**
- `Principal::roles()` = the non-null `backendRole` and `frontendRole`.
- `AuthorizationService::authorizeInternal` looks up each role in
  `config/authorization.json` (re-read and validated on every check) and
  allows the request if any role grants the permission. For `sql` and
  `write` scopes, at least one granting role must also list the resource or
  `*`.
- The permission list cached on the `Principal` is not used for these
  decisions.

**Permissions** (closed set validated by `AuthorizationRepository`):
`admin.manage`, `frontend.read`, `frontend.users.manage`, `data.read`,
`data.write`, `metadata.read`, `sql.execute`, `routine.execute`.

**Special rules:**
- `frontend.read` is granted to any principal with `frontendAccess=true`,
  regardless of role (the accepted SSA-06 behavior).
- Routines require `routine.execute`, a role listed in the registry entry,
  and `data.write` for write routines; `frontend.read` never qualifies.
- `AuthorizationMiddleware` falls back to `admin.manage` for any action it
  does not recognize, so it denies by default.

**Answers to the specific questions:**

1. **Where authorization occurs:** in the middlewares listed above, plus the
   service-level checks.
2. **Identity passed:** the server-built `Principal`.
3. **How roles are resolved:** from the persisted user record (session) or
   the key record (API key).
4. **How permissions are resolved:** from `authorization.json`, at the time
   of each check.
5. **Default-deny:** yes. Unknown permission, role, resource or action all
   result in a denial.
6. **Missing identity:** `401 AUTHENTICATION_REQUIRED` (all three
   authorization middlewares check for a null principal).
7. **Missing permission:** `403 AUTHORIZATION_DENIED`.
8. **Missing role or scope:** `403 RESOURCE_ACCESS_DENIED` for resource,
   routine and query-source scope; `403 AUTHORIZATION_DENIED` for frontend
   actor/target policy.
9. **Bypass by changing request fields:** not found.
   - Role and permission fields are not read from requests.
   - Validators reject unknown properties.
   - `action` is compared strictly (`in_array(..., true)`).
   - Controller and method names come from the normalizer, not from raw
     input.

## 5. Public API Boundary

| Capability | Public API status | Classification |
|---|---|---|
| `setup.status` | Unauthenticated; returns only `initialized` | Intentionally public |
| `auth.csrf`, `auth.login`, `auth.session`, `auth.logout` | Unauthenticated | Intentionally public |
| `auth.frontendUsers.*` | Session + `frontend.users.manage`; frontend-managed identities only | Intentionally protected (scope narrowed by the AAPI-01/AAPI-02 remediation) |
| Admin actions (`admin.*`) | `404` | Intentionally unavailable |
| Backend identity, API key and role management (`auth.users.*`, `auth.apiKeys.*`, `auth.roles.list`) | `404` | Intentionally unavailable (SSA-04); `auth.frontendUsers.*` no longer reaches backend identities (AAPI-01, AAPI-02 resolved) |
| `setup.createAdmin` | `404` | Intentionally unavailable (SSA-02) |
| Routine execution | Registry + `routine.execute` (shipped registry is empty) | Intentionally protected; CSRF for registered write routines (AAPI-03 resolved) |
| SQL Resources (`sql`) | `sql.execute` + scope, or `frontend.read` | Intentionally protected |
| JSON queries and metadata | `data.read` / `metadata.read` / `frontend.read` + query-source registry | Intentionally protected |
| Writes | `data.write` + `writeResources` + CSRF for sessions | Intentionally protected |
| Database or application configuration, backup/restore, availability controls, detailed health | Not routed | Intentionally unavailable (Admin only) |

No unexpectedly reachable Admin or configuration action was found on the
public API. The inventory found unexpected reachability only for identity
management through `auth.frontendUsers.*`; that path now refuses backend
identities for every actor (Section 17).

## 6. Admin API Boundary

**Middleware order:**
1. Action allowlist.
2. `LocalAdminMiddleware` (gate).
3. `AuthenticationMiddleware` (session).
4. `ApiRateLimitMiddleware`.
5. `AdminAuthorizationMiddleware` (`admin.manage`).
6. `CsrfProtectionMiddleware`.
7. Validator and controller.

| Condition | Gated actions (44) | Session actions (5) |
|---|---|---|
| `GENERIC_ADMIN_ENABLED` unset or not `1` | `404 NOT_FOUND` | Reachable |
| Non-loopback `REMOTE_ADDR` | `404 NOT_FOUND` | Reachable |
| Unauthenticated | `setup.createAdmin` reachable (pre-initialization); other 43 → `401` | Reachable |
| Authenticated, no `admin.manage` (read-only, data-operator, AA) | `403 AUTHORIZATION_DENIED` | Reachable |
| Missing or invalid CSRF on a protected action | `403 CSRF_VALIDATION_FAILED` (checked after authorization) | `auth.login`/`auth.logout` → `403` |
| API key only | `401` (Admin never accepts keys) | n/a |

Notes:
- Proxy headers are not trusted. Only `REMOTE_ADDR` is used; same-host
  proxies are forbidden by the deployment documentation (SSA-08).
- The 5 session actions are reachable on the Admin API without the gate, so
  an Admin login is possible when `GENERIC_ADMIN_ENABLED=0` or from a
  non-loopback address. This is intended (SSA-02/04 design) and equivalent to
  the public `auth.login`. A session alone never reaches a gated action.
  - The IIS template restricts the Admin site to `127.0.0.1` and `::1`.
  - The Nginx template serves Admin on a separate loopback-resolved host
    name, so its host-only session cookie is not sent to the public API host.
- `setup.createAdmin` needs the gate but no authentication. It returns
  `409 INSTALLATION_ALREADY_INITIALIZED` after initialization.

## 7. Role / Permission Matrix

Role permissions from the validated defaults (`RuntimeConfiguration::authorizationDefaults`
and the review installation). All roles have `sqlResources: ["*"]`; write
scope is `["*"]` for data-operator, system-administrator and
api-administrator, and `[]` otherwise.

| Role | Domain | Assignable to | `admin.manage` | `frontend.users.manage` | `data.read` | `data.write` | `metadata.read` | `sql.execute` | `routine.execute` | `frontend.read` |
|---|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| read-only | backend | users, keys, `publicRoles` | — | — | ✓ | — | ✓ | ✓ | ✓ | — |
| data-operator | backend | users, keys | — | — | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| system-administrator | backend | users | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| api-administrator | backend | keys only | — | — | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| application-administrator | frontend | users (`frontendRole`) | — | ✓ | — | — | — | — | — | ✓ |
| *any user with `frontendAccess=true`* | — | — | — | — | — | — | — | — | — | ✓ (implicit) |

`AuthorizationRepository::validate` enforces invariants:
- read-only, data-operator, api-administrator and AA never hold
  `admin.manage`;
- read-only and AA never hold `data.write`;
- SA must hold `admin.manage`;
- api-administrator never holds `frontend.users.manage`.

`publicRoles` and `legacyApiKeyRoles` accept `read-only` or `data-operator`; `system-administrator` is rejected (AAPI-08).

**Minimum privilege per action:**

| Action | Public | Authenticated | `frontend.read` | `data.read` | `metadata.read` | `sql.execute` | `routine.execute` | `data.write` | `frontend.users.manage` | `admin.manage` |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Session actions, `setup.status` | ✓ | | | | | | | | | |
| `select`/`union`/`unionAll` | | ✓ | either | either | | | | | | |
| `metadata.*` | | ✓ | either | | either | | | | | |
| `sql` | | ✓ | either | | | either + scope | | | | |
| `procedure`/`function`/`tableFunction` | | ✓ | | | | | ✓ + registry role | write routines | | |
| `insert`/`update`/`delete`/`upsert` | | ✓ | | | | | | ✓ + scope | | |
| `auth.frontendUsers.*` | | session | | | | | | | ✓ + persisted SA/AA | |
| `setup.createAdmin` | gate, pre-init | | | | | | | | | |
| `auth.users.*`, `auth.apiKeys.*`, `auth.roles.list`, `admin.*` | | session + gate | | | | | | | | ✓ |

## 8. Authentication & Authorization Test Cases

All cases use isolated runtime directories (`GENERIC_RUNTIME_CONFIG_DIR`,
`GENERIC_LOG_DIR`) and synthetic identities created by the test, following
the existing suites. No real account is modified.

| Case | Setup | Expected |
|---|---|---|
| No authentication | Session mode, no cookie, data action | `401 AUTHENTICATION_REQUIRED` |
| Insufficient permission | read-only session → `insert` | `403 AUTHORIZATION_DENIED` |
| Wrong role / scope | read-only with `sqlResources` restricted → other resource | `403 RESOURCE_ACCESS_DENIED` |
| Expired session | Idle and absolute timeouts at minimum; clock advanced | `401`; session destroyed |
| Invalid session | Random or uninitialized cookie (strict mode) | `401` |
| Changed `authVersion` | Second session after password/role/profile change | `401` for the old session |
| Missing CSRF | Session `insert` / `auth.frontendUsers.create` without header | `403 CSRF_VALIDATION_FAILED` |
| Invalid CSRF | Wrong 64-hex token, short token, token from another session | `403 CSRF_VALIDATION_FAILED` |
| Invalid API key | Malformed, unknown id, wrong secret | `401` |
| Revoked / disabled key, disabled owner | Key state changed in the fixture | `401`; revoked cannot be re-enabled (`409`) |
| Wrong key scope | read-only key → `insert`; scoped data-operator key → other resource | `403` |
| SA accessing frontend-only action | SA session → `auth.frontendUsers.list` | Allowed (SA holds `frontend.users.manage`) |
| Frontend user → admin action | AA session → public `admin.*` | `404`; Admin API → `403` |
| API key → identity management | Valid key → `auth.frontendUsers.*` | `401` (session required) |

## 9. IDOR / Resource Authorization

| Identifier | Accepted by | Object-level check | Assessment |
|---|---|---|---|
| `username` | `auth.users.*` (Admin) | `admin.manage` covers all users; self-authorization change denied; last-SA guard | Adequate for a single admin tier |
| `username` | `auth.frontendUsers.*` (public) | Actor re-read from storage; backend identities refused for every actor; AA peers protected from role change; missing targets refused like protected ones | Adequate (AAPI-01, AAPI-02, AAPI-04 resolved); uniqueness `409` on create/rename accepted (AAPI-04) |
| API key `id` (16 hex, random) | `auth.apiKeys.enable/disable/revoke` | `admin.manage`; no per-owner scoping (single admin tier) | Adequate |
| API key `ownerUsername` | `auth.apiKeys.create` | Owner must exist and be enabled; key role is independent of the owner's role | Accepted design (AAPI-06) |
| Role IDs | `auth.users.create/assignAuthorization`, `auth.apiKeys.create`, `auth.frontendUsers.create/assignRole` | Enumerated against `RoleModel` per domain | Adequate |
| SQL Resource `resource` | `sql` | `sqlResources` scope; discovery allowlist; bypassed by `frontend.read` (SSA-06) | Accepted limitation |
| Write `resource` | `insert`/`update`/`delete`/`upsert` | `writeResources` scope + write registry | Adequate |
| Routine name | `procedure`/`function`/`tableFunction` | Registry entry + role list | Adequate |
| Table / view names | JSON queries, metadata | Query-source registry (SSA-03) | Adequate within SSA-03 boundary |
| `recoveryPointId`, `filename` | `admin.backup.download/preview` | Strict regex; SA only | Adequate |
| `uploadToken` (48 hex, random) | `admin.backup.restore` | Capability token, not bound to session; SA + gate only | Adequate; test token-format rejection |

There is no per-user object ownership in the data model (queries are not
owned). IDOR risk is therefore concentrated in identity management.

## 10. HTTP Method and Content-Type Security

- **Method:** both APIs reject every method except `POST` before any
  dispatch. The public API answers `OPTIONS` with `204` after the origin
  check and before any session or body processing. The Admin API answers
  `OPTIONS` with `405`. Changing the method cannot bypass anything: there
  is no alternative routing by method or query string.
- **Content-Type:** the media type must be exactly `application/json`
  (parameters such as `charset` are allowed). Form and multipart posts
  cannot reach dispatch. Combined with the origin allowlist, this forces a
  CORS preflight for cross-origin browser requests.
- **Body:**
  - The size limit is enforced while reading (`413`).
  - Malformed JSON → `400 INVALID_JSON`; a scalar → `400 INVALID_REQUEST`.
  - The Admin API also rejects a JSON list. The public API accepts a list
    such as `[]`: it is never dispatched to an action and fails
    authentication or authorization (AAPI-07, informational).
- **Unexpected parameters:** auth, setup, frontend user, user management,
  API key, role and Admin validators reject unknown properties. Query
  validators allowlist keys per action.
- **Action type confusion:** non-string actions are cast or compared
  strictly. Unknown actions → `404` (Admin, blocked public prefixes) or
  authorization/validation errors (other public actions).

## 11. Error and Status Code Behavior

| Condition | Status | Code | Notes |
|---|---:|---|---|
| Disallowed `Origin` (public) | 403 | `CORS_ORIGIN_DENIED` | Before method check, including `OPTIONS` |
| Non-POST | 405 | `METHOD_NOT_ALLOWED` | |
| Non-JSON content type | 415 | `UNSUPPORTED_MEDIA_TYPE` | |
| Oversized body | 413 | `REQUEST_TOO_LARGE` | |
| Malformed JSON / non-object | 400 | `INVALID_JSON` / `INVALID_REQUEST` | |
| Blocked public action (`admin.*`, backend identity) | 404 | `NOT_FOUND` | Before runtime and authentication checks |
| Unknown Admin action | 404 | `NOT_FOUND` | |
| Admin gate closed (flag or non-loopback) | 404 | `NOT_FOUND` | Hides the existence of Admin actions |
| Public API disabled (production) | 503 | `SERVICE_UNAVAILABLE` | Before authentication |
| API-key mode without configured keys | 503 | `AUTHENTICATION_UNAVAILABLE` | |
| Unauthenticated / invalid key / expired or invalidated session | 401 | `AUTHENTICATION_REQUIRED` | Same code for all causes |
| Unauthenticated rate limit / API rate limit | 429 | `RATE_LIMIT_EXCEEDED` | |
| Login failure / lockout | 401 / 429 | `INVALID_CREDENTIALS` / `LOGIN_RATE_LIMITED` | |
| Missing permission | 403 | `AUTHORIZATION_DENIED` | |
| Resource, routine or query-source scope | 403 | `RESOURCE_ACCESS_DENIED` | |
| CSRF missing or invalid | 403 | `CSRF_VALIDATION_FAILED` | Public: after frontend-user authorization, before data authorization. Admin: after authorization |
| Last enabled SA | 403 | `LAST_ENABLED_ADMIN` | |
| Target user missing | 404 / 403 | `USER_NOT_FOUND` / `AUTHORIZATION_DENIED` | `404` on the Admin API; the public frontend path refuses a missing target like a protected one (AAPI-04) |
| Setup after initialization | 409 | `INSTALLATION_ALREADY_INITIALIZED` | |
| Database unavailable | 503 | `DATABASE_UNAVAILABLE` | Only after authorization (public data actions) |

The intended convention is:
- `404` for anything outside the caller's boundary (blocked public actions,
  closed Admin gate);
- `401` for a missing or invalid identity;
- `403` for an authenticated identity without the permission, scope or CSRF
  token.

## 12. Privilege Escalation Review

| Path | Controls | Result |
|---|---|---|
| Public → any user | Registration does not exist; `setup.createAdmin` needs the gate and pre-initialization | No path |
| Public (mode `none`) → write | Anonymous principal gets `publicRoles` (default read-only) | No path by default; anonymous write requires an explicit operator configuration; anonymous SA rejected (AAPI-08) |
| Frontend user (no role) → AA | `assignRole` to AA denied to AA actors; only SA can grant | No path |
| AA → SA | SA targets protected; `createFrontendUser` limited to AA and read-only roles; `system-administrator` assignment denied | No path |
| AA → data-operator / read-only backend account | Public path refuses backend identities (`isFrontendManagedIdentity`) | **Closed** (AAPI-01 resolved). Before the fix, AA could reset a backend-only data-operator password and log in as that user |
| AA → peer AA account | Peer profile/password/lifecycle allowed by design | Lateral takeover is possible and tested as intended. Note for review |
| SA (remote, public API) → other SA identities | Backend-identity check runs before the SA actor shortcut | **Closed** (AAPI-02 resolved); SA accounts are managed only through the loopback Admin API |
| Self → higher role | `assignAuthorization` self-change denied; AA self lifecycle/authorization denied | No path |
| API key → admin | Key roles exclude SA and AA; Admin never accepts keys; `auth.*` needs a session | No path |
| API key → broader than owner | Key role is chosen by the SA and is not bounded by the owner's role | Accepted design (AAPI-06) |
| Admin → unrestricted backend | SA is the top role. Bounded by the query-source registry, routine registry, write registry and read-only SQL Resources | Intended |
| Configuration → privilege | `authorization.json` is file-only (no API writes it); validation enforces role invariants | No API path |

## 13. CSRF Review

- **Token generation:** `CsrfTokenService::token()` returns a 64-hex token
  (32 random bytes) stored in the session. It is returned by `auth.csrf`,
  and rotated on login (`X-CSRF-Token` response header).
- **Validation:** `CsrfProtectionMiddleware` checks the `X-CSRF-Token` header
  with `hash_equals`, for a fixed list of 47 action names.
- **Ordering:**
  - Public: after authentication and frontend-user authorization, but before
    data authorization.
  - Admin: after `admin.manage` authorization.
  - Both orders fail closed.
- **API key and `none` exceptions:** CSRF is skipped when the request was
  authenticated by an API key or in mode `none`. This is intended: a key is
  not an ambient browser credential, and mode `none` has no session. The
  provider flag is set only by `AuthenticationMiddleware`. `auth.*` and
  `admin.*` always use sessions, so CSRF always applies to protected
  identity and Admin actions.
- **GET:** cannot trigger anything (`405`).
- **Gaps:**
  - Resolved (AAPI-03): registered write routines (`access: write`) now
    require CSRF for session callers. Read routines stay unprotected, like
    `select`.
  - Read-only Admin actions (`admin.database.get`, `admin.settings.get`, etc.)
    are unprotected by design; they return data only to a same-origin caller.
- **Additional browser defenses:** SameSite=Lax cookies, the
  `application/json`-only content type, and the origin allowlist (public
  API).

## 14. Session Security

**Cookie:** `generic_reporting_session` with:
- path `/`, host-only domain, lifetime 0;
- `HttpOnly`;
- `SameSite=Lax`;
- `Secure` in production or on direct HTTPS.

**PHP session settings**, enforced and failing closed if not applied:
`use_strict_mode=1`, `use_only_cookies=1`, `use_trans_sid=0`, and
`gc_maxlifetime` equal to the absolute timeout.

**Behavior:**
- **Regeneration:** on login (`session_regenerate_id(true)`), followed by
  CSRF rotation.
- **Expiration:** idle (minimum 60 s, default 1800 s) and absolute (minimum
  300 s, default 28800 s), checked on every resume. Expiry destroys the
  session and writes an audit event.
- **Logout:** clears `$_SESSION`, expires the cookie and destroys the
  session.
- **`authVersion`:** checked on every authenticated request against the
  persisted user.
- **Concurrent sessions:** allowed. All of a user's sessions are revoked
  together by an `authVersion` change. Concurrent writes are covered by
  existing workers.
- **Anonymous sessions:** `auth.csrf` and `auth.login` create a pre-auth
  session that holds only the CSRF token. An anonymous session never yields a
  principal.

**Authorization implications:**
- Password changes through `auth.frontendUsers.changePassword` (own or
  others') do not require the current password. Every change revokes all of
  the account's sessions, including the session that made it. The residual
  owner-lockout risk from a hijacked administrator session is accepted
  (AAPI-05).
- Admin and public sessions share a cookie name. They are separated only when
  Admin is served on a different host (as in the deployment templates).

## 15. Existing Security Test Coverage

14 existing suites cover authorization and API security: `ApiProtectionTest`,
`AuthenticationFlowTest`, `AuthenticationFoundationTest`,
`AuthorizationAndApiKeyTest`, `AdminUserManagementTest`, `FirstTimeSetupTest`,
`FrontendUserMutationAuthorizationTest`, `SecurityHardeningTest`,
`SecurityTestingTest`, `SecurityAuditLoggingTest`,
`StaticSecurityRemediationTest`, `HttpsSecurityTest`,
`UnifiedAdminConsoleTest`, `ProductionErrorHandlingTest`.

| Security Area | Existing Test | Coverage | Gap |
|---|---|---|---|
| Public blocks for `admin.*`, `setup.createAdmin`, backend identity | `StaticSecurityRemediationTest` (HTTP) | Representative actions | Not every one of the 29 `admin.*` names and all 14 identity actions |
| Admin gate (flag, loopback) | `StaticSecurityRemediationTest`, `UnifiedAdminConsoleTest` | Flag off / non-loopback samples | Full matrix of 44 gated actions × conditions |
| `admin.manage` enforcement | `AdminUserManagementTest`, `SecurityTestingTest` | Role checks at service and middleware level | HTTP matrix for every Admin action and non-admin role |
| Login, generic failures, regeneration | `SecurityTestingTest`, `AuthenticationFlowTest` | Good | — |
| Session expiry, logout replay, `authVersion` | `SecurityTestingTest`, `ApiProtectionTest`, `SecurityHardeningTest` | Good | Rename and frontend-access changes as invalidation triggers |
| CSRF | `SecurityTestingTest`, `SecurityHardeningTest`, `AuthorizationAndApiKeyTest` | Rotation, API-key independence, samples; write routines (`ApiSecurityHardeningTest`) | Every one of the 47 protected actions over HTTP |
| API keys | `SecurityTestingTest`, `AuthorizationAndApiKeyTest`, `SecurityAuditLoggingTest` | Secret handling, owner state, revoke, role confinement | Mode matrix (`api_key`, `session+api_key`, legacy key) over HTTP |
| Role and resource scopes | `SecurityTestingTest`, `SqlResourceFilteringTest`, `StaticSecurityRemediationTest` | `sql`/`write` scopes, query sources, routines | — |
| Frontend user policy | `FrontendUserMutationAuthorizationTest`, `AuthorizationBoundaryTest` (HTTP), `SecurityTestingTest` | AA self/peer/normal/SA targets; backend-only targets and SA → SA refused publicly (AAPI-01/02); profile minimization and uniform refusals (`ApiSecurityHardeningTest`, AAPI-04) | — |
| Client-supplied identity fields | `SecurityTestingTest` | Server identity wins | — |
| Method / content type / JSON | `ProductionErrorHandlingTest`, `SecurityTestingTest` | Error mapping, safe methods | HTTP `405`/`415`/`INVALID_JSON` on both APIs; public JSON list |
| CORS | `SecurityTestingTest`, `ProductionValidationTest` | Configuration validation | HTTP preflight and disallowed-origin `403` |
| Rate limiting | `SecurityHardeningTest`, `SecurityTestingTest`, `UnifiedAdminConsoleTest` | Login, API, unauthenticated | — |
| Setup | `FirstTimeSetupTest`, `StaticSecurityRemediationTest` | Concurrency, gate | — |

## 16. v2.1.3 Test Plan

Each test uses isolated runtime directories and synthetic identities. HTTP
tests use the existing PHP built-in server pattern. Tests marked
**(finding)** are expected to fail until the related finding is remediated
or accepted.

### P0 — Critical authorization boundaries

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P0-01 | Public API never exposes Admin actions | All 29 `admin.*` names on `/api` | None, read-only, SA session | n/a | Valid POST JSON | `404 NOT_FOUND` for every name and identity | Admin boundary |
| P0-02 | Public API never exposes backend identity management | `setup.createAdmin`, 8 `auth.users.*`, 5 `auth.apiKeys.*`, `auth.roles.list` | None, SA session | n/a | Valid POST JSON | `404` | SSA-02/04 regression |
| P0-03 | Data actions require authentication | All 16 public data actions | None | Mode `session` | No cookie | `401 AUTHENTICATION_REQUIRED` | Authentication required |
| P0-04 | Gate blocks when Admin is disabled | 44 gated Admin actions | SA session | `admin.manage` | Loopback, `GENERIC_ADMIN_ENABLED` unset and `0` | `404` | Feature flag |
| P0-05 | Gate blocks non-loopback | 44 gated Admin actions | SA session | `admin.manage` | Flag `1`, non-loopback `REMOTE_ADDR` | `404` | Loopback boundary |
| P0-06 | Admin requires a session | 43 `admin.manage` actions | None | `admin.manage` | Gate open | `401` | Authentication |
| P0-07 | Admin requires `admin.manage` | 43 `admin.manage` actions | read-only, data-operator, AA sessions | `admin.manage` | Gate open, valid CSRF | `403 AUTHORIZATION_DENIED` | Vertical access control |
| P0-08 | Admin allowlist | Unknown action names | SA session | n/a | Gate open | `404` | Default deny |
| P0-09 | Identity management never accepts API keys | 8 `auth.frontendUsers.*` | Valid key of each role | Session | Modes `api_key`, `session+api_key` | `401` | Session-only identity management |
| P0-10 | Frontend user management requires its permission | 8 `auth.frontendUsers.*` | read-only, data-operator, frontend user without role | `frontend.users.manage` | Valid CSRF | `403` | Permission check |

### P1 — Privilege escalation

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P1-01 | AA cannot take over backend-only accounts (AAPI-01, implemented) | `auth.frontendUsers.changePassword` | AA session | Policy | Target data-operator / read-only with `frontendAccess=false` | `403`; target hash unchanged | Cross-domain escalation |
| P1-02 | AA cannot change lifecycle or profile of backend-only accounts (AAPI-01, implemented) | `.update`, `.enable`, `.disable`, `.delete`, `.assignRole` | AA session | Policy | Same targets | `403`; target unchanged | Account integrity |
| P1-03 | SA identity management stays on the Admin boundary (AAPI-02, implemented) | `auth.frontendUsers.changePassword`/`.delete`/`.disable`/`.update`/`.assignRole` on `/api` | SA session | `admin.manage` via Admin API | Target is another SA or the actor itself | `403 AUTHORIZATION_DENIED`; the Admin API path succeeds | SSA-04 boundary |
| P1-04 | AA cannot create privileged users | `auth.frontendUsers.create` | AA session | Policy | `role` = SA, data-operator, api-administrator; both `role` and `frontendRole` | `400` | Role assignment confinement |
| P1-05 | AA cannot promote or self-mutate | `.assignRole`, `.enable/.disable/.delete` on self | AA session | Policy | AA role to a normal user; self lifecycle | `403` | No self-escalation |
| P1-06 | Client identity fields are ignored or rejected | Data actions, `auth.frontendUsers.*` | read-only session | n/a | Extra `backendRole`, `frontendRole`, `roles`, `permissions`, `userId` | `400` unknown property, or no effect on the principal | Server-side identity |
| P1-07 | No self-authorization change; last SA protected | `auth.users.assignAuthorization`, `.disable`, `.delete` | SA session | `admin.manage` | Self target; last enabled SA | `403` / `403 LAST_ENABLED_ADMIN` | Lockout and escalation guard |
| P1-08 | API key role confinement | `auth.apiKeys.create` | SA session | `admin.manage` | Roles SA, AA, two roles, empty | `400` | Key privilege ceiling |
| P1-09 | No API key reaches Admin | `/api` `admin.*`; Admin API actions | api-administrator key | n/a | Header only | `404` (public), `401` (Admin) | Key/Admin separation |
| P1-10 | Authorization invariants | `AuthorizationRepository::validate` | n/a | n/a | `admin.manage` added to non-SA roles; `data.write` to read-only/AA; `publicRoles` = SA | Rejected; SA in `publicRoles`/`legacyApiKeyRoles` rejected (AAPI-08, implemented) | Configuration integrity |

### P1 — Admin API protection

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P1-11 | Setup cannot run twice | `setup.createAdmin` | None | Gate | Initialized installation | `409 INSTALLATION_ALREADY_INITIALIZED`; store unchanged | One-time bootstrap |
| P1-12 | Admin session actions never unlock gated actions | `auth.login` then `admin.status` | SA | Gate | Flag `0` | Login succeeds; `admin.status` → `404` | Gate independence from session |
| P1-13 | Admin CSRF on every protected action | 36 Admin CSRF actions | SA session | `admin.manage` | No / invalid / foreign-session token | `403 CSRF_VALIDATION_FAILED`; no state change | CSRF |
| P1-14 | Gate precedes authentication | Gated actions | Valid SA cookie + CSRF | Gate | Non-loopback | `404` (not `401`/`403`) | Boundary concealment |
| P1-15 | Proxy headers ignored | Gated actions | SA session | Gate | Non-loopback with `X-Forwarded-For`/`X-Real-IP`/`Forwarded: 127.0.0.1` | `404` | No header-based trust (SSA-08) |

### P1 — API authentication

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P1-16 | Invalid keys rejected | `select` | Invalid key | `data.read` | Malformed, unknown id, wrong secret, empty header | `401` | Key authentication |
| P1-17 | Revoked or disabled keys rejected | `select` | Revoked / disabled key | `data.read` | Mode `api_key` | `401`; enabling a revoked key → `409` | Key lifecycle |
| P1-18 | Disabled owner disables the key | `select` | Key of a disabled owner | `data.read` | Mode `api_key` | `401` | Owner coupling |
| P1-19 | No configured keys fails closed | `select` | Any key | n/a | Mode `api_key`, no keys | `503 AUTHENTICATION_UNAVAILABLE` | Fail-closed |
| P1-20 | Mixed mode fallback | `select` | Invalid key + valid session; invalid key only | `data.read` | Mode `session+api_key` | Session principal used; key-only → `401` | Deterministic resolution |
| P1-21 | Mode `none` is least privilege | `select`, `insert`, `auth.frontendUsers.list` | Anonymous | `publicRoles` | Mode `none` | `select` allowed; `insert` `403`; frontend users `401` | Anonymous ceiling |
| P1-22 | Legacy key | `select` | `GENERIC_SQL_API_KEY` | `legacyApiKeyRoles` | Shorter than 32 characters; correct; wrong | Short or wrong → `401`; correct → read-only principal | Legacy compatibility |
| P1-23 | Key scope enforcement | `insert`, `sql` | read-only key; scoped data-operator key | `data.write` / `sql.execute` | Out-of-scope resource | `403` / `403 RESOURCE_ACCESS_DENIED` | Resource scopes |

### P2 — CSRF/session

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P2-01 | Public CSRF on all 13 protected actions | Login, logout, 7 frontend user mutations, 4 writes | Session | Per action | No / invalid token | `403 CSRF_VALIDATION_FAILED`; no state change | CSRF |
| P2-02 | API-key requests are exempt from CSRF | `insert` | data-operator key | `data.write` | No CSRF header | Allowed (documented exception) | Intended exception |
| P2-03 | Write routines require CSRF for sessions (AAPI-03, implemented) | `procedure` (registered write routine fixture) | data-operator session | `routine.execute` + `data.write` | No CSRF header | `403 CSRF_VALIDATION_FAILED` | CSRF completeness |
| P2-04 | Login CSRF and rotation | `auth.login` | Anonymous | n/a | No token; then token reused after login | `403`; old token rejected after rotation | Login CSRF |
| P2-05 | Session fixation | `auth.login` | Anonymous with attacker-chosen cookie | n/a | Strict mode | New session ID; unknown IDs not adopted | Fixation |
| P2-06 | Expiry | Data action | Session | n/a | Idle and absolute limits exceeded | `401`; session destroyed | Expiration |
| P2-07 | `authVersion` triggers | Data action | Second session | n/a | Password, role, profile/rename, enable/disable, frontend access change | `401` | Revocation |
| P2-08 | Logout replay | Data action | Logged-out cookie | n/a | Replay | `401` | Logout |
| P2-09 | Cookie attributes | Login response | n/a | n/a | Development, HTTPS, production | `HttpOnly`, `SameSite=Lax`, `Secure` when required | Cookie hardening |
| P2-10 | No state change before POST dispatch | All actions | Session | n/a | `OPTIONS`, `GET` | `204` (public `OPTIONS`) / `405`; session and stores untouched | Method safety |

### P2 — IDOR/resource authorization

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P2-11 | SQL Resource scope | `sql` | read-only scoped; frontend-access user | `sql.execute` / `frontend.read` | Out-of-scope resource | `403` (scoped); allowed for frontend access (SSA-06, pinned) | Resource authorization |
| P2-12 | Write scope | `insert`/`update`/`delete`/`upsert` | Scoped data-operator | `data.write` | Out-of-scope resource | `403 RESOURCE_ACCESS_DENIED` | Resource authorization |
| P2-13 | Routine registry roles | `procedure` | Role not listed | `routine.execute` | Registered routine | `403 RESOURCE_ACCESS_DENIED` | Routine authorization |
| P2-14 | Query-source registry | `select`, joins, `metadata.columns` | read-only | `data.read` | Unregistered table | `403 RESOURCE_ACCESS_DENIED` | SSA-03 regression |
| P2-15 | API key identifiers | `auth.apiKeys.enable/disable/revoke` | SA | `admin.manage` | Unknown id; malformed id | `404 API_KEY_NOT_FOUND` / `400` | Object lookup |
| P2-16 | Backup identifiers | `admin.backup.download/preview/restore` | SA | `admin.manage` | Traversal, wrong format, unknown token | `400` / `404`; no file outside the backup directory | Path and object safety |
| P2-17 | Username case variants | `auth.frontendUsers.*` | AA | Policy | SA username in different case | `403` | Case-insensitive protection |
| P2-18 | Username enumeration (AAPI-04, implemented) | `auth.frontendUsers.changePassword` | AA | Policy | Unknown vs existing backend-only username | Identical `403 AUTHORIZATION_DENIED` | Enumeration resistance |

### P2 — HTTP method/content validation

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P2-19 | Methods | Both APIs | Any | n/a | `GET`, `PUT`, `PATCH`, `DELETE`; Admin `OPTIONS` | `405` | Method enforcement |
| P2-20 | Content type | Both APIs | Any | n/a | Missing, `text/plain`, form, multipart; JSON with `charset` | `415`; JSON with charset accepted | Content-type enforcement |
| P2-21 | JSON body | Both APIs | Any | n/a | Malformed, empty, scalar, list | `400`; public list body never dispatched (AAPI-07, implemented) | Input validation |
| P2-22 | Unknown properties | Auth, frontend user, Admin validators | Authorized | Per action | Extra property | `400` | Strict schemas |
| P2-23 | Action type confusion | Both APIs | Any | n/a | `action` as array, number, null, padded string | `400`/`404`; no PHP warning | Robust dispatch |
| P2-24 | CORS | Public API | Any | n/a | Disallowed `Origin` on `POST` and `OPTIONS` | `403 CORS_ORIGIN_DENIED` | Origin allowlist |
| P2-25 | Body limits | Public API, Admin API | Any | n/a | Above the public limit; above the Admin limit | `413` | Resource limits |

### P3 — Error/status behavior

| ID | Objective | Endpoint/action | Identity | Required privilege | Request condition | Expected result | Security property |
|---|---|---|---|---|---|---|---|
| P3-01 | Status matrix | All rejection paths in §11 | Per row | Per row | Per row | Exact status and code | Stable contract |
| P3-02 | Runtime gate precedence | Public actions | None | n/a | Production, API disabled | `503` before `401` | Availability gate |
| P3-03 | Database state hidden from unauthorized callers | Data actions | Unauthenticated / unauthorized | n/a | Database unavailable | `401`/`403`, not `503` | Information disclosure |
| P3-04 | No internal detail in production errors | All | Any | n/a | Production, induced failures | No paths, stack traces, role configuration or SQL | Error hygiene |
| P3-05 | `401` precedes CSRF for unauthenticated data writes | `insert` | None | n/a | Session mode, no token | `401` | Ordering |
| P3-06 | Rate limits | Login, any action | Anonymous, session | n/a | Exceed limits; username case variants | `429`; no bypass by changing case | Abuse resistance |

Total proposed tests: **64** (P0: 10, P1: 23, P2: 25, P3: 6).

## 17. Potential Findings

### Remediation status

| ID | Severity | Status |
|---|---|---|
| AAPI-01 | High | **Resolved** (2026-10-06) |
| AAPI-02 | Medium | **Resolved** (2026-10-06) |
| AAPI-03 | Low | **Resolved** (2026-10-06) |
| AAPI-04 | Low | **Resolved** (2026-10-06); uniqueness conflict (`409`) on create/rename accepted |
| AAPI-05 | Informational | **Accepted / Documented** |
| AAPI-06 | Informational | **Accepted / Documented** |
| AAPI-07 | Informational | **Informational / No remediation required** |
| AAPI-08 | Informational | **Resolved** (2026-10-06) for the System Administrator role; anonymous or legacy-key write access is an accepted operator configuration |

#### Frontend identity boundary (AAPI-01, AAPI-02)

`UserManagementService::isFrontendManagedIdentity()` classifies the persisted
target account from the existing identity model:

| Persisted target | Classification | Public `auth.frontendUsers.*` |
|---|---|---|
| `backendRole = null` (frontend-only, any `frontendRole`) | Frontend identity | Manageable under the existing AA/SA actor rules |
| `backendRole` is assignable by the frontend (`FrontendCapabilityPolicy::assignableRoles()`, currently `read-only`) **and** `frontendAccess = true` | Frontend identity | Manageable under the existing actor rules |
| `backendRole = system-administrator` | Backend identity | Refused for every actor, including SA and self (`403 AUTHORIZATION_DENIED`, audit reason `backend_identity_protected`) |
| Any backend role the frontend cannot assign (currently `data-operator`), with or without frontend access | Backend identity | Refused for every actor |
| A frontend-assignable backend role **without** frontend access (backend-only `read-only`) | Backend identity | Refused for every actor |

The check runs in `assertFrontendMutationAllowed` immediately after the actor
is re-read from storage and **before** the System Administrator shortcut, so
it applies to `update`, `enable`, `disable`, `delete`, `changePassword`, and
`assignRole`. `create` cannot target an existing account (`409
USER_ALREADY_EXISTS`, case-insensitive), and validators reject backend-role
fields. Backend identities are managed through the Admin API (`auth.users.*`):
gate (`GENERIC_ADMIN_ENABLED=1` + loopback), Admin session, `admin.manage`,
and CSRF on mutations. None of those protections changed.

Behavior change to note: when an Application Administrator revokes frontend
access from a frontend `read-only` user, the account becomes backend-only;
restoring access afterwards requires the Admin API. `auth.frontendUsers.list`
lists the same accounts; AAPI-04 minimized the profiles it returns (see AAPI-04 below).

### AAPI-01 — Application Administrator can take over or alter backend-only accounts (High)

- **Status:** Resolved (High).
- **Root cause:** the public target policy protected only targets whose
  `backendRole` is `system-administrator`. It never classified the target as a
  frontend or backend identity, so Data Operator and backend-only accounts
  were treated as frontend users.
- **Remediation:** `isFrontendManagedIdentity()` (see the boundary table
  above) is enforced for every public frontend-user mutation. The decision
  uses the persisted `backendRole`, `frontendAccess`, and the frontend's
  assignable roles, never the username or request fields.
- **Authorization boundary:** an Application Administrator manages only
  frontend identities. A backend identity is refused before any change, with
  its password, username, enabled state, roles, and `authVersion` unchanged.
- **Regression tests:**
  - `FrontendUserMutationAuthorizationTest` (service level). AA and SA actors
    try a password reset, rename, enable, disable, delete, grant access, grant
    AA, and revoke access against a backend-only data-operator, a backend-only
    read-only user, a data-operator with frontend access, and a case variant;
    all are refused and the stored account is unchanged. Username collisions
    cannot replace a backend identity, and validators reject backend fields.
    Frontend `read-only` users remain manageable.
  - `AuthorizationBoundaryTest` (HTTP). An AA session gets `403` for each
    mutation against a backend Data Operator; a takeover login fails; the
    operator's original login still works; AA can still reset a frontend
    user's password, and that user can log in; missing CSRF gives `403`.
  - Mutation checks: reverting to "only System Administrator targets are
    protected", and running against the pre-fix service from `HEAD`, both
    fail both suites.

**Original finding:**

- **Endpoint/action:** public `auth.frontendUsers.changePassword`, `.update`,
  `.enable`, `.disable`, `.delete`, `.assignRole`.
- **Code path:**
  - `api/index.php` → `FrontendUserAuthorizationMiddleware`
    (`frontend.users.manage`) → `FrontendUserController::dispatch` →
    `UserManagementService::frontendMutate` / `deleteFrontendUser` →
    `assertFrontendMutationAllowed`
    (`app/Services/UserManagementService.php:344`).
  - That check protects only targets whose `backendRole` is
    `system-administrator`. It does not require the target to be a frontend
    user: there is no `frontendAccess` check, and data-operator and read-only
    backend roles are not checked.
- **Current behavior:** confirmed against the service in an isolated temporary
  store with synthetic users. An AA actor (no `data.write`, no
  `admin.manage`) acting on a data-operator user with `frontendAccess=false`
  could:
  - change the target's password (the new password verifies, and the target
    remains data-operator);
  - rename the target, grant it frontend access, disable it and delete it.
  
  The same target does not appear in `auth.frontendUsers.list`, but it can be
  addressed by username.
- **Expected behavior:** frontend user management changes only frontend
  users. Backend-only identities are managed through the Admin API.
- **Security impact:** vertical privilege escalation across the
  frontend/backend domain. An AA can reset a data-operator's password, log in
  as that user and obtain `data.write`, `sql.execute` and `routine.execute`.
  It can also disable or delete backend service users (denial of service)
  and rename them.
- **Reproduction/test plan:** P1-01, P1-02 (service level and HTTP), using
  synthetic users in an isolated runtime directory.
- **Affected permission/role:** `frontend.users.manage` held by
  `application-administrator`; targets with `data-operator` or `read-only`
  backend roles.
- **Recommended remediation:**
  - In `assertFrontendMutationAllowed`, deny non-SA actors when the target
    has a backend role other than one the frontend itself assigns, or when the
    target lacks frontend access. Define "frontend user" explicitly (for
    example, `frontendAccess === true` and `backendRole` in
    `[null, read-only]` with frontend provenance).
  - Add regression tests P1-01 and P1-02.

### AAPI-02 — System Administrator identity management is reachable through the public API (Medium)

- **Status:** Resolved (Medium).
- **Root cause:** `assertFrontendMutationAllowed` returned immediately for a
  System Administrator actor, so no target rule applied to SA sessions on the
  public path.
- **Remediation:** the backend-identity check now runs before the SA
  shortcut. System Administrator accounts (including the actor's own) and
  other backend identities are refused on the public path for SA actors too.
  SA keeps full public management of frontend identities, including
  Application Administrators.
- **Admin API boundary:** `auth.users.update`, `.enable`, `.disable`,
  `.delete`, `.changePassword`, and `.assignAuthorization` remain the only
  path for SA accounts. That path requires the action allowlist,
  `LocalAdminMiddleware` (`GENERIC_ADMIN_ENABLED=1` and loopback
  `REMOTE_ADDR`), an Admin session, `admin.manage`, and CSRF, and keeps the
  last-enabled-SA guard. None of these changed.
- **Regression tests:**
  - `FrontendUserMutationAuthorizationTest`: an SA actor's public profile,
    password, enable/disable, delete, and access changes against another SA
    and against itself are refused, and the account is unchanged. The
    last-enabled-SA guards are now exercised through the Admin-path service
    methods (`setEnabled`, `deleteUser`, `assignAuthorization`), which keeps
    their coverage.
  - `AuthorizationBoundaryTest` (HTTP):
    - An SA session on the public API gets `403` for a password reset,
      rename, disable, delete, and role change against another SA, itself,
      and a Data Operator, while it can still disable and enable a frontend
      user.
    - On the Admin API, with the flag disabled, the action returns `404`; a
      non-loopback `REMOTE_ADDR` returns `404`; an AA session gets `403`; a
      request with no CSRF token gets `403`.
    - The enabled loopback Admin API resets, renames, disables, and deletes an
      SA, and resets a Data Operator password, after which that user can log
      in.
  - Mutation check: restoring the SA shortcut before the boundary check fails
    both suites.

**Original finding:**

- **Endpoint/action:** public `auth.frontendUsers.changePassword`, `.update`,
  `.disable`, `.enable`, `.delete` with an SA actor.
- **Code path:** `assertFrontendMutationAllowed` returns immediately when the
  persisted actor is an SA (`UserManagementService.php:354`). SA holds
  `frontend.users.manage`.
- **Current behavior:** confirmed in an isolated store. Through the public
  API, without the loopback Admin gate, an SA session can reset another SA's
  password, delete another SA, disable it or rename it. Only the last
  enabled SA is protected.
- **Expected behavior:** after SSA-04, backend identity management (including
  SA accounts) is available only through the loopback Admin API.
- **Security impact:** a stolen or remotely used SA session can take over or
  remove the other SA accounts from any network location that reaches the
  public API. This defeats the purpose of the loopback-only identity
  boundary. It requires an authenticated SA, hence Medium.
- **Reproduction/test plan:** P1-03.
- **Affected permission/role:** `system-administrator` with
  `frontend.users.manage`.
- **Recommended remediation:** in the public frontend path, treat backend-role
  targets (at least SA targets, preferably all backend-only targets) as
  protected for every actor, including SA. Leave those changes to the Admin
  API. Alternatively, remove the SA early return and apply the AA target rules
  to SA actors in the public path.

### AAPI-03 — Routine actions are not CSRF-protected (Low)

- **Status:** Resolved.
- **Current behavior (before the fix):** the issue was present in code. It
  was not exploitable in the shipped configuration, because
  `config/routine-resources.php` is empty and unregistered routines are
  refused. It would have become live as soon as an operator registered a
  write routine.
- **Remediation:** `CsrfProtectionMiddleware` resolves `procedure`,
  `function`, and `tableFunction` requests through `RoutineResourceRegistry`.
  A registered routine with `access: write` requires a valid
  `X-CSRF-Token` for session callers.
  - Read routines stay unprotected, like `select`.
  - Unregistered or malformed routine requests are left to
    `AuthorizationMiddleware`, which refuses them.
  - API-key and mode-`none` callers stay exempt, as for `insert`, `update`,
    and `delete`.
  - The frontend is read-only (`FrontendCapabilityPolicy::WRITE = false`) and
    never calls write routines, so its CSRF allowlist needs no change.
- **Regression coverage:** `ApiSecurityHardeningTest`.
  - The test asserts that the shipped registry is empty, then injects an
    in-memory registry into the middleware only. Nothing is registered.
  - A write `procedure`, `function`, and `tableFunction` with a missing,
    wrong, or truncated token get `403 CSRF_VALIDATION_FAILED`; with a valid
    token they pass.
  - Read and unregistered routines pass to authorization; API-key and
    mode-`none` callers are exempt.
  - Mutation check: disabling the write-routine check fails the suite.

**Original finding:**

- **Endpoint/action:** public `procedure`, `function`, `tableFunction`.
- **Code path:** `CsrfProtectionMiddleware::PROTECTED_ACTIONS` lists `insert`,
  `update`, `delete` and `upsert`, but not the routine actions. Write
  routines (`access: write`) require `data.write`
  (`AuthorizationService::authorizeRoutine`) but no CSRF token.
- **Current behavior:** a session-authenticated write routine executes
  without `X-CSRF-Token`. The shipped routine registry is empty, so this is
  latent until an operator registers a write routine.
- **Expected behavior:** every state-changing session request requires CSRF.
- **Security impact:** CSRF defense in depth is missing for write routines.
  Exploitation is limited by SameSite=Lax cookies, the
  `application/json`-only content type and the origin allowlist (403 before
  preflight).
- **Reproduction/test plan:** P2-03 with a registered write-routine fixture.
- **Affected permission/role:** `routine.execute` + `data.write` (data-operator,
  SA, api-administrator keys are exempt by design).
- **Recommended remediation:** require CSRF for the routine actions, or at
  least for routines whose registry entry has `access: write`.

### AAPI-04 — Username enumeration and SA profile disclosure to frontend administrators (Low)

- **Status:** Resolved; one residual uniqueness signal is accepted.
- **Current behavior (before the fix):** confirmed.
  `auth.frontendUsers.list` returned SA name, mobile, and email to any
  `frontend.users.manage` holder. The public mutations returned
  `404 USER_NOT_FOUND` for missing accounts and `403` for protected ones.
- **Remediation:**
  - `backendProtected` is now `true` for every backend identity, using the
    AAPI-01 classification. Previously only SA rows were marked.
  - Backend identities keep their list row (username, roles, enabled state),
    so the existing frontend UI shape is preserved. Their `name`, `mobile`,
    and `email` are returned as `null` unless the viewer is a System
    Administrator or the account itself.
  - On the public frontend path, a missing target is refused with the same
    `403 AUTHORIZATION_DENIED` as a protected target. The Admin API keeps
    `404 USER_NOT_FOUND`.
  - Frontend-managed users keep full profiles and management.
- **Security reasoning:** Application Administrators cannot manage backend
  identities after AAPI-01/02, so they have no need for their contact
  details. A missing account no longer gives a side-effect-free existence
  check.
- **Accepted residual:** `auth.frontendUsers.create` and a rename still return
  `409 USER_ALREADY_EXISTS` when a username is taken. Usernames must be
  unique, and that check cannot be hidden without breaking the contract. The
  probe has a side effect (it creates or renames an account) and is audited.
  Login rate limiting bounds any guessing it enables.
- **Regression coverage:** `ApiSecurityHardeningTest`.
  - Service level: AA list minimization for SA and frontend-access Data
    Operator rows; full profiles for SA viewers and the account itself;
    backend-only accounts not listed; identical refusals for missing and
    protected targets across five mutations, for both AA and SA actors.
  - HTTP: the minimized SA row and an identical error object for missing and
    protected targets.
  - Mutation checks: removing minimization, restoring SA-only
    `backendProtected`, and restoring the `404` each fail the suite.

**Original finding:**

- **Endpoint/action:** `auth.frontendUsers.list` and the targeted
  `auth.frontendUsers.*` mutations.
- **Code path:**
  - `listFrontendUsers` includes `system-administrator` accounts, with name,
    username, mobile and email.
  - `frontendMutate` resolves the target (`404 USER_NOT_FOUND`) before the
    policy check (`403`).
- **Current behavior:** an AA can list SA identities and contact details and
  can probe any username, including backend-only accounts, through the
  `404`/`403` difference.
- **Expected behavior:** frontend administrators see only manageable
  frontend users. Responses for unmanageable and non-existent targets are
  indistinguishable.
- **Security impact:** helps target login attempts and AAPI-01 (backend
  usernames are otherwise not listed). Login rate limiting mitigates guessing.
- **Reproduction/test plan:** P2-18.
- **Affected permission/role:** `frontend.users.manage`.
- **Recommended remediation:** omit or minimize SA records in the frontend
  list (for example, a flag without contact details). Return the same denial
  for missing and protected targets.

### AAPI-05 — Password changes do not require the current password (Informational)

- **Status:** Accepted / Documented. No code change.
- **Current behavior by path:**
  - **Self-service:** there is no self-service path for ordinary users.
    Only `frontend.users.manage` holders (AA, and SA for frontend accounts)
    can change their own password, through
    `auth.frontendUsers.changePassword`. The request needs an authenticated
    session and a valid CSRF token.
  - **Administrator reset:** `auth.frontendUsers.changePassword` on another
    frontend account, and the Admin API `auth.users.changePassword` (gate,
    session, `admin.manage`, CSRF). These are intentionally reset operations.
  - **Setup:** `setup.createAdmin` sets only the first password, before
    initialization.
  - **Recovery:** there is no recovery or forgotten-password flow.
- **Security reasoning:**
  - Every password change increments `authVersion`, which revokes all of the
    account's sessions, including the session that made the change.
  - The flows are CSRF-protected, and cookies are `HttpOnly` and
    `SameSite=Lax`.
  - A party able to ride an administrator session can already disable,
    delete, or reset other accounts. Re-authentication on self-change alone
    would not materially reduce that exposure.
  - The shipped frontend client sends no current password
    (`auth.frontendUsers.changePassword` carries only `newPassword` and its
    confirmation), so requiring one would break the existing contract without
    a proportionate gain.
- **Accepted risk:** a hijacked administrator session can lock out the account
  owner by changing its password. Recovery is through another administrator
  or the Admin API.
- **Regression coverage:** `ApiSecurityHardeningTest`.
  - Service level: frontend and Admin resets advance `authVersion`.
  - HTTP: an AA reset revokes the target's existing session; an AA's own
    password change revokes both of its sessions, including the one that made
    the change; a revoked session cannot change a password; and the new
    password logs in.

**Original finding:**

- **Endpoint/action:** `auth.frontendUsers.changePassword` (self and others);
  Admin `auth.users.changePassword`.
- **Code path:** `FrontendUserRequestValidator` accepts only `newPassword` and
  confirmation. `changeFrontendUserPassword` does not re-authenticate.
- **Current behavior:** any authorized session can set a new password for
  itself or for a manageable target.
- **Expected behavior:** a design decision. Administrative resets commonly
  omit it; self-service changes usually require re-authentication.
- **Security impact:** a hijacked AA or SA session can lock out the account
  owner. Other sessions are revoked by the `authVersion` change.
- **Reproduction/test plan:** document the decision in a test (self change
  with and without the current password).
- **Affected permission/role:** `frontend.users.manage`, `admin.manage`.
- **Recommended remediation:** require the current password for self-service
  password changes. Keep administrative resets as they are.

### AAPI-06 — API key privileges are independent of the owner's role (Informational)

- **Status:** Accepted / Documented. No code change.
- **Current behavior:**
  - A managed key stores its single role when created. That role comes from
    `RoleModel::apiKeyRoles()` and is chosen by an SA, not derived from the
    owner.
  - At authentication, the owner must still exist and be enabled.
  - Demoting the owner or changing its password does not change the key.
  - Disabling or deleting the owner, or disabling or revoking the key, stops
    authentication.
  - A revoked key cannot be re-enabled.
- **Security reasoning:**
  - Key privileges are a separate grant made by a System Administrator.
    `api-administrator` is a key-only role that no user can hold, so bounding
    a key by its owner's role would make that role unusable and break the
    existing API contract.
  - The owner is an accountability and liveness anchor: disabling or deleting
    it revokes its keys immediately.
  - Only `admin.manage` holders, through the loopback Admin API, can create,
    disable, or revoke keys.
- **Accepted risk / operator guidance:** when an owner's trust level changes
  without the account being disabled, review and revoke that owner's keys in
  the Admin Console.
- **Regression coverage:** `ApiSecurityHardeningTest` pins the lifecycle:
  - a key keeps its role after owner demotion and password change;
  - it is refused while the owner is disabled and recovers on re-enable;
  - a disabled key is refused;
  - a revoked key is refused and cannot be re-enabled;
  - a key-only role can be issued to a lower-privileged owner;
  - a key is refused after its owner is deleted.

**Original finding:**

- **Endpoint/action:** `auth.apiKeys.create`; key authentication.
- **Code path:** `ApiKeyService::create` validates the key role against
  `RoleModel::apiKeyRoles()` only. `authenticate` checks owner existence and
  `enabled`, but not the owner's role or `authVersion`.
- **Current behavior:**
  - A read-only (or frontend-only) user can own a data-operator or
    api-administrator key.
  - Demoting the owner, or changing their password, leaves the key valid.
- **Expected behavior:** a design decision (the owner is an accountability
  label, and keys are SA-issued).
- **Security impact:** low; only an SA can create keys. Owner changes do not
  revoke keys, which may surprise operators.
- **Reproduction/test plan:** pin the decision with a test (owner demotion
  does not affect the key; disabling the owner does).
- **Affected permission/role:** `admin.manage`; key roles.
- **Recommended remediation:** either document the behavior, or bound the key
  role by the owner's role and revoke the owner's keys on authorization
  change.

### AAPI-07 — Public API accepts a JSON list body (Informational)

- **Status:** Informational / No remediation required.
- **Current behavior:**
  - `api/index.php` accepts any decoded JSON array. List support is not an
    intended feature: the error message requires an object, and the Admin API
    rejects lists.
  - A list has no string `action` key, so it never reaches an action handler.
    It is rejected by authentication (`401`) or by the `admin.manage` default
    in `AuthorizationMiddleware` (`403`).
  - Nested lists inside an object body (fields, filters, queries, parameters,
    roles) are validated per action and are unaffected.
  - The SQL Parser is non-executing and has its own request handler.
- **Security reasoning:** there is no authorization bypass, mass-assignment,
  or dispatch path: authorization fails closed before validation. Changing the
  status code would only alter error shape, so no code change was made.
- **Regression coverage:** `ApiSecurityHardeningTest` (HTTP).
  - On the public API, list bodies (`[]`, a list of objects with `action`, a
    list of action strings) get `401` anonymously and `403` with an AA session.
  - On the Admin API, the same bodies get `400 INVALID_REQUEST`.

**Original finding:**

- **Endpoint/action:** `api/index.php`.
- **Code path:** the public entry point checks `is_array` but not
  `array_is_list`. The Admin API rejects lists.
- **Current behavior:** a list body continues to authentication and is then
  rejected by action validation.
- **Expected behavior:** consistent `400 INVALID_REQUEST`, as in the Admin API.
- **Security impact:** none identified; consistency only.
- **Reproduction/test plan:** P2-21.
- **Affected permission/role:** none.
- **Recommended remediation:** add `array_is_list` to the public body check.

### AAPI-08 — `publicRoles` and `legacyApiKeyRoles` accept privileged backend roles (Informational)

- **Status:** Resolved for the clearly unsafe state. Anonymous or legacy-key
  write access is accepted as an explicit operator configuration.
- **Current behavior (before the fix):** confirmed. Validation accepted any
  backend role, including `system-administrator`, for the anonymous
  (mode `none`) and legacy shared-key principals.
- **Remediation:** `AuthorizationRepository::validate` rejects
  `system-administrator` in `publicRoles` and `legacyApiKeyRoles` ("Unsafe
  authorization role assignment."). The configuration fails closed: it is not
  loaded and health reports it invalid.
  - An unauthenticated or shared-secret identity has no legitimate use for
    `admin.manage` or `frontend.users.manage`.
  - No API writes this file, and the defaults and migrations use only
    `read-only`.
- **Not changed (accepted):** `data-operator` remains valid in either list.
  Mode `none` is itself an explicit, Admin-only choice for trusted
  deployments, and no evidence shows that anonymous write is never
  legitimate. Defaults remain `read-only`.
  - Authentication bypass is not possible: `auth.*` and `admin.*` always
    require a session, and Admin actions are never public.
  - Operators who enable mode `none` or the legacy key own the decision to
    widen those roles.
- **Regression coverage:** `ApiSecurityHardeningTest`.
  - `system-administrator`, alone or with `read-only`, is rejected in both
    lists.
  - `[]`, `read-only`, and `data-operator` are accepted.
  - The defaults remain `read-only`.
  - Mutation check: removing the rejection fails the suite.

**Original finding:**

- **Endpoint/action:** all public data actions in mode `none`; the legacy
  API key.
- **Code path:** `AuthorizationRepository::validate` accepts any backend role
  in `publicRoles` and `legacyApiKeyRoles`, including `data-operator` and
  `system-administrator`.
- **Current behavior:** defaults are `read-only`. A manual edit to
  `config/authorization.json` could grant anonymous callers `data.write`.
  `admin.*` and identity actions remain unreachable, because they are blocked
  or need a session. No API writes this file.
- **Expected behavior:** anonymous and legacy principals are limited to
  read-only.
- **Security impact:** configuration-only; requires file write access.
- **Reproduction/test plan:** P1-10.
- **Affected permission/role:** `publicRoles`, `legacyApiKeyRoles`.
- **Recommended remediation:** restrict both lists to `read-only` in
  validation, or report a production validation warning.

**Summary:** 8 potential findings. 1 High (AAPI-01), 1 Medium (AAPI-02),
2 Low (AAPI-03, AAPI-04) and 4 Informational (AAPI-05 to AAPI-08). The inventory
step fixed none of them. Final dispositions:
- **Resolved:** AAPI-01, AAPI-02, AAPI-03, AAPI-04 (with the accepted `409`
  residual) and AAPI-08 (for the System Administrator role).
- **Accepted / Documented:** AAPI-05 and AAPI-06.
- **Informational / No remediation required:** AAPI-07.

## 18. Files Inspected

- Entry points: `api/index.php`, `admin/api.php`, `sqlparser/index.php`,
  `sqlparser/router.php`.
- Middleware: `AdminAuthorizationMiddleware`, `ApiRateLimitMiddleware`,
  `ApplicationRuntimeMiddleware`, `AuthenticationMiddleware`,
  `AuthorizationMiddleware`, `CsrfProtectionMiddleware`,
  `DatabaseAvailabilityMiddleware`, `FrontendUserAuthorizationMiddleware`,
  `LocalAdminMiddleware`, `LoggingMiddleware`.
- Authorization: `Principal`, `PrincipalContext`, `RoleModel`,
  `FrontendCapabilityPolicy`, `AuthorizationService`,
  `AuthorizationRepository`.
- Authentication and session: `AuthService`, `AuthSessionService`,
  `CsrfTokenService`, `ApiKeyAuthenticator`, `ApiKeyService`,
  `LoginRateLimiter`, `ApiRateLimiter`, `SecurityConfiguration`
  (session options).
- Services and controllers: `UserManagementService`, `SetupService`,
  `AdminService` (database configuration, settings, backups),
  `BackupRecoveryService` (upload token), `AuthController`,
  `FrontendUserController`, `UserManagementController`, `ApiKeyController`,
  `RoleController`, `SetupController`, `AdminController`, and the query,
  metadata, SQL and write controller method lists.
- Validators: `FrontendUserRequestValidator`, `AuthRequestValidator`,
  `QueryRequestValidator` (action list), `AdminRequestValidator` (backup
  identifiers), `QueryRequestNormalizer` (controller mapping).
- Resources: `RoutineResourceRegistry` (types).
- Configuration: `config/authorization.example.json`; the role structure
  (not secrets) of the review installation's `config/authorization.json`;
  `RuntimeConfiguration` defaults.
- Deployment: `deployment/iis/admin.web.config.example`,
  `deployment/nginx/generic-sql-api.linux.example.conf`.
- Tests:
  - read in full or in part: `FrontendUserMutationAuthorizationTest`,
    `SecurityTestingTest` (role and API key sections);
  - keyword-mapped: the 14 suites listed in Section 15.

## 19. Conclusion

The backend's boundary is largely deny-by-default:
- **Public API:** Admin and backend identity actions are blocked with `404`
  before authentication.
- **Admin API:** an action allowlist, the loopback/feature-flag gate,
  session-only authentication and `admin.manage`, with CSRF on every
  state-changing Admin action.
- **Identity:** principals are rebuilt from persisted state on every request,
  and role and permission inputs are never read from the client.
- **Sessions and API keys:** sound, including strict mode, regeneration,
  expiry, `authVersion` revocation, and one-time hashed keys with owner
  coupling.

The material gap found by the inventory was the frontend user-management
path, now resolved for AAPI-01 and AAPI-02 (Section 17). Its target policy
protected only System Administrator targets from Application Administrators
and did not restrict System Administrators at all:
- an Application Administrator could take over backend-only data-operator
  accounts (AAPI-01, High);
- a System Administrator could manage other System Administrator identities
  from the public API, outside the loopback Admin boundary (AAPI-02, Medium).

The public path now refuses every backend identity for every actor, and
backend identities are managed only through the loopback Admin API.

The remaining findings are dispositioned:
- write routines now require CSRF (AAPI-03);
- backend identity profiles and missing-account responses no longer leak to
  frontend administrators (AAPI-04);
- the System Administrator role is rejected for anonymous and legacy-key
  principals (AAPI-08);
- password-change re-authentication (AAPI-05), API key role persistence
  (AAPI-06), and list bodies (AAPI-07) are accepted or informational, with
  regression tests pinning the behavior.

Of the 64 tests proposed in Section 16, those for the findings are
implemented (P1-01 – P1-03, P1-10, P2-03, P2-18, P2-21). The remaining
systematic P0 – P3 matrix has not been implemented, so v2.1.3 is not complete.
