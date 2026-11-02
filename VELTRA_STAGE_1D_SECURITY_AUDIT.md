# VELTRA 2.0 — Stage 1D Security & Architecture Audit

**Audit date:** 2026-11-02  
**Repository:** `/home/veltra/projekty/veltra`  
**Review type:** Static security review plus isolated PostgreSQL security, integrity, and concurrency verification
**Acceptance recommendation:** **STAGE 1D ACCEPTED.** The isolated backend suite passed; no confirmed exploitable application vulnerability was identified. Stage 1E was not started.

## 1. Executive summary

The approved Stage 1D specification and implementation report were reviewed. Stage 1D source, migrations, routes, middleware, models, authorization and identity services, configuration, and relevant tests received a read-only security review. Docker connectivity was independently verified, and the backend test suite was run against a disposable PostgreSQL environment isolated from the running application.

The source review found no high-confidence exploitable security vulnerability. The complete backend suite passed: **109 tests, 757 assertions, 0 failures, 0 errors**. A separate concurrency run passed **13 cases, 77 assertions**, including the nine administrator/security cases and four management cases. The earlier medium assurance gap (SEC-001) is closed.

**Stage 1D verification is accepted. Stage 1E was not started; this audit does not authorize or begin it.** One low residual risk remains: synchronous mail delivery can produce a timing difference to an already authorized caller (assessed below).

## 2. Scope and methodology

The audit compared source to the approved RBAC v1 specification dated 2026-11-02. The implementation report was used only to identify claims for verification. The review traced relevant HTTP routes through middleware/controllers and domain services, and reviewed migrations 000003–000009 and security tests.

The source review was read-only. Runtime tests used a new internal-only Docker network, a uniquely named PostgreSQL container and volume, a dedicated Redis container, and a dedicated PHPUnit runner. The runner could reach only those test services; its backend source bind mount was read-only, with writable temporary runtime directories. All nine migrations and destructive fixtures were confined to the disposable test database. The test containers, network, named volume, and Redis-created anonymous volume were removed after verification. No application source or existing configuration was changed.

## 3. Verification of implementation claims

| Claim in implementation report | Audit result |
|---|---|
| 109 passing tests / 757 assertions | **Independently verified.** The full backend PHPUnit suite passed against the isolated PostgreSQL 17.11 service. |
| Nine PostgreSQL security concurrency scenarios | **Independently verified.** Four administrator and five security concurrency cases passed. |
| Management concurrency scenarios | **Independently verified.** Four additional cases passed; all 13 concurrency cases passed (77 assertions). |
| Frontend build and lint, Pint, Composer validation | **Not independently verified.** No build, lint, or validation command was run. |
| SMTP activation/reset and Redis behavior | Activation/reset and session invalidation tests passed using the suite's fake mail/test session drivers; Redis integration tests passed against a dedicated Redis container. **Live SMTP and production Redis session configuration were not tested.** |
| Six application containers / database target | **Verified read-only.** All six containers remained running; PostgreSQL, Redis, backend, frontend, and Mailpit were healthy. The backend target was `postgres:5432/veltra`, using the existing `veltra_postgres-data` volume. |
| No Stage 1E/1F business modules | Consistent with the approved specification's Stage 1D boundary; this is **not** reported as a defect. |

The specification and implementation report were available during the audit. The report itself explicitly notes that synchronous SMTP can produce timing differences despite uniform initiation responses; that risk was assessed separately below.

## 4. Findings ordered by severity

### Summary

No open security vulnerabilities were identified. The previous assurance item SEC-001 is closed after the isolated test verification below.

### SEC-001

