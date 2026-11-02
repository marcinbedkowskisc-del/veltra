# VELTRA 2.0 — Stage 1E Independent Security & Architecture Audit

**Audit date:** 2026-11-02  
**Repository:** `/home/veltra/projekty/veltra`  
**Review type:** Read-only adversarial source review and independent isolated PostgreSQL verification  
**Final verdict:** **REJECT / BLOCK STAGE 1F**

## 1. Executive summary

The Stage 1E production API and service flow were reviewed by a security reviewer, and the reported 15 focused tests / 162 assertions and 124-test backend suite / 919 assertions were independently reproduced against a newly created disposable PostgreSQL and Redis environment. No reportable exploitable vulnerability in the current HTTP flows was identified. The four production modules remain code-defined as `NOT_READY`; the reviewed service rejects their activation.

One database-level audit integrity gap was confirmed directly against the disposable PostgreSQL database: the `security_events_module_shape` CHECK accepts a `module_transition` row with a `NULL` `effective_enabled` value under PostgreSQL's three-valued CHECK semantics. No current HTTP route was found that lets a client write arbitrary audit fields, so this is not a confirmed remotely exploitable API vulnerability. It is nevertheless a confirmed failure of a database invariant for the append-only audit record.

The review also found material verification gaps: the new database triggers and foreign keys lack direct negative-write tests; security-boundary cases are not all exercised at HTTP level; and concurrency coverage tests overlapping duplicate enables but not opposing-state races or partially overlapping batches. The full approved Google Doc body could not be extracted in this audit session; the user-provided audit criteria, repository README, implementation report, source review, and test evidence were used, and no unprovided requirements are inferred.

Because audit integrity is part of the module-configuration foundation and the authoritative specification was not fully available for line-by-line comparison, Stage 1E is **not accepted as a basis to begin Stage 1F**. Do not start Stage 1F until the audit-shape invariant is corrected and tested, the identified high-value verification gaps are addressed or explicitly risk-accepted, and the approved specification is available for a complete compliance comparison. No fixes were made.

## 2. Scope and methodology

### In scope

- Production module catalog, readiness, dependency validation, and test fixtures.
- Per-location configuration, service transaction/locking, password confirmation, administrator checks, and rate limiting.
- Availability integration with authorization and frontend-facing API routes/response shapes.
- PostgreSQL schema, foreign keys, checks, triggers, and audit-event persistence.
- Feature/unit/concurrency test quality and independent reproduction of reported test counts.
- Docker context, daemon, container/network/storage isolation, and disposable test target verification.

### Read-only / isolation constraints

- No application source, migration, or configuration was edited. No dependency was installed or changed.
- No migration or destructive fixture was run against the application database. The test runner was attached only to a newly created `internal=true` network and used a read-only temporary copy of backend source.
- A disposable database-only constraint probe ran in a transaction and was rolled back. It did not leave probe records or trigger changes.
- No application container or existing application volume was deleted or modified. No commit, push, reset, or rebase was performed.

### Authoritative-document limitation

The linked approved Stage 1E Google Doc opened in the signed-in browser, but its body could not be extracted in this audit session. A plain-text export did not complete. The audit therefore does not claim a complete line-by-line comparison to that document. The supplied audit task contains detailed requirements and was used as the available acceptance checklist, together with the repository README and implementation report. This limitation is a verification gap, not an inferred application defect.

## 3. Verification of implementation claims

