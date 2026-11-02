# VELTRA 2.0 — Stage 1F Comprehensive Foundation Audit

**Audit date:** 2026-11-02  
**Review type:** Read-only source review, isolated regression execution, local runtime checks  
**Verdict:** **CONDITIONAL ACCEPT**  
**Recommendation:** **GO — with localization and production-release gates**

## Executive assessment

The Stage 1A–1E foundation is sufficiently reliable to begin scoped business-module development. The reviewed Laravel API, Sanctum SPA authentication, authorization boundaries, PostgreSQL integrity protections, Stage 1E readiness gates, and organization-level serialization are supported by the source and a passing full backend regression suite. The Stage 1E migration is recorded as `Ran` in the local development database. The live application database was checked read-only; all destructive tests and test migrations ran only in a dedicated disposable PostgreSQL environment.

The independent security review found **no confirmed exploitable security vulnerability** in reviewed HEAD. The Stage 1E `NULL effective_enabled` invariant is enforced by PostgreSQL and covered by tests. Focused Stage 1E tests passed **30 tests / 295 assertions**; the complete backend suite passed **139 tests / 1,052 assertions**, with no failures or warnings. Frontend lint and production build both passed.

One confirmed, user-visible compliance issue remains: the running backend locale and fallback are both English, while backend API/domain errors include English strings and the frontend can display server messages verbatim. This is a **MEDIUM localization finding**, not a security vulnerability. It does not prevent backend/domain development from beginning, but Polish localization must be treated as a release requirement for every user-facing flow. One inherited LOW-risk SMTP timing signal and non-production verification limitations are also documented below.

No application source, configuration, database schema/data, existing application container, or existing Docker volume was changed for this audit. No application migration was run. No commit or push was created.

## Scope and materials reviewed

Reviewed actual source and tests, including:

- Approved Stage 1D RBAC specification and Stage 1E module-activation architecture specification, referenced in the prior audit/review reports.
- `README.md`, `compose.yaml`, backend authentication/session/Sanctum configuration, API routes, middleware, controllers, authorization/identity/installation/module services, migrations 000001–000010, PHPUnit configuration and tests.
- Frontend React/TypeScript screens and API handling: `frontend/src/App.tsx`, `AuthPanel.tsx`, `AccountLinkForm.tsx`, and `api.ts`.
- Prior security and deployment evidence: `VELTRA_STAGE_1D_SECURITY_AUDIT.md`, `VELTRA_STAGE_1E_SECURITY_AUDIT.md`, `VELTRA_STAGE_1E_SECURITY_REMEDIATION_REPORT.md`, `VELTRA_STAGE_1E_REMEDIATION_REVIEW.md`, `VELTRA_STAGE_1E_FINAL_VERIFICATION.md`, `VELTRA_STAGE_1E_DEPLOYMENT_PREFLIGHT.md`, `VELTRA_STAGE_1E_DEPLOYMENT_BLOCKERS_RESOLUTION.md`, `VELTRA_DOCKER_HOST_FORWARDING_REPAIR.md`, `VELTRA_STAGE_1E_LOCAL_DEPLOYMENT_REPORT.md`, and `VELTRA_STAGE_1E_COMPOSE_CONFIGURATION_REPORT.md`.
- The prior Stage 1D SMTP timing assessment and Stage 1E rollback/backup guidance were retained as residual operational evidence, not treated as proof of production readiness.

## Findings