**Original severity:** MEDIUM — assurance/verification gap, not a confirmed vulnerability
**Title:** Critical PostgreSQL security tests were not independently executed (closed)
**Affected files and lines:** `backend/tests/Feature/AdministratorConcurrencyTest.php:23,69`; `backend/tests/Feature/SecurityConcurrencyTest.php:28,98`; test database configuration in `backend/phpunit.xml:27-33` and `backend/tests/InstallationTestCase.php:17-20`  
**Evidence:** These fixtures execute `TRUNCATE organizations CASCADE`, so an isolated target was required.
**Resolution:** The suite ran against `veltra_testing` on PostgreSQL 17.11 inside a new Docker `internal=true` network. Only the dedicated PHPUnit, PostgreSQL, and Redis containers were attached. The test PostgreSQL used the unique volume `veltra-stage1d-audit-20261102T092006-pgdata`; the runner had no route to `veltra_default`, published ports, or the application's `veltra_postgres-data` volume. A read-only PDO probe confirmed database `veltra_testing`, role `stage1d_test`, and the disposable server address before migrations or destructive tests. The tests passed and the disposable resources were removed; application containers and their existing PostgreSQL volume remained unchanged.
**Status:** CLOSED — verification gap resolved; no application defect confirmed.

No confirmed or likely exploitable application vulnerabilities were identified in the reviewed source or observed test results. SEC-001 is retained for audit history only and is not an open finding.

### Synchronous SMTP timing assessment

The source contains a real response-time difference: eligible link requests send mail synchronously, while missing or ineligible targets skip dispatch. The route requires a first-party authenticated session, validated identity, operating context, and throttling (`backend/routes/api.php:20-22,39`). Target lookup and the uniform accepted response are in `backend/app/Http/Controllers/Api/V1/SecurityController.php:159-169,180-183`; eligibility checks are in `backend/app/Domain/Identity/AccountLinks.php:40-88`; synchronous delivery follows issuance in `backend/app/Domain/Identity/AccountLinks.php:90-114`.

**Severity assessment: LOW residual account-state oracle; not a separately reportable vulnerability.** A caller must already be an authenticated, authorized administrator/supervisor with access to this security API and know a target UUID. Repeated timing measurements could suggest whether a target is eligible for activation/reset, but do not disclose the token or grant access. UUIDs and the caller's existing scoped access reduce practical exposure. Identical HTTP status/body does not equalize latency and is not a timing mitigation.

**Safe recommendation:** Move email delivery behind a transactional outbox/queue so SMTP latency is removed from the request path. Keep target existence, eligibility, and token details out of responses. If these conditions must remain opaque to an authorized caller, use consistent handling and consider response-time equalization; preserve current server-side authorization, throttles, and audit events.

## 5. RBAC compliance matrix

Source-review results only; these are not runtime test results.

| Requirement | Assessment |
|---|---|
| Exactly six business roles; no hidden operator role | Source-reviewed role definitions and role tests; no alternative administrator-equivalent role identified. |
| Administrator organization-wide scope without per-location memberships | `BusinessAuthorization` separates active operating context from role-derived target scope; no artificial all-location membership requirement identified. |
| BOSS report scope | Role scope is restaurant-wide; report authorization distinguishes restaurant reports from central-kitchen source data. |
| Central-kitchen chef scope and management reassignment | Authorization checks current management relationships and selected kitchen context; supply relationships are not used to grant management scope. |
| Restaurant manager boundaries | Role administration restricts manager-grantable roles and protects elevated targets; personnel operations are scope checked. |
| Restaurant chef financial access | Financial permissions are distinct and not implicit in the chef role. |
| Employee and delegated operation boundaries | Operational grants use an explicit permission allowlist and target/context checks; no privileged role grant through the delegation path was identified. |
| Multiple roles / location memberships | Authorization evaluates actor roles and target scope; protected-target checks account for elevated roles beyond the immediate restaurant. |
| User-controlled target identifiers / IDOR | UUID target lookup is followed by domain authorization and scope checks; no cross-organization or cross-location bypass was identified in reviewed paths. |
| Revoked/reassigned membership and stale access | Identity/context validation and versioned session checks require active identity/context; role/grant records are tied to the membership assignment lifecycle. |

