# VELTRA PA Stage 07B.3A — Implementation Report

**Status:** Implemented in the authorized isolated worktree; owner review required  
**Base:** `09a8cd982f1496a3d6cb80843212a2770540dd70`  
**Branch:** `pa-07b3a-readiness-contract`  
**Scope:** Organization-level activation state, append-only audit, closed capability catalog, and administrator API only

## Summary

Implemented the approved organization-scoped `BOSS_RAW_CATALOG_READ` capability contract. Code readiness remains `NOT_READY`; an absent organization state remains `DISABLED`; no organization was enabled. The ordinary `RAW_MATERIALS` module, existing RAW permissions and endpoints, location module states, delegation, and Stage 1D RBAC were not changed.

The activation state is a readiness/configuration gate only. It does not grant data access, change BOSS membership, or expose a RAW projection. No PA-07B.3B/3C behavior or frontend work is included.

## Implemented files

- `backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php`
- `backend/app/Domain/Modules/OrganizationCapabilityDefinition.php`
- `backend/app/Domain/Modules/OrganizationCapabilityCatalog.php`
- `backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php`
- `backend/app/Domain/Modules/OrganizationCapabilityService.php`
- `backend/app/Domain/Modules/OrganizationCapabilityException.php`
- `backend/app/Domain/Modules/OrganizationCapabilityAuditException.php`
- `backend/app/Http/Controllers/Api/V1/OrganizationCapabilityController.php`
- `backend/app/Http/Requests/OrganizationCapabilityActivationRequest.php`
- `backend/app/Http/Requests/OrganizationCapabilityHistoryRequest.php`
- `backend/tests/Feature/OrganizationCapabilityTest.php`
- `backend/tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php`
- `backend/tests/Feature/OrganizationCapabilityConcurrencyTest.php`
- `backend/tests/Feature/OrganizationCapabilityAuditFailureTest.php`
- `backend/tests/Unit/OrganizationCapabilityCatalogTest.php`
- `backend/tests/Support/OrganizationCapabilityCatalogFixtures.php`
- `backend/tests/Support/concurrent_organization_capability_configure.php`

Also updated the signed-scope factory and its unit test, the service-provider catalog binding, authenticated security routes, security-rejection middleware, API exception handling, and Polish messages.

## Database and security

The additive migration creates `organization_capability_states` and append-only `organization_capability_events`. It includes organization/actor/context integrity constraints, immutable state identity, initial enable-only state creation, exact version increments, unique operation/event-kind keys, deferred state-to-transition and attempt/transition checks, row locking on the organization, and signed mutation triggers.

The runtime scopes added are exactly:

- State insert/update: `organization-capability.configure`
- Event insert: `organization-capability.audit`

Runtime grants are state `SELECT, INSERT, UPDATE` and events `SELECT, INSERT`; no delete, truncate, event update, or sequence grant was added. Trigger functions use schema-qualified relations and a pinned `search_path`.

The approved event `CHECK` was tightened so a rejected attempt must record its unchanged effective state, and an accepted no-op must have effective state equal to the existing state. This closes an audit inconsistency in the approved SQL without weakening any invariant. No other contract deviation was made.

## API

All routes remain under the existing authenticated `/api/v1/security` session, identity, operating-context and throttle stack:

- `GET /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ`
- `PUT /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ`
- `GET /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ/history`

The GET endpoints and mutation service require an active verified administrator with a current organization membership context. PUT also requires fresh password confirmation and an expected state version. The service revalidates the actor, organization, role, context and version under an organization-row lock. Operation IDs are generated server-side.

Stale versions return `409 CAPABILITY_STATE_VERSION_CONFLICT`; enabling the code-level `NOT_READY` capability returns `409 CAPABILITY_NOT_READY`. Rejections roll back the mutation transaction and are recorded in a separate signed audit transaction. Audit persistence failure is surfaced as HTTP 500 and cannot return success. Status reports `available: false` until both code readiness and explicit organization activation permit it.

## Verification

### PostgreSQL migration

- Fresh complete migration sequence ran successfully on PostgreSQL `17.11`, including migration `2026_11_20_000056`.
- Feature tests inspect the installed function search paths, deferred triggers, exact scope allowlist, runtime table allowlist, and table privileges.
- Integrity tests cover identity/version manipulation, append-only audit, missing/orphan/mismatched attempt-transition rows, inconsistent rejected/idempotent effective states, duplicate event kinds, cross-organization references, and incorrect signed scopes.
- Transaction tests cover accepted-audit failure rollback and rejected-audit failure reporting.
- A concurrent-request test verifies serialization into one accepted transition and one stale-version rejection.

### Focused tests