| # | Severity | Finding and evidence | Impact / disposition |
|---|---|---|---|
| 1 | MEDIUM | **Backend error localization is not consistently Polish.** The running backend reports `app.locale=en` and `fallback_locale=en`. `backend/config/app.php:82-84` defaults both to English; `backend/.env.example:9-10` sets the primary locale to Polish but the fallback to English. No backend translation files were found under `backend/lang` or `backend/resources/lang`. `backend/bootstrap/app.php:41-49` returns English business/database error text; `AuthenticationController.php:26` and `AccountLinkController.php:28` contain English API messages. `frontend/src/api.ts:28-33` forwards a server `message` when it is present, and the screens render caught errors. | Confirmed user-visible language inconsistency against the Polish UI requirement. Set the runtime/default/fallback locale deliberately to Polish, add/verify Laravel validation and authentication translations, localize API/domain messages, and test that surfaced errors remain Polish. **Required before exposing new or existing affected flows to users; not a security defect.** |
| 2 | LOW | **Synchronous account-link email delivery can create a scoped timing signal.** The prior Stage 1D audit traced the synchronous dispatch path and found response-time differences between eligible and ineligible targets (`SecurityController.php` and `AccountLinks.php`); status/body uniformity does not equalize latency. | An already authenticated, authorized, scoped caller who knows a target UUID could infer limited eligibility information through repeated timing; no token disclosure or privilege escalation was identified. Low residual risk, not a confirmed exploitable vulnerability. Consider a transactional outbox/queue before production; retain server-side authorization, throttling, and uniform response content. Live SMTP timing was not tested. |
| 3 | INFORMATIONAL | **Frontend regression coverage is limited to static/build checks.** `frontend/package.json` defines lint/build scripts but no automated UI-test script. | No frontend test failure was observed; the current screens passed lint and build. Add component/browser tests as new business workflows and authorization-sensitive interactions are introduced. This is an improvement, not a Stage 1A–1E defect. |
| 4 | INFORMATIONAL | **Production operations were not audited or exercised.** This is a local-development environment. Prior deployment evidence reports PostgreSQL WAL archiving disabled; the logical-backup restore rehearsal was successful, but it does not provide PITR. The queue service has no Compose healthcheck. | No local-development blocker was identified. Before production, define RPO/RTO, backup retention/off-host protection and restore drills, and decide whether PITR is required; add queue liveness/health monitoring. Production ingress/TLS, secrets, live SMTP, load, and failover remain outside this audit. |

No CRITICAL or HIGH findings were confirmed. Finding 1 is the only mandatory remediation gate for user-facing release. Findings 2–4 are residual risk or future operational improvements.

## Architecture and business-module readiness

- **Boundaries:** The backend separates versioned API routes/controllers from domain services for installation, identity, authorization, and module configuration. The frontend is a React/TypeScript SPA using a shared API helper. The structure is adequate for adding bounded business domains without coupling those features to authentication internals.
- **Authentication/session:** Stateful Sanctum SPA flow, CSRF, session-backed authentication, login throttling, identity/context validation, and session invalidation are present in configuration and middleware. Session cookies are HTTP-only and same-site `lax`; secure-cookie behavior is environment-sensitive and enabled outside local/testing. Tests cover authentication, recovery, session/CSRF boundaries, and inactive identity handling.
- **Authorization:** Security routes are grouped behind first-party session, Sanctum auth, identity/context checks, rejection auditing, operating-context enforcement, and throttling (`backend/routes/api.php:27-50`). Source review found no cross-organization/location authorization bypass or privilege escalation. Tests exercise administrator-only module configuration and denials for non-administrator roles.
- **PostgreSQL integrity:** Migrations use foreign keys, composite organization/location references, uniqueness/check constraints, and database triggers for protected histories and state identity. Migration 000010's transition check requires the effective value and uses null-safe comparisons; append-only security-event protections remain in place. The live development database reports migration 000010 `Ran` (batch 4).
- **Module activation:** The code-defined production catalog remains fail-closed. All four production entries remain `NOT_READY`; the tests verify activation attempts are rejected. Module state is location-specific, default-disabled, and does not inherit from a kitchen or from later location creation. Business workflows remain intentionally absent and are not classified as defects.
- **Concurrency:** Module configuration and location creation serialize on the organization row. The process-based PostgreSQL tests use independent connections and cover both location-creation/configuration lock orders, configuration commit and rollback outcomes, and no unintended state inheritance. Existing overlapping/opposing module changes and management/security concurrency tests also passed in the full suite.

The foundation is suitable for implementing business modules, provided new functionality preserves organization/location scope, transactional state changes, audit requirements, the readiness gate, and Polish user-facing text. This GO is for development only; it is not production acceptance.

## Infrastructure and live environment