Key source paths reviewed include `backend/app/Domain/Authorization/BusinessAuthorization.php:14-88`, `RoleAdministration.php:19-48,72-99,137-183`, `PersonnelAdministration.php:18-150`, and `backend/app/Http/Controllers/Api/V1/SecurityController.php:59-115`.

## 6. PostgreSQL concurrency and integrity assessment

- The authorization mutation path serializes organization-level changes using a row lock (`backend/app/Domain/Authorization/SecurityMutation.php:26-39`).
- Role removal/deactivation checks the remaining active administrator and usable operating context (`RoleAdministration.php:72-99,137-165`).
- Migration 000004 defines deferred active-and-verified-administrator protection (`backend/database/migrations/2026_11_02_000004_protect_administrators_and_security_history.php:43-60`); migration 000009 adds the usable active membership/location requirement (`backend/database/migrations/2026_11_02_000009_preserve_usable_administrator_context.php:9-26`).
- Migration 000006 constrains grant scope and closes grants when memberships deactivate (`backend/database/migrations/2026_11_02_000006_create_permission_grants.php:11-58`).
- Migrations 000001–000009 were applied to the disposable PostgreSQL 17.11 database; all nine reported `Ran`. The complete test suite passed, including database constraint/integrity tests and the PostgreSQL-specific trigger assertions.
- The three concurrency test classes use separate PostgreSQL processes. All 13 data-provider cases passed: four administrator, five security, and four management cases (77 assertions in the focused run).

## 7. Authentication and account recovery assessment

Source review found hashed, single-use activation/reset tokens; activation/reset eligibility and delivery limits; token consumption and password update flows; and session/version invalidation logic. Relevant paths include `backend/app/Domain/Identity/AccountLinks.php:26-37,40-165`, `backend/app/Providers/AppServiceProvider.php:32-49`, and `backend/routes/api.php:20-22`. Account activation/reset, session invalidation, and API authentication tests passed in the full suite.

Synchronous SMTP creates the LOW residual timing oracle described above. The suite uses fake mail and a test session driver; live provider delivery, production SMTP timing, and production Redis-backed session behavior were not independently exercised. The dedicated Redis integration tests passed.

## 8. API and session security assessment

Security routes are grouped behind first-party session, Sanctum authentication, identity validation, rejection auditing, operating-context enforcement, and throttling (`backend/routes/api.php:20-42`). Stateful API middleware is configured in `backend/bootstrap/app.php:20-26`; `RequireSpaSession` and `ValidateIdentityContext` enforce session/identity state (`backend/app/Http/Middleware/RequireSpaSession.php:11-17`, `ValidateIdentityContext.php:14-36`). Login regenerates the session and clears prior context (`backend/app/Http/Controllers/Api/V1/AuthenticationController.php:16-28`). Session and Sanctum configuration were source-reviewed (`backend/config/session.php:168-202`; `backend/config/sanctum.php:8-20`).

No frontend-only security boundary or administrator-session-only developer endpoint activation was identified in reviewed routes. The session-authenticated API, CSRF, and security API feature tests passed using the Compose-matching `SANCTUM_STATEFUL_DOMAINS`. Deployment overrides, a live browser, reverse-proxy/TLS settings, production cookie behavior, and sensitive bundle contents were not dynamically verified.

## 9. Test coverage and gaps

Reviewed security test files:

- `backend/tests/Feature/AdministratorConcurrencyTest.php`
- `backend/tests/Feature/SecurityConcurrencyTest.php`
- `backend/tests/Feature/AccountSecurityTest.php`
- `backend/tests/Feature/RoleAdministrationTest.php`
- `backend/tests/Feature/PermissionDelegationTest.php`
- `backend/tests/Feature/ReportAuthorizationTest.php`
- `backend/tests/Feature/BusinessRoleDefinitionTest.php`
- `backend/tests/Feature/PersonnelAdministrationTest.php`
- `backend/tests/Feature/SecurityApiTest.php`
- `backend/tests/Feature/IdentityAuthenticationTest.php`
- `backend/tests/AuthorizationTestCase.php`
- `backend/tests/IdentityTestCase.php`

