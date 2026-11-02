# VELTRA 2.0 — Stage 1D Security & Architecture Audit

**Audit date:** 2026-11-02  
**Repository:** `/home/veltra/projekty/veltra`  
**Review type:** Read-only static security and architecture review  
**Acceptance recommendation:** **REJECT / BLOCK NEXT STAGE** pending safe, independent verification of the security test suite. No high-confidence exploitable application vulnerability was identified in the reviewed source.

## 1. Executive summary

The approved Stage 1D specification and the implementation report were retrieved and reviewed. Stage 1D source, migrations, routes, middleware, models, authorization and identity services, configuration, and relevant tests were reviewed by a security-review agent. The implementation report's test/build/concurrency/SMTP/Redis results were treated as claims, not audit evidence.

The source review found no high-confidence exploitable security vulnerability in the reviewed Stage 1D paths. It did identify one **MEDIUM assurance/verification gap**: the security and concurrency suite was not run because its fixtures truncate the test database and the reviewer could not independently establish that the configured database was isolated from installation or shared data. This is not a confirmed application vulnerability, but it prevents independent acceptance of critical authorization and concurrency guarantees.

**Stage 1E should remain blocked until the PostgreSQL security suite has been run against an explicitly verified disposable test database.** This gate is due to missing verification, not a confirmed Stage 1D exploit.

**Follow-up verification (2026-11-02):** An attempt was made to establish a disposable test database. Isolation could not be proven because the Docker engine was unavailable, so the destructive tests were not run and no database, container, volume, or application data was changed.

## 2. Scope and methodology

The audit compared source to the approved RBAC v1 specification dated 2026-11-02. The implementation report was used only to identify claims for verification. The review traced relevant HTTP routes through middleware/controllers and domain services, and reviewed migrations 000003–000009 and security tests.

The review was static and read-only. No application source or migration was changed; no database was queried or mutated; migrations, seeders, application tests, builds, linters, browser scenarios, and live SMTP/Redis scenarios were not run. Test assertions and previous execution results are therefore not treated as independently verified.

## 3. Verification of implementation claims

| Claim in implementation report | Audit result |
|---|---|
| 109 passing tests / 757 assertions | **Not independently verified.** Tests were reviewed but not run; safe database isolation could not be established. |
| Nine PostgreSQL concurrency scenarios | **Not independently verified.** Test code and locking/trigger design were source-reviewed; no concurrency scenario was executed. |
| Frontend build and lint, Pint, Composer validation | **Not independently verified.** No build, lint, or validation command was run. |
| SMTP activation/reset and Redis session invalidation | **Not independently verified.** Source flows were reviewed; no live mail, browser, HTTP, or Redis test was run. |
| Six containers running / database state | **Not independently verified.** No Docker or database diagnostic was run. |
| No Stage 1E/1F business modules | Consistent with the approved specification's Stage 1D boundary; this is **not** reported as a defect. |

The specification and implementation report were available during the audit. The report itself explicitly notes that synchronous SMTP can produce timing differences despite uniform initiation responses; that risk was assessed separately below.

## 4. Findings ordered by severity

### Summary

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | 🟡 MEDIUM | `backend/tests/Feature/AdministratorConcurrencyTest.php`; `backend/tests/Feature/SecurityConcurrencyTest.php` | 23, 69; 28, 98 | Critical security/concurrency tests were not run because their destructive database target could not be proven isolated. This is an assurance gap, not a confirmed application exploit. | 9/10 |

### SEC-001