| Claim | Independent result |
|---|---|
| Exactly four production modules; all remain `NOT_READY` | Source review confirmed `TEMPERATURES`, `CLEANING`, `LOSSES`, `INVENTORY` are the production catalog entries and have `ready=false`. |
| Production API cannot override readiness | Source review found readiness is catalog-defined and the configuration service rejects an enable request for an unready module. Test-only ready fixtures are injected by tests; no production API field changes catalog definitions. |
| Location state is fail-closed and independent | Missing state is treated as disabled. State is keyed per location/module; there is no kitchen-to-restaurant configuration inheritance. |
| Secure configuration and atomic audit | The reviewed service rechecks the administrator and context, requires a fresh password, resolves active same-organization targets, locks organization/locations/states, and writes state plus successful events transactionally. Rejections are audited after rollback. |
| Focused suite: 15 tests / 162 assertions | **Reproduced:** 15 passed, 162 assertions. |
| Full backend suite: 124 tests / 919 assertions | **Reproduced:** 124 passed, 919 assertions, zero reported warnings/failures. |
| PostgreSQL concurrency behavior | The existing independent-process test passed and observes the competing process waiting on the organization lock before commit; it verifies idempotent retry, one state row, one transition, and two accepted attempts. Coverage is narrow; see findings. |
| Existing application environment untouched | Confirmed before and after the test run; all six original application containers remained running and the original PostgreSQL/Redis volumes remained attached. |

## 4. Findings ordered by severity

### SEC-1E-001 — Transition audit CHECK accepts a null effective state

- **Severity:** MEDIUM
- **Status:** CONFIRMED — database-invariant gap; not an HTTP-reachable exploit identified
- **Affected file:** `backend/database/migrations/2026_11_02_000010_create_location_module_configuration.php:21-30`
- **Evidence:** In the `module_transition` branch, the constraint requires `effective_enabled = requested_enabled` and `previous_enabled <> effective_enabled`, but does not require `effective_enabled IS NOT NULL` or use null-safe comparisons. PostgreSQL accepts a CHECK expression that evaluates to `NULL`.
- **Reproduction:** In the disposable PostgreSQL database, an accepted `module_transition` with `previous_enabled=true`, `requested_enabled=true`, and `effective_enabled=NULL` was inserted successfully while the table's foreign-key/append-only triggers were disabled inside a transaction. The CHECK constraints remained active. A valid transition inserted in the same transaction also passed. The transaction was rolled back; the probe row count afterward was zero and the append-only trigger was enabled.
- **Failure scenario:** A database writer that can insert a row with those values can persist an event labeled as a transition even though its effective state is unknown and the old/new values do not represent a transition.
- **Impact:** Audit consumers cannot rely on every persisted `module_transition` row having a definite effective state or representing an actual state change. No current API route was found that accepts arbitrary event fields, so remote exploitation through the reviewed HTTP API is unconfirmed.
- **Recommended remediation:** Require `effective_enabled IS NOT NULL`; use `IS DISTINCT FROM` / `IS NOT DISTINCT FROM` as appropriate to make null semantics explicit. Add a direct PostgreSQL test asserting the malformed row is rejected and valid transitions are accepted.
- **Concrete verification test:** On disposable PostgreSQL, insert malformed and valid `module_transition` rows through the actual schema. Assert malformed insertion raises a CHECK violation, valid insertion succeeds, and normal append-only behavior remains enforced.
- **Confidence:** 9/10.

### SEC-1E-002 — No direct PostgreSQL negative-write coverage for critical constraints

- **Severity:** LOW
- **Status:** CONFIRMED — verification gap
- **Affected files:** `backend/database/migrations/2026_11_02_000010_create_location_module_configuration.php:21-30,45-47,71-85`; `backend/tests/Feature/ModuleConfigurationTest.php:39-238`
- **Evidence:** The migration defines event shape checks, state identity protection, serialization, and referential constraints. The Stage 1E feature tests exercise service/API outcomes and resulting counts but do not attempt direct event update/delete, identity-changing state update, or cross-organization state insertion against PostgreSQL.
- **Impact:** The tests do not independently establish that the database protections remain effective if a future writer bypasses the service. This gap also allowed the confirmed null-check issue to go unnoticed.
- **Recommended remediation:** Add disposable-PostgreSQL tests for event append-only update/delete, state identity immutability, cross-organization state references, malformed audit shapes, and unique location/module state.
- **Concrete verification test:** Each prohibited write should raise the expected PostgreSQL SQLSTATE in an isolated database; valid rows should continue to insert/update within supported operations.
- **Confidence:** 9/10.

### SEC-1E-003 — HTTP boundary cases are not fully tested