The environment was confirmed as local development. The six existing application containers remained running: backend, frontend, PostgreSQL, Redis, Mailpit, and queue. Backend, frontend, PostgreSQL, Redis, and Mailpit reported healthy; queue was running (Compose does not define a queue healthcheck). Host probes returned HTTP 200 for backend `/api/health` and frontend `/`; PostgreSQL accepted connections and Redis returned `PONG`.

The backend and queue use the verified candidate image `veltra-backend:stage1e-candidate-db767dd`, image ID `sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21`. The frontend runs image ID `sha256:0ad79b600639c2d1cd2c84a7bfb0afb79f611542df9a6ddc438e7decfa2e7569`. Existing PostgreSQL and Redis containers and named volumes were not replaced or modified. A read-only `php artisan migrate:status` confirmed migration 000010 remains `Ran`.

### Disposable test isolation

Before any migration or destructive test, a new Docker network was created with `Internal=true`. Its only initial endpoints were the dedicated PostgreSQL 17 and Redis containers; neither had published host ports. PostgreSQL used only the dedicated volume `veltra_stage1f_foundation_20261102_pgdata`. A PDO probe from the candidate image returned database `veltra_testing`, user `stage1f_audit`, and PostgreSQL address `172.19.0.2`, matching the disposable PostgreSQL container. The PHPUnit configuration also forces `DB_DATABASE=veltra_testing`.

Only after these checks were complete were migrations and tests run. The runner used the candidate backend image and joined only the internal disposable network; test connection variables explicitly pointed at `postgres` and `redis` on that network. Destructive test fixtures, including `TRUNCATE ... CASCADE`, were therefore confined to the disposable database. After verification, the dedicated PostgreSQL/Redis containers, PostgreSQL volume, and internal network were removed and their absence verified. No application container/network/volume was attached to the runner.

## Regression results and exact commands

### Disposable database setup

```sh
docker network create --internal veltra-stage1f-foundation-20261102-internal
docker volume create veltra_stage1f_foundation_20261102_pgdata
docker run -d --name veltra-stage1f-foundation-postgres \
  --network veltra-stage1f-foundation-20261102-internal --network-alias postgres \
  -e POSTGRES_DB=veltra_testing -e POSTGRES_USER=stage1f_audit \
  -e POSTGRES_PASSWORD=Stage1F-Disposable-Only-2026 \
  --mount type=volume,source=veltra_stage1f_foundation_20261102_pgdata,target=/var/lib/postgresql/data \
  postgres:17-alpine
docker run -d --name veltra-stage1f-foundation-redis \
  --network veltra-stage1f-foundation-20261102-internal --network-alias redis \
  redis:7.4-alpine redis-server --save '' --appendonly no
```

Isolation was verified with `docker network inspect`, `docker inspect`, `pg_isready`, `redis-cli ping`, and a PDO query before migration. The network was internal, the only initial endpoints were the two disposable services, port bindings were empty, and the database volume was dedicated.

### Disposable migration

```sh
docker run --rm --network veltra-stage1f-foundation-20261102-internal \
  --entrypoint php \
  -e APP_ENV=testing \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=veltra_testing -e DB_USERNAME=stage1f_audit \
  -e DB_PASSWORD=Stage1F-Disposable-Only-2026 -e DB_URL= \
  sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21 \
  artisan migrate --force
```

Migrations 000001–000010 completed successfully in the disposable database only.

### Focused and full backend suites

```sh
docker run --rm --network veltra-stage1f-foundation-20261102-internal \
  --entrypoint sh \
  -e APP_ENV=testing \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=veltra_testing -e DB_USERNAME=stage1f_audit \
  -e DB_PASSWORD=Stage1F-Disposable-Only-2026 -e DB_URL= \
  -e REDIS_CLIENT=phpredis -e REDIS_HOST=redis -e REDIS_PORT=6379 \
  -e SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000,127.0.0.1:5173,127.0.0.1:8000 \
  -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync \
  -e CACHE_STORE=array -e SESSION_DRIVER=array \
  sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21 \
  -c '
set -eu
php artisan test --do-not-cache-result \
  tests/Unit/ModuleCatalogTest.php \
  tests/Feature/ModuleConfigurationTest.php \
  tests/Feature/ModuleConfigurationDatabaseIntegrityTest.php \
  tests/Feature/ModuleConfigurationConcurrencyTest.php
php artisan test --do-not-cache-result --display-warnings
'
```