**Severity:** MEDIUM — assurance/verification gap, not a confirmed vulnerability  
**Title:** Critical PostgreSQL security tests were not independently executed  
**Affected files and lines:** `backend/tests/Feature/AdministratorConcurrencyTest.php:23,69`; `backend/tests/Feature/SecurityConcurrencyTest.php:28,98`; test database configuration in `backend/phpunit.xml:27-33` and `backend/tests/InstallationTestCase.php:17-20`  
**Evidence:** The concurrency fixtures execute `TRUNCATE organizations CASCADE`. Although PHPUnit and fixtures point to `veltra_testing`, the reviewer could not independently prove that this database was disposable and isolated from installation data or other shared resources. Tests were therefore not run, as required by the read-only safety conditions.  
**Exploit/failure scenario:** This is not evidence of an application attack path. The operational failure scenario is running the destructive test fixture with a misconfigured or shared database connection. The audit consequence is that the claimed passing suite and critical last-administrator/concurrency behavior remain unverified.  
**Impact:** There is no independent evidence from this audit that the security tests pass against PostgreSQL. A test misconfiguration could also truncate data in the configured database.  
**Recommended remediation:** Establish a disposable PostgreSQL test service/database with a unique test-only database name and credentials; verify its endpoint and isolation before running tests. Add a fail-fast test-bootstrap guard that refuses to run destructive fixtures unless the connection is explicitly marked and identified as test-only.  
**Verification test:** Demonstrate the guard rejects a non-test/shared database; then run the complete security suite against the disposable PostgreSQL database, including the two-process administrator and grant concurrency scenarios, and confirm the installation database remains unchanged.  
**Status:** NEEDS VALIDATION  

No confirmed or likely exploitable application vulnerabilities were identified in the reviewed source. The item above is retained because it blocks verification of required security properties.

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
- Migrations 000003–000009 were source-reviewed, not applied or rolled back. PostgreSQL-specific behavior, actual constraint enforcement, rollback hazards, and runtime deadlock behavior remain unverified.
- Concurrency test code exists and uses separate PostgreSQL processes, but no concurrency test was executed. The implementation report's nine scenario results remain unverified.

## 7. Authentication and account recovery assessment

Source review found hashed, single-use activation/reset tokens; activation/reset eligibility and delivery limits; token consumption and password update flows; and session/version invalidation logic. Relevant paths include `backend/app/Domain/Identity/AccountLinks.php:26-37,40-165`, `backend/app/Providers/AppServiceProvider.php:32-49`, and `backend/routes/api.php:20-22`.

Synchronous SMTP creates the LOW residual timing oracle described above. Live delivery, actual token expiry behavior, race resistance under concurrent send/use, response timing, and session invalidation were not independently exercised. The implementation report's SMTP and Redis results remain claims.

## 8. API and session security assessment

Security routes are grouped behind first-party session, Sanctum authentication, identity validation, rejection auditing, operating-context enforcement, and throttling (`backend/routes/api.php:20-42`). Stateful API middleware is configured in `backend/bootstrap/app.php:20-26`; `RequireSpaSession` and `ValidateIdentityContext` enforce session/identity state (`backend/app/Http/Middleware/RequireSpaSession.php:11-17`, `ValidateIdentityContext.php:14-36`). Login regenerates the session and clears prior context (`backend/app/Http/Controllers/Api/V1/AuthenticationController.php:16-28`). Session and Sanctum configuration were source-reviewed (`backend/config/session.php:168-202`; `backend/config/sanctum.php:8-20`).

No frontend-only security boundary or administrator-session-only developer endpoint activation was identified in reviewed routes. Deployment overrides, live CORS/CSRF behavior, reverse-proxy/TLS settings, cookie behavior in production, and sensitive bundle contents were not dynamically verified.

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

The tests were not run. Their existence and assertions indicate intended coverage but do not establish passing behavior. PostgreSQL constraint execution, end-to-end HTTP authorization, browser/Redis sessions, SMTP timing, and the nine reported concurrency scenarios remain unverified by this audit.

### Follow-up disposable-database verification

**Outcome: NOT RUN — isolation could not be proven; the safety gate was honored.**