**Runtime result:** `vendor/bin/phpunit --do-not-cache-result` — **109 tests, 757 assertions, 0 failures, 0 errors**. A focused run of `AdministratorConcurrencyTest`, `SecurityConcurrencyTest`, and `ManagementConcurrencyTest` passed **13 tests, 77 assertions**. The full suite includes PostgreSQL integrity constraints, session/API security, token flows, and Redis integration checks.

### Follow-up disposable-database verification

**Outcome: COMPLETE — isolation proven before migrations and destructive tests; disposable resources removed afterward.**

- Docker client/server were independently verified as 29.9.2; Docker Desktop reported 4.97.0. The six existing application containers were running. Compose inspection confirmed backend target `postgres:5432/veltra` on `veltra_default`; the application PostgreSQL container mounted only `veltra_postgres-data`.
- PHPUnit sets the database name to `veltra_testing` but does not set the host. The audit runner therefore explicitly overrode the connection to `stage1d-pg:5432`, database `veltra_testing`, role `stage1d_test`; it did not use Compose's `postgres` alias.
- Before running migrations or tests, the test network `veltra-stage1d-audit-20261102T092006-net` was inspected and confirmed `internal=true`. Its only endpoints were the dedicated audit PHPUnit, PostgreSQL, and Redis containers. No application container was attached, no port was published, and the runner's backend source mount was read-only.
- The dedicated PostgreSQL container mounted only `veltra-stage1d-audit-20261102T092006-pgdata`. A read-only PDO probe returned database `veltra_testing`, role `stage1d_test`, server address `172.19.0.2`, PostgreSQL `17.11`, and data directory `/var/lib/postgresql/data`. The database started empty; migrations were applied only after this verification.
- Test bootstrap used the matching Compose stateful-domain list, a valid test-only application key, writable tmpfs paths, and an isolated Redis service. The README's test workflow requires migrations before PHPUnit; all nine migrations were applied to the disposable database. The final full suite and focused concurrency suite passed as reported above.
- The first attempts exposed missing test-environment setup (schema not migrated, then test-only key/runtime paths/Redis/stateful-domain values absent). Those attempts did not pass; after correcting only the disposable runner setup, the full suite passed. These were test-harness configuration issues, not application defects.
- After testing, the disposable database contained nine migration records and no organization rows. All six existing containers remained running; PostgreSQL, Redis, backend, frontend, and Mailpit were healthy. The application PostgreSQL mount remained `veltra_postgres-data`. All audit containers, the dedicated named database volume, internal network, and the Redis image's audit-created anonymous volume were removed. The only remaining Docker volumes were the two pre-existing application volumes.
- No confirmed application defects were found by the passing tests. No application source or existing configuration was modified.

## 10. Confirmed strengths

- Operating context and authorization scope are handled as separate checks.
- Elevated role administration and protected-target checks are implemented in domain services.
- Administrator and usable-context invariants have both service-level and deferred database protections in source.
- Financial/report permissions are separate; report export authorization checks read, export, and source permissions.
- Delegations use scoped roles, explicit permissions, time validity, and membership-linked records.
- Account-link token handling, delivery limits, and synchronous dispatch are explicit in the identity service.
- Security mutation events are written in the mutation flow; migration 000004 makes the event table append-only through ordinary application-level SQL paths. Database-owner/infrastructure privileges can still alter the schema or database.
- Report-data/exports and other Stage 1E/1F modules are not treated as missing Stage 1D functionality.

## 11. Residual risks