| Suite | Result |
|---|---|
| Focused catalog/configuration/database-integrity/concurrency | **30 passed, 295 assertions** |
| Full backend suite | **139 passed, 1,052 assertions**; no failures or warnings |

The focused tests include direct PostgreSQL checks for invalid NULL/mismatched/no-op audit transitions, valid transitions, append-only events, immutable module-state identity, uniqueness and composite references. They also verify fail-closed production readiness, HTTP/RBAC boundaries, atomicity, and the process-based location-creation/all-compatible-selection race. The full suite provides Stage 1A–1E backend regression evidence.

### Frontend checks

```sh
docker run --rm --network veltra-stage1f-foundation-20261102-internal \
  --entrypoint sh \
  sha256:0ad79b600639c2d1cd2c84a7bfb0afb79f611542df9a6ddc438e7decfa2e7569 \
  -c 'set -eu; npm run lint; npm run build'
```

Lint passed with **0 warnings and 0 errors** across 6 files. TypeScript and Vite production build passed; Vite transformed 20 modules.

### Read-only live checks

```sh
docker compose ps
curl --max-time 10 -fsS -w '\nbackend_http=%{http_code}\n' \
  http://127.0.0.1:8000/api/health
curl --max-time 10 -fsS -o /dev/null -w 'frontend_http=%{http_code}\n' \
  http://127.0.0.1:5173/
docker exec veltra-postgres-1 pg_isready -U veltra -d veltra
docker exec veltra-redis-1 redis-cli ping
docker exec -w /var/www/html veltra-backend-1 \
  php artisan migrate:status --no-interaction
```

Observed backend and frontend status: HTTP 200; health JSON reported PostgreSQL and Redis `up`; PostgreSQL accepted connections; Redis returned `PONG`; migration 000010 was `Ran`. These live checks did not change database state.

## Polish localization assessment

The existing React screens contain predominantly Polish copy: health/status, login, operating-location selection, activation, and password reset. The shared API helper also supplies Polish messages for several HTTP statuses. However, the backend's observed runtime locale is `en|en`; no backend translation catalogs were found; and raw API error messages are not uniformly localized. Consequently, the current UI is not yet consistently Polish for all server-driven errors.

**Required for user-facing release:** make Polish the deliberate runtime/default/fallback locale; add and test Polish framework validation/authentication strings; localize controller/domain/exception messages; and add regression assertions that the rendered API error copy is Polish. All new business-module labels, validation, status, and error messages must be Polish.

## Required remediation versus optional improvements

### Required before user-facing release

1. Close finding 1 by localizing backend defaults, framework translations, and all user-visible API/domain messages; verify from the actual runtime configuration and tests.
2. Maintain the existing fail-closed module readiness and authorization/audit boundaries for new business domains.

### Before production deployment

1. Define production RPO/RTO and implement approved off-host backup/restore procedures; enable PITR/WAL archiving only if those objectives require it.
2. Validate production TLS/reverse proxy, secure-cookie, CORS/stateful-domain, secret-management, SMTP, queue, monitoring, and recovery configuration.
3. Consider moving account-link delivery to a transactional outbox and add queue health/liveness monitoring.

### Optional / staged quality improvements

- Add React component and browser-level tests before business screens become complex.
- Add explicit performance/load testing as volume and availability objectives are established.

## Limitations

- This review and regression run cover the current local-development candidate, not staging or production.
- SMTP provider timing, external reverse proxy/TLS, browser end-to-end flows, load/failover behavior, and production credential handling were not independently tested.
- Static review and automated tests do not constitute an external penetration test or cryptographic review.
- No confirmed vulnerability was found; absence of findings is not a guarantee of security.

## Final verdict

**CONDITIONAL ACCEPT. GO for scoped business-module development** on the existing foundation. This is not authorization to implement modules in this audit task and is not production acceptance. The confirmed MEDIUM localization finding must be closed before affected functionality is released to users; all future user-facing VELTRA functionality must be in Polish. The LOW SMTP timing risk and production-only operational gates should remain tracked.