- Organization-capability focused suite: **20 passed, 202 assertions**.
- Scanner-dependent regression classes: **18 passed, 151 assertions** after the isolated ClamAV service became healthy.
- PHP Pint: **24 changed PHP files passed**.
- `git diff --check`: passed.

### Complete backend regression

- Full backend suite: **511 passed, 5,073 assertions, zero failures**.
- Duration: **819.68 seconds**.
- Command: `php artisan test --no-coverage --compact` in the isolated backend test container.
- Ran against isolated PostgreSQL 17.11, Redis, and ClamAV. The test container's PHP CLI memory limit was set to 512 MB for the run.
- Two earlier attempts did not complete: one started before ClamAV was ready, and another exhausted the default PHP child-process memory limit. They are not counted as test failures; the completed run passed.

No frontend files were changed, so frontend regression checks were not applicable. No browser UI was introduced or verified.

## Environment isolation

Testing uses a uniquely named Compose project and the repository's PostgreSQL 17 test topology with database storage in container `tmpfs`. A dedicated image tag was used; the existing `veltra-test-backend:local` image and existing application containers were not replaced or stopped. No production, STAGING, primary local database, or organization capability state was touched.

## Limitations and owner review

- Capability readiness intentionally remains `NOT_READY`; enabling is therefore rejected.
- The new API is not a RAW catalog read API and does not change current RAW readiness behavior.
- No browser UI work is included in PA-07B.3A.
- The report was not uploaded to Google Drive, per the user's explicit direction in this session.

## Working-tree status

Implementation remains uncommitted and unpushed in the dedicated worktree on `pa-07b3a-readiness-contract`, at base HEAD `09a8cd982f1496a3d6cb80843212a2770540dd70`. The owner-provided schema contract file remains unmodified and untracked there.

The original `/home/veltra/projekty/veltra` worktree remains on `main`. Every pre-existing PA-07B.2/preview path reported by Git remains preserved; no branch or Git history changes were made.

The following is the dedicated worktree's `git status --short --branch` snapshot after implementation and report creation; no paths are staged:

```text
## pa-07b3a-readiness-contract
 M backend/app/Domain/Authorization/AuthorizationMutationScope.php
 M backend/app/Http/Middleware/AuditSecurityRejection.php
 M backend/app/Providers/AppServiceProvider.php
 M backend/bootstrap/app.php
 M backend/lang/pl/messages.php
 M backend/routes/api.php
 M backend/tests/Unit/AuthorizationMutationScopeTest.php
?? VELTRA_PA_STAGE_07B3A_IMPLEMENTATION_REPORT.md
?? VELTRA_PA_STAGE_07B3A_SCHEMA_CONTRACT_REVIEW.md
?? backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php
?? backend/app/Domain/Modules/OrganizationCapabilityAuditException.php
?? backend/app/Domain/Modules/OrganizationCapabilityCatalog.php
?? backend/app/Domain/Modules/OrganizationCapabilityDefinition.php
?? backend/app/Domain/Modules/OrganizationCapabilityException.php
?? backend/app/Domain/Modules/OrganizationCapabilityService.php
?? backend/app/Http/Controllers/Api/V1/OrganizationCapabilityController.php
?? backend/app/Http/Requests/OrganizationCapabilityActivationRequest.php
?? backend/app/Http/Requests/OrganizationCapabilityHistoryRequest.php
?? backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php
?? backend/tests/Feature/OrganizationCapabilityAuditFailureTest.php
?? backend/tests/Feature/OrganizationCapabilityConcurrencyTest.php
?? backend/tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php
?? backend/tests/Feature/OrganizationCapabilityTest.php
?? backend/tests/Support/OrganizationCapabilityCatalogFixtures.php
?? backend/tests/Support/concurrent_organization_capability_configure.php
?? backend/tests/Unit/OrganizationCapabilityCatalogTest.php
```

In the original worktree, Git continues to report `.gitignore` modified and these eight untracked paths: `VELTRA_PA_STAGE_07B2_BROWSER_VERIFICATION_REPORT.md`, `VELTRA_PA_STAGE_07B2_ENV_VERIFICATION_REPORT.md`, `VELTRA_PA_STAGE_07B2_OWNER_ACCEPTANCE.md`, `VELTRA_PA_STAGE_07B3_IMPLEMENTATION_PLAN.md`, `backend/Dockerfile.browser-preview`, `backend/database/seeders/BrowserPreviewSeeder.php`, `compose.browser-preview.yaml`, and `frontend/vite.browser-preview.config.ts`. None was changed by this implementation.

**No commit, push, deployment, capability activation, or non-isolated database change was performed.**