- **Severity:** LOW
- **Status:** CONFIRMED — verification gap; no bypass found by source review
- **Affected files:** `backend/routes/api.php:27-36`; `backend/tests/Feature/ModuleConfigurationTest.php:70-94,215-227`
- **Evidence:** The routes inherit SPA-session, authentication, identity/context, operating-context, and throttle middleware. Tests cover a non-administrator, password failures/rate limiting, and administrator-versus-manager history access, but do not cover all supplied boundary scenarios: bearer-only/no-session access, CSRF rejection, inactive or foreign-organization targets, and history access outside the actor's authorized scope.
- **Impact:** These security boundaries are source-wired but not comprehensively locked down by regression tests. No current authorization bypass was confirmed.
- **Recommended remediation:** Add HTTP-kernel tests for each boundary and assert denial, no state mutation, and correct rejection auditing.
- **Concrete verification test:** Issue authenticated requests under missing SPA session/CSRF, inactive and cross-organization target IDs, and unauthorized history scope; assert rejection and unchanged state/audit invariants.
- **Confidence:** 8/10.

### SEC-1E-004 — Opposing-state and partially overlapping batch races are untested

- **Severity:** LOW
- **Status:** CONFIRMED — verification gap; existing duplicate-enable lock test passed
- **Affected file:** `backend/tests/Feature/ModuleConfigurationConcurrencyTest.php:21-100`
- **Evidence:** The independent-process test exercises overlapping duplicate enables and idempotent retry. It does not exercise concurrent enable-versus-disable requests or bulk selections that overlap on only some locations.
- **Impact:** Final-state and event consistency for those interleavings is not independently established by the current concurrency suite, despite the passing duplicate-enable case.
- **Recommended remediation:** Add process-based PostgreSQL tests for opposing-state updates and partially overlapping bulk operations. Assert final state, operation IDs, transition counts, and attempt outcomes.
- **Concrete verification test:** Hold the organization lock in one process, issue opposing/partially overlapping requests from separate processes, observe lock waits, release in controlled order, and verify persisted state and event history.
- **Confidence:** 9/10.

### SEC-1E-005 — Migration rollback discards module configuration and event detail

- **Severity:** LOW
- **Status:** CONFIRMED — operational/data-retention risk, not a normal-request vulnerability
- **Affected file:** `backend/database/migrations/2026_11_02_000010_create_location_module_configuration.php:89-107`
- **Evidence:** The down migration drops `location_module_states` and removes the module-specific columns from `security_events`. Rolling back this migration therefore deletes module configuration and the fields needed to interpret module-specific audit events.
- **Impact:** An operational rollback after module configuration has been used loses Stage 1E state and event detail. This is an expected consequence of a destructive schema rollback, but should not be mistaken for a data-preserving recovery.
- **Recommended remediation:** Document the rollback as data-destructive, require an explicit backup/export and approval, or use a forward-only recovery plan once the data has operational value.
- **Concrete verification test:** In disposable PostgreSQL only, insert representative state and module events, run the migration rollback, and verify/report exactly which data is removed; never run this against an application database.
- **Confidence:** 10/10.

## 5. RBAC and location-scope analysis

Source-review result: no reportable authorization bypass was identified in the module HTTP flows.

- Module routes are in the existing security route group with first-party SPA session, Sanctum authentication, active/verified identity checks, security rejection auditing, operating context, and throttling.
- The configuration service re-fetches the actor and context, checks eligible administrator role, confirms the current password server-side, and applies target checks after resolving locations.
- Explicit target selection is limited to active locations in the actor's organization; unresolved targets reject the operation. The all-compatible selection is resolved from current active compatible locations and does not create future inheritance.
- Location/module state is independent. Moving or changing a restaurant's managing kitchen does not copy module state; no source-reviewed code path creates configuration for future locations.
- Read endpoints authorize the actor against the operating context and requested location. History is administrator-only and GET-only. Dedicated tests for inactive and out-of-scope history targets remain a gap (SEC-1E-003).
- Password confirmation is required even for idempotent operations; failed attempts are rate-limited. No password value is included in module audit event fields.
- The service's organization lock serializes configuration with operations using the same organization lock. The reviewer found no current HTTP path that accepts client-supplied actor identity or catalog readiness fields.
- The audit did not establish race behavior against all possible concurrent role revocation/account deactivation paths; those require explicit interleaving tests if a stronger guarantee is required.