- `compose.yaml:1,24,26,98-99,119-121` defaults the Compose project to `veltra`, points the backend at the `postgres` service, defaults the application database to `veltra`, and declares persistent named `postgres-data` and `redis-data` volumes.
- `backend/phpunit.xml:28-31` forces the database name to `veltra_testing` and clears `DB_URL`, but does not pin the database host or prove which PostgreSQL server/volume receives the connection.
- The test cases and child processes assert only the database name (`InstallationTestCase.php:21`; concurrency tests at `AdministratorConcurrencyTest.php:22,42` and `SecurityConcurrencyTest.php:27,68`; child fixture guards in `tests/Support/concurrent_administrator.php:14-15` and `concurrent_security.php:17-18`). Those name checks do not prove instance or volume isolation.
- Both concurrency test files execute `TRUNCATE organizations CASCADE` (`AdministratorConcurrencyTest.php:23,69`; `SecurityConcurrencyTest.php:28,98`) and the security test also deletes activation/reset token rows (`SecurityConcurrencyTest.php:99-100`).
- The WSL `docker` CLI was unavailable. The Windows `docker.exe` CLI was found, but all read-only attempts to inspect containers, volumes, networks, Compose projects, and engine state failed because the Docker Desktop Linux engine named pipe was unavailable. No attempt was made to start the engine or create infrastructure.
- Consequently, running against the existing Compose project or its named volumes could not be ruled out. No tests, migrations, seeders, database commands, containers, networks, or volumes were created or changed. **No application defects were verified in this follow-up because no test executed.**

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

- Critical tests and runtime claims have not been independently verified in this audit.
- No production deployment, external SMTP provider, live TLS/reverse proxy, load test, independent penetration test, or cryptographic review was performed.
- Future report consumers must preserve the reviewed report-source authorization contract; no Stage 1D report-data/export endpoint was available to validate end-to-end enforcement.
- Synchronous SMTP leaves a low-impact timing signal to an already authorized, scoped caller.
- The database owner and infrastructure administrators remain capable of changing database schema or records; append-only triggers are not protection against those privileges.
- Security events do not include IP address or user-agent. The approved requirements did not specifically require those fields, so this is an auditability limitation, not a finding.

## 12. Recommended remediation order

1. Establish and positively verify a disposable PostgreSQL test database; add a fail-fast guard against destructive fixtures targeting a shared/non-test database.
2. Run the security and concurrency suites in that isolated environment, verify installation data is unchanged, and record actual results.
3. Before production use, validate deployment-specific TLS/proxy/CORS/cookie/SMTP settings and consider moving email dispatch to a transactional outbox to reduce timing variance.
4. Require future report consumers to call the same server-side source/read/export authorization contract and add HTTP-level tests when those consumers are introduced.

## 13. Acceptance recommendation

**REJECT / BLOCK NEXT STAGE** until the test isolation gap is resolved and critical PostgreSQL security/concurrency tests are independently executed. This is an evidence/acceptance gate; the static source review did not identify a confirmed exploitable vulnerability. No source remediation is prescribed by this audit.

## 14. Commands executed and results

| Command/action | Result |
|---|---|
| `git status --short --branch` before initial report creation | `## main` (clean worktree). |
| `docker ps --format '{{.Names}} {{.Image}} {{.Status}}'`; `docker volume ls --format '{{.Name}}'`; `docker network ls --format '{{.Name}}'`; `docker compose ls --format json` | Each failed: Docker CLI is unavailable in the WSL distribution. |
| `docker.exe ps --format '{{.Names}} {{.Image}} {{.Status}}'`; `docker.exe volume ls --format '{{.Name}}'`; `docker.exe network ls --format '{{.Name}}'`; `docker.exe compose ls --format json`; `docker.exe info --format '{{.ServerVersion}}'` | Each failed to connect because the Docker Desktop Linux-engine named pipe was missing. No Docker engine action was taken. |
| PostgreSQL security/concurrency tests, migrations, seeders, database commands, build/lint | Not run. Complete isolation could not be established. |
| Read-only retrieval of approved specification and implementation report | Retrieved successfully from the supplied Google Docs/Drive links. |
| Google Drive destination folder and report | The report was uploaded after user confirmation and verified in the requested folder. The same Drive file is being updated with this follow-up result. |

## 15. Git status

- **Before audit output:** `## main` (clean worktree, as observed with `git status --short --branch`).
- **After audit output:** `main` with this audit report as the only audit-created untracked file. No application source was modified.
- The verification follow-up began with the same status (`## main`; only `VELTRA_STAGE_1D_SECURITY_AUDIT.md` untracked). No source, migrations, tests, Compose configuration, or other application files were changed.
- No commit, push, reset, rebase, migration, seed, database write, or container modification was performed.

## Google Drive upload

The user-facing delivery summary records the verified Drive link. Only this Markdown report is in scope for upload; no source files, environment files, or credentials are included.
