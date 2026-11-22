# PA-07B.3A Defect Correction Report

## Scope and outcome

This report covers only the owner-authorized correction of deferred PostgreSQL commit-failure handling and negative-test fixtures. The accepted base is `09a8cd982f1496a3d6cb80843212a2770540dd70`; work remained on `pa-07b3a-readiness-contract`.

The correction is implemented and verified in an isolated test environment. No commit, push, deployment, capability activation, production/STAGING change, or PA-07B.3B/3C work was performed.

## Root cause and corrected behavior

When a deferred PostgreSQL constraint failed during the outer Laravel transaction's `COMMIT`, PDO raised a `PDOException`, not the `QueryException` handled by the capability service. The failure therefore bypassed the normal rejected-operation audit path. Separately, a connection/commit error cannot safely be assumed to mean rollback.

The correction is limited to capability operations that opt into commit-outcome classification:

- If the operation callback completed and PostgreSQL reports a class-23 constraint failure, Laravel's transaction level is zero, and PDO confirms no active transaction, the commit is classified as a confirmed rollback. The original PDO exception is preserved in the exception chain.
- For a confirmed rollback, the service attempts to append the rejected operation in a separate signed transaction. A successful rejection audit returns the safe `CAPABILITY_AUDIT_PERSISTENCE_FAILED` response; audit persistence failure is also surfaced with that safe error code. Database internals are not included in the client response.
- Other commit-time PDO failures are treated as uncertain. The service logs the operation identifier and SQLSTATE, does not retry the mutation or claim that a capability rejection event was persisted, and returns `503 CAPABILITY_COMMIT_OUTCOME_UNKNOWN`.
- Existing authorization-mutation callers retain the original behavior; the new classification path is opt-in.

## Files changed for this correction

- `backend/app/Domain/Authorization/AuthorizationMutationContext.php` — opt-in commit-outcome classification and confirmed-rollback checks.
- `backend/app/Domain/Authorization/AuthorizationMutationCommitException.php` — carries rollback certainty and the original PDO exception.
- `backend/app/Domain/Modules/OrganizationCapabilityService.php` — confirmed-rollback audit attempt and fail-closed handling for uncertain outcomes.
- `backend/app/Domain/Modules/OrganizationCapabilityException.php` — supports preserving the underlying exception.
- `backend/lang/pl/messages.php` — safe uncertainty response message.
- `backend/tests/Feature/OrganizationCapabilityAuditFailureTest.php` — deferred commit rollback, rejection audit, exception chain, audit failure, and uncertain outcome coverage.
- `backend/tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php` — scope matrix assertions aligned with the migration's action-specific scopes and valid boundary probes.
- `backend/tests/Feature/OrganizationCapabilityTest.php` — supported administrator-role revocation and append-only-aware cross-organization fixtures.
- `backend/tests/InstallationTestCase.php` — opt-in cleanup for disposable cross-organization fixtures that contain append-only history.

Fixture repairs preserve the negative assertions. They use supported role-revocation behavior, avoid deleting append-only identity/membership history, keep cross-organization fixtures compatible with the single-installation constraint, and use valid table rows when probing signed-scope denials.

## Behavior before and after

| Case | Before | After |
|---|---|---|
| Deferred constraint fails at COMMIT | Raw PDO failure bypassed the service's QueryException audit handling. | Confirmed rollback is distinguished, the original exception is retained, and a separate signed rejection audit is attempted. |
| Audit persistence fails | No reliable safe capability error was guaranteed through the commit-failure path. | The client receives `CAPABILITY_AUDIT_PERSISTENCE_FAILED`; database details remain server-side. |
| Commit outcome is uncertain | Could not safely classify the outcome or guarantee audit state. | No blind retry or capability rejection-audit claim; fail closed with `CAPABILITY_COMMIT_OUTCOME_UNKNOWN` (503). |

## Verification

| Check | Result |
|---|---|
| Fresh PostgreSQL 17 migration through `2026_11_20_000056_create_organization_capability_state` | Passed. |
| Previously failing focused cases | 8 passed, 89 assertions. |
| PA-07B.3A capability tests plus `RawMaterialCapabilitiesApiTest` | 56 passed, 551 assertions. |
| Complete backend Pint run | 389 files passed. |
| `git diff --check` | Passed. |
| First complete backend regression run | Exited 2 because a PHP child process hit its default 128 MB memory limit in `RawMaterialsTest::test_stage3e_b3_content_filename_and_size_validation_fail_closed`. This run is not counted as passing. |
| Complete backend regression rerun in a fresh isolated PostgreSQL 17 environment, with 512 MB configured for PHP CLI and child processes | Exit code 0; 521 passed, 5,188 assertions; duration reported as 834.36 seconds. |

The isolated regression containers and temporary Compose/PHP configuration were removed after verification. No test was skipped to obtain a passing result.

## Readiness and security boundaries

- `BOSS_RAW_CATALOG_READ` remains code-level `NOT_READY`; the closed-code catalog still declares `ready: false`.
- No organization capability was enabled.
- `RAW_MATERIALS`, existing Stage 1D/RBAC grants, ordinary RAW authorization, and module activation were not intentionally changed.
- The focused ordinary RAW negative test passed as part of the 56-test run.
- No production or STAGING database or service was used.

## Unresolved item

The explicit `CAPABILITY_COMMIT_OUTCOME_UNKNOWN` response code is an additive API code chosen to satisfy the requirement to report uncertainty. The authorization described the fail-closed behavior but did not prescribe this exact code. This is not a test failure or identified security defect; the owner should confirm the code and client-facing semantics during acceptance.

## Git status

Branch: `pa-07b3a-readiness-contract`  
HEAD: `09a8cd982f1496a3d6cb80843212a2770540dd70`  
No staged changes; no commit was created. The worktree remains dirty with the following tracked modifications and untracked PA-07B.3A implementation/review artifacts. The correction report itself is also untracked.

```text
 M backend/app/Domain/Authorization/AuthorizationMutationContext.php
 M backend/app/Domain/Authorization/AuthorizationMutationScope.php
 M backend/app/Http/Middleware/AuditSecurityRejection.php
 M backend/app/Providers/AppServiceProvider.php
 M backend/bootstrap/app.php
 M backend/lang/pl/messages.php
 M backend/routes/api.php
 M backend/tests/Feature/RawMaterialCapabilitiesApiTest.php
 M backend/tests/InstallationTestCase.php
 M backend/tests/Unit/AuthorizationMutationScopeTest.php
?? VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md
?? VELTRA_PA_STAGE_07B3A_DEFECT_CORRECTION_REPORT.md
?? VELTRA_PA_STAGE_07B3A_FINAL_REVIEW.md
?? VELTRA_PA_STAGE_07B3A_IMPLEMENTATION_REPORT.md
?? VELTRA_PA_STAGE_07B3A_SCHEMA_CONTRACT_REVIEW.md
?? VELTRA_PA_STAGE_07B3A_VERIFICATION_CLOSURE.md
?? backend/app/Domain/Authorization/AuthorizationMutationCommitException.php
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

## Recommendation

Recommend owner acceptance of the narrowly scoped correction: the deferred-commit rollback and uncertain-outcome paths are covered, the focused and complete backend suites pass, and the fixtures retain their negative security assertions. Acceptance should explicitly confirm the additive `CAPABILITY_COMMIT_OUTCOME_UNKNOWN` response code. No further stage work has been started.