## 6. Module readiness and fail-closed verification

- Production definitions are exactly `TEMPERATURES`, `CLEANING`, `LOSSES`, and `INVENTORY`, all with `ready=false`.
- Readiness is part of the code-defined catalog. The configuration request does not accept a replacement definition or `ready` flag.
- Unknown module identifiers are rejected. Test fixtures that construct ready definitions are used from test code and are not exposed by the production API.
- The service refuses enable requests for definitions that are not ready. Authorization availability also checks catalog readiness before querying persisted enabled state, so an enabled row alone cannot make a not-ready production module available.
- Missing state yields `DISABLED`; a location with no configuration remains fail-closed.
- No production route that activates an unfinished module was identified. No production module was marked ready during the audit.

## 7. Dependency and atomicity analysis

- Catalog construction rejects malformed/duplicate definitions, unknown dependencies, duplicate dependency entries, and dependency cycles; unit tests cover unknown dependencies and cycles.
- The service checks that dependencies are ready, compatible, and enabled in the same location before enabling a dependent. It does not automatically enable dependencies. Disabling a dependency is rejected if a direct or transitive dependent is enabled; there is no cascading disable.
- Current production definitions have no dependencies. Dependency-positive tests use explicit ready fixtures and do not change production readiness.
- The service resolves a whole target batch before mutation, serializes on organization and ordered location/state locks, and records successful state changes and transition events in the transaction. Rejections roll back the mutation and write rejected attempt events afterward.
- The passing PostgreSQL concurrency case proves the competing duplicate-enable waits on the organization lock and then becomes an idempotent no-op. Opposing-state and partially overlapping batch scenarios remain unverified (SEC-1E-004).
- The full test suite's passing result does not replace those missing interleaving cases or direct database constraint tests.

## 8. Database integrity and audit-history analysis

- The schema has a unique `(location_id, module_key)` configuration key, organization/location foreign keys, actor foreign keys, module-key format checks, immutable state-identity protection, and an append-only security-event trigger.
- Event append-only and state-identity protections were source-reviewed; their negative database writes were not covered by Stage 1E tests.
- **Confirmed defect:** the event-shape CHECK accepts a null `effective_enabled` transition as described in SEC-1E-001.
- Normal API changes preserve role/permission rows on module disable and check current module availability on authorization; the focused suite verifies the intended permission behavior.
- Rejected attempts are not emitted as successful transition events; the focused suite checks rejection, idempotency, and transition counts.
- The down migration is data-destructive for the new state table and module audit details (SEC-1E-005).
- These findings concern invariants and testability. No arbitrary audit-field write route or current API path that reproduces SEC-1E-001 was identified.

## 9. Test coverage and independently reproduced results

### Isolation proof

- Docker context: `default`; daemon: `docker-desktop`; client/server: `29.9.2`.
- Disposable network: `veltra_stage1e_ind_audit_internal`, bridge with `internal=true`.
- Before the test runner started, the network contained only `veltra-stage1e-ind-audit-postgres` and `veltra-stage1e-ind-audit-redis`.
- PostgreSQL container: `veltra-stage1e-ind-audit-postgres`; volume: `veltra_stage1e_ind_audit_pgdata:/var/lib/postgresql/data`; no published ports.
- Redis container: `veltra-stage1e-ind-audit-redis`; no published ports or application volume.
- Read-only PDO probe, before migrations/destructive tests: database `veltra_testing`, role `stage1e_audit`, server IP `172.19.0.2`, data directory `/var/lib/postgresql/data`, PostgreSQL `17.11`. Dedicated Redis `PING` succeeded; Redis version was `7.4.12`.
- Test-runner invocation specified only the internal network, explicit disposable DB host/database/user, read-only temporary backend source mount, temporary writable `tmpfs` runtime directories, and no published port.
- The application PostgreSQL remained on `veltra_default` with `veltra_postgres-data:/var/lib/postgresql/data`; application Redis remained on its existing volume. Neither existing volume was mounted into the runner.

