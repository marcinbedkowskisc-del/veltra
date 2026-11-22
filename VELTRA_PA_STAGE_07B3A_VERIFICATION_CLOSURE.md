# VELTRA PA Stage 07B.3A — Verification Closure

**Status:** Stopped after a targeted test exposed an implementation defect; no behavioral correction made  
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3a`  
**Branch:** `pa-07b3a-readiness-contract`  
**HEAD / accepted baseline:** `09a8cd982f1496a3d6cb80843212a2770540dd70`  
**Recommendation:** **Do not formally accept PA-07B.3A until the commit-time failure path is corrected under owner authorization and the blocked verification gaps are rerun.**

## Summary

The approval-record discrepancy is reconciled in a separate local addendum, while the original schema contract and its historical proposal wording remain unchanged. Eight focused negative-test methods were added and an existing RAW negative test was strengthened.

The first focused run exposed a real defect in deferred-constraint failure handling. PostgreSQL raises a `PDOException` from `PDO::commit()` when the deferred trigger rejects the transaction; `OrganizationCapabilityService::configure()` does not catch that exception type, so the service's rejected-operation audit path is bypassed. Per the task instruction, work stopped before any behavioral correction. The complete backend regression and the remaining unexecuted RAW focus were not run after this discovery.

## Approval reconciliation

Created [VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md](./VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md). It records the authorized 3A scope, accepted base SHA, subsequent owner authorization, the historical meaning of the proposal-only wording, the effective-state audit constraint strengthening, and the explicit exclusions. It asserts no approval timestamp or signature.

The original [VELTRA_PA_STAGE_07B3A_SCHEMA_CONTRACT_REVIEW.md](./VELTRA_PA_STAGE_07B3A_SCHEMA_CONTRACT_REVIEW.md) was not modified.

## Files changed in this closure

- `VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md` — added.
- `backend/tests/Feature/OrganizationCapabilityAuditFailureTest.php` — added deferred commit-time failure case.
- `backend/tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php` — added foreign actor/state attribution case and scope-matrix case.
- `backend/tests/Feature/OrganizationCapabilityTest.php` — added revoked-role, revoked-membership, inactive/unverified identity, inactive-context, dual-role, and cross-organization status/history cases.
- `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php` — asserted that BOSS without an independent RAW grant has no effective `RAW_MATERIALS_VIEW` permission.
- `VELTRA_PA_STAGE_07B3A_VERIFICATION_CLOSURE.md` — this report.

No application behavior, migration, or permanent configuration file was changed. A temporary Compose override was removed after testing.

## Verification run in this task

### Isolated database setup

- Built and used a dedicated `veltra-pa-07b3a-closure-backend:local` image rather than replacing the existing `veltra-test-backend:local` image.
- Started a uniquely named Compose project with PostgreSQL 17, Redis, and ClamAV; PostgreSQL data used the configured container `tmpfs`.
- Ran the fresh migration sequence, including `2026_11_20_000056_create_organization_capability_state`; migration completed successfully.
- Removed only the named isolated Compose project, its dedicated image, temporary synthetic environment/ini files, and temporary Compose override.

### Newly executed focused tests

Command:

```text
php artisan test --no-coverage --compact \
  tests/Feature/OrganizationCapabilityTest.php \
  tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php \
  tests/Feature/OrganizationCapabilityAuditFailureTest.php
```

Result: **20 passed, 6 failed, 230 assertions, 16.17 seconds**. This run is not a passing verification.

The focused output showed:

1. **Implementation defect — deferred commit failure:** the injected deferred constraint trigger raises SQLSTATE `23514` from `PDO::commit()` in `AuthorizationMutationContext::runWithSubject()`. The exception is a `PDOException`, while the service catch at `OrganizationCapabilityService::configure()` lists `QueryException` and selected domain/HTTP exceptions. It therefore bypasses `recordRejected()`. The request returned an internal-server-error response without the expected `CAPABILITY_AUDIT_PERSISTENCE_FAILED` error code. The required post-failure state/audit assertions were not reached. No behavioral fix was attempted.
2. **Test fixture failure — revoked administrator role:** the attempted direct role revocation violated `role_assignments_check1`; authorization assertions were not reached.
3. **Test fixture failure — cross-organization API isolation:** fixture teardown could not delete the foreign location because a membership still referenced it.
4. **Existing fixture incompatibility — cross-organization database cases:** `withForeignOrganizationFixture()` attempted to drop `organizations_single_installation_unique`, which was absent in this fresh schema. Both database tests using that helper stopped before their attribution assertions.
5. **Test expectation failure — scope matrix:** the asserted generic role/RAW scope prefixes did not match the migration's more specific scope rows (including action-specific prefixes). The matrix test stopped at the metadata assertion, before executing its mismatch probes.
6. The remaining focused tests in the run passed, including disabled/`NOT_READY` defaults, ordinary RAW readiness reporting, revoked membership denial, inactive/unverified identity denial, inactive context denial, and BOSS-only denial with explicit administrator dual-role success.

The RAW capability test file containing the strengthened no-independent-grant assertion was not run separately. No complete backend regression was run in this task because the deferred-commit defect activated the explicit stop condition.

### Prior evidence, not rerun here

The earlier implementation report records a complete backend run of **511 passed, 5,073 assertions, zero failures** before these closure-test changes. That result does not cover the newly added tests and does not resolve the defect found here. PHP was not installed on the host; PHP test execution was performed only in the isolated container. `git diff --check` passed after cleanup. Pint was not rerun in this stopped closure.

## Unresolved findings and acceptance recommendation

- **BLOCKER:** Deferred commit-time integrity failures escape the service's rejection-audit handling. This violates the required rejected-operation audit behavior. Stop is in effect; do not make a behavioral correction without the required owner authorization.
- **NON-BLOCKING verification gaps:** The role-revocation test fixture, cross-organization fixture setup/cleanup, and exact signed-scope matrix expectations must be corrected and rerun. The RAW test strengthening was not executed in this task.
- **Readiness boundary:** The closed-code capability remains `NOT_READY`; no organization was activated. The new tests did not modify readiness, RAW behavior, or Stage 1D.

**Formal acceptance is not recommended** until the owner reviews the commit-time audit defect, authorizes any required correction, and the focused cases plus complete isolated backend regression pass. No PA-07B.3B/3C work is included.

## Git status

Branch and HEAD remain `pa-07b3a-readiness-contract` at `09a8cd982f1496a3d6cb80843212a2770540dd70`. Nothing is staged. No commit, push, deployment, capability activation, STAGING/production change, or external upload occurred.

The exact worktree status after report creation is:

```text
## pa-07b3a-readiness-contract
 M backend/app/Domain/Authorization/AuthorizationMutationScope.php
 M backend/app/Http/Middleware/AuditSecurityRejection.php
 M backend/app/Providers/AppServiceProvider.php
 M backend/bootstrap/app.php
 M backend/lang/pl/messages.php
 M backend/routes/api.php
 M backend/tests/Feature/RawMaterialCapabilitiesApiTest.php
 M backend/tests/Unit/AuthorizationMutationScopeTest.php
?? VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md
?? VELTRA_PA_STAGE_07B3A_FINAL_REVIEW.md
?? VELTRA_PA_STAGE_07B3A_IMPLEMENTATION_REPORT.md
?? VELTRA_PA_STAGE_07B3A_SCHEMA_CONTRACT_REVIEW.md
?? VELTRA_PA_STAGE_07B3A_VERIFICATION_CLOSURE.md
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

The untracked PA-07B.3A implementation files and earlier reports/contracts were already present in this authorized worktree before this closure task; they were not staged or committed here.