- No production deployment, external SMTP provider, live TLS/reverse proxy, load test, independent penetration test, or cryptographic review was performed.
- Future report consumers must preserve the reviewed report-source authorization contract; no Stage 1D report-data/export endpoint was available to validate end-to-end enforcement.
- Synchronous SMTP leaves a low-impact timing signal to an already authorized, scoped caller.
- The database owner and infrastructure administrators remain capable of changing database schema or records; append-only triggers are not protection against those privileges.
- Security events do not include IP address or user-agent. The approved requirements did not specifically require those fields, so this is an auditability limitation, not a finding.

## 12. Recommended remediation order

1. Consider adding a fail-fast test-bootstrap guard that verifies the PostgreSQL host/instance as well as the database name before destructive fixtures run; no source changes were made in this audit.
2. Before production use, validate deployment-specific TLS/proxy/CORS/cookie/SMTP settings and consider moving email dispatch to a transactional outbox to reduce timing variance.
3. Require future report consumers to call the same server-side source/read/export authorization contract and add HTTP-level tests when those consumers are introduced.

## 13. Acceptance recommendation

**STAGE 1D SECURITY VERIFICATION: ACCEPTED.** The isolation gap is closed; the complete PostgreSQL-backed suite and all 13 concurrency cases passed. No confirmed application security vulnerabilities or application defects were found. Stage 1E was not started, and this result does not authorize or begin that stage.

## 14. Commands executed and results

| Command/action | Result |
|---|---|
| `docker version`; read-only `docker ps`, `docker inspect`, `docker network inspect`, and `docker volume ls` | Docker client/server 29.9.2; Docker Desktop 4.97.0. Compose PostgreSQL target and existing `veltra_postgres-data` mount were recorded without exposing credentials. |
| Disposable PostgreSQL and Redis stack | PostgreSQL 17.11, database `veltra_testing`, isolated role `stage1d_test`; internal-only network with only audit services; dedicated database volume; no published ports. Read-only PDO probe confirmed target before migrations/tests. |
| `php artisan migrate --force --no-interaction`; `php artisan migrate:status --no-interaction` in the isolated runner | All nine migrations ran on the disposable database. |
| `vendor/bin/phpunit --do-not-cache-result` with Compose-matching test environment | **109 tests, 757 assertions, 0 failures, 0 errors.** |
| Focused PHPUnit run for `AdministratorConcurrencyTest`, `SecurityConcurrencyTest`, and `ManagementConcurrencyTest` | **13 tests, 77 assertions, 0 failures, 0 errors.** |
| Post-test database and application inspection | Disposable database had nine migration records, 15 public tables, and zero organizations. Application containers remained running; application PostgreSQL still mounted `veltra_postgres-data`. |
| Explicit cleanup of audit containers, named volume, internal network, and Redis-created anonymous volume | Completed. Only the two pre-existing application volumes remained. |
| Build/lint, external SMTP, production deployment settings, live browser scenarios | Not run; outside this verification's runtime scope. |
| Google Drive report | Updated the existing report in place as Version 3 in `RAPORTY_COPILOTA`; its name, folder, and preview contents were verified. |

## 15. Git status

- **Before this continuation:** clean worktree.
- **After this continuation:** only this audit report is modified. No application source, tests, migrations, Compose files, or existing configuration were changed.
- No commit or push was made. Migrations and destructive operations were confined to the disposable test database; audit Docker resources were removed after the run.

## Google Drive upload

The existing report was replaced in place as **Version 3** in [RAPORTY_COPILOTA](https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t). The updated [VELTRA_STAGE_1D_SECURITY_AUDIT.md](https://drive.google.com/file/d/1atTKk_OQQLxUdoctp0NrwPoaOROoBWeH/view) was verified in the folder and its preview confirmed the Stage 1D acceptance, test counts, resolved SEC-001, and Drive delivery status. Only this Markdown report was uploaded; no source files, environment files, or credentials were included.