### Commands and results

The commands were run from the repository root, against a temporary backend source copy:

```text
docker context show
=> default

docker version --format 'client={{.Client.Version}} server={{.Server.Version}}'
=> client=29.9.2 server=29.9.2

docker info --format 'daemon={{.Name}} os={{.OperatingSystem}} root={{.DockerRootDir}}'
=> daemon=docker-desktop; Docker Desktop daemon; /var/lib/docker

Read-only PDO probe before migration:
=> veltra_testing | stage1e_audit | 172.19.0.2 | /var/lib/postgresql/data | PostgreSQL 17.11
Dedicated Redis PING:
=> true

php artisan migrate --force
=> all migrations completed on the disposable database

php artisan test --do-not-cache-result tests/Unit/ModuleCatalogTest.php tests/Feature/ModuleConfigurationTest.php tests/Feature/ModuleConfigurationConcurrencyTest.php
=> 15 passed (162 assertions)

php artisan test --do-not-cache-result
=> 124 passed (919 assertions), no reported warnings or failures

Disposable PostgreSQL audit CHECK probe in BEGIN/ROLLBACK:
=> malformed transition with effective_enabled=NULL inserted; valid transition inserted; ROLLBACK left zero probe rows and append-only trigger enabled
```

The disposable containers, internal network, and volume were removed after the runs. All six original application containers remained running; PostgreSQL, Redis, backend, frontend, and Mailpit were healthy. The original `veltra_postgres-data` and `veltra_redis-data` volumes remained present.

### Test-quality gaps

- The new PostgreSQL triggers/checks/FKs lack direct negative-write tests (SEC-1E-002).
- HTTP tests do not cover every session/CSRF/foreign/inactive/history-scope boundary (SEC-1E-003).
- Concurrency coverage lacks enable-versus-disable and partially overlapping batch cases (SEC-1E-004).
- Full live SMTP, production infrastructure, and a frontend administration panel are outside this audit; no business module endpoints are implemented.

## 10. Remaining risks

1. A persisted module transition can have a null effective state through a database writer, weakening audit integrity.
2. Direct database protections are source-defined but not comprehensively guarded by regression tests.
3. Authorization boundary behavior is not covered for every listed HTTP scenario.
4. Lock correctness is verified for one duplicate-enable interleaving, not all opposing/bulk races.
5. A schema rollback removes Stage 1E data and audit detail.
6. Exact approved-spec compliance remains partially unverified because the complete Google Doc body could not be extracted in this audit session.
7. Passing tests do not establish production readiness; all modules remain `NOT_READY`, and business workflows/operational deployment were not audited.

## 11. Recommended remediation priority

1. **Before accepting the audit foundation:** make the transition CHECK explicitly reject `NULL effective_enabled`; add a direct PostgreSQL regression test. Re-run the isolated focused and full suites.
2. Add direct PostgreSQL tests for append-only history, immutable state identity, foreign-key organization/location consistency, and unique state.
3. Add process-based tests for opposite-state and partially overlapping bulk operations.
4. Add HTTP-boundary tests for no-session/bearer-only, CSRF, inactive/foreign targets, and history scope.
5. Document the migration rollback as data-destructive and define the approved backup/forward-recovery procedure.
6. Re-open the approved specification and complete a line-by-line compliance comparison before any Stage 1F work.

## 12. Final verdict

**REJECT / BLOCK STAGE 1F.**

This verdict is based on the confirmed PostgreSQL audit-shape invariant gap and material verification gaps, not on a confirmed remotely exploitable API vulnerability. The full approved specification was not available as extractable text in this audit session, so exact compliance cannot be certified. Do not begin Stage 1F until the priority items are resolved or explicitly risk-accepted and the specification comparison is complete.

## 13. Git and change-scope record

- **Git status before audit output:** `## main` (clean).
- **Git status after audit output:** `## main` with only `VELTRA_STAGE_1E_SECURITY_AUDIT.md` newly untracked.
- Application source, migrations, tests, and existing configuration were not modified by this audit.
- No commit or push was created. No application database or volume was touched. No Stage 1F work was performed.
