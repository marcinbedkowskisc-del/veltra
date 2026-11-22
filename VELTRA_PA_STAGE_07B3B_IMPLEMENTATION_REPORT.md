# VELTRA PA-07B.3B Implementation Report

## Baseline and isolation

- Branch: `pa-07b3b-boss-raw-catalog`
- Worktree: `/home/veltra/projekty/veltra-pa-07b3b`
- HEAD: `b47085e17810fea5d659b3245d04c542608f07d6`
- Accepted base SHA: `b47085e17810fea5d659b3245d04c542608f07d6`
- The dedicated worktree remains at the accepted base commit; changes are uncommitted and unstaged.
- Test execution used the guarded `scripts/compose-test` wrapper and the `veltra-test-isolated` Docker context with synthetic fixtures. No primary local database, STAGING, or production database was used.

## Implementation

Implemented only the two read-only routes:

- `GET /api/v1/boss/raw-materials`
- `GET /api/v1/boss/raw-materials/{material}`

Authorization and locking:

- Added typed BOSS list/detail intents and explicit BusinessVisibility surface decisions.
- Added read-only organization capability access evaluation. BOSS capability readiness remains `ready: false`; explicit organization activation remains required and disabled by default.
- Acquires the organization row with `FOR SHARE` before reloading identity and revalidating the exact operating membership ID, location, and organization. The effective BOSS role, capability state, and global `RAW_SUPPLIERS` restriction are evaluated before reading catalog data.
- No ordinary RAW permission or readiness fallback is used.

Approved projection and DTO:

- List, count, and detail share one organization-scoped approved-material query.
- Requires an `ACTIVE`, non-deleted material, its current approved revision, and a matching `APPROVED` event/content hash. Uses the current organization-scoped category name; archived categories remain eligible when referential integrity holds.
- The DTO exposes only `id`, `code`, `name`, `category.id`, `category.name`, and `base_unit_code`.
- Search, sort, filter, pagination, and counts operate only on that projection. Search treats SQL wildcard characters literally.
- No supplier, financial, descriptive, allergen, document, share, audit, or working/pending revision fields are selected or returned.

API and error handling:

- Added typed request validation, controller, and the two BOSS routes without reusing ordinary RAW controllers, serializers, grants, or readiness middleware.
- Responses and route-scoped middleware/framework errors receive safe envelopes and `no-store` handling.

## Verification

- Focused PA-07B.3B authorization, API, provenance, and concurrency tests: **21 tests, 222 assertions passed**.
- Relevant existing BOSS security, ordinary RAW, capability, and Stage 1D regressions: **220 tests, 2,605 assertions passed**.
- Complete backend PHPUnit regression, run directly with `php -d memory_limit=512M vendor/bin/phpunit --no-coverage` in the isolated Compose environment: **542 tests, 5,410 assertions passed**.
- Full backend PHP Pint check: **401 files passed**.
- `git diff --check`: passed.
- Multi-process concurrency tests cover reader-first and writer-first BOSS role revocation, exact membership revocation, global `RAW_SUPPLIERS` restriction changes, capability disable, and replacement membership at the same location. The lock wait and post-commit authorization behavior passed.
- Synthetic `EXPLAIN (FORMAT TEXT)` inspection showed the shared query using existing indexes and nested-loop/semi-join execution. No migration or index change is proposed.

The initial full-suite attempt through `artisan test` spawned PHPUnit with PHP's default 128 MB limit and terminated on memory exhaustion. The complete suite was rerun directly with the required 512 MB limit and passed; no tests were skipped.

## Files and responsibilities

Modified:

- `backend/app/Domain/Authorization/BusinessAuthorization.php` — dispatches BOSS catalog intents through explicit visibility authorization.
- `backend/app/Domain/Authorization/BusinessVisibility.php` — evaluates BOSS catalog visibility and organization capability.
- `backend/app/Domain/Authorization/BusinessVisibilitySurface.php` — carries typed BOSS intent metadata.
- `backend/bootstrap/app.php` — normalizes route-scoped framework errors and applies no-store behavior.
- `backend/lang/pl/messages.php` — safe catalog availability message.
- `backend/routes/api.php` — registers the two BOSS GET routes.

Added:

- `backend/app/Domain/Authorization/BossRawCatalogIntent.php` — typed list/detail intents.
- `backend/app/Domain/Modules/OrganizationCapabilityAccess.php` — read-only capability access evaluation.
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php` — exact allowlisted DTO.
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php` — locking, authorization, approved projection, filtering, and pagination.
- `backend/app/Http/Controllers/Api/V1/BossRawMaterialCatalogController.php` — thin read-only API controller.
- `backend/app/Http/Middleware/BossRawCatalogNoStore.php` — no-store response handling.
- `backend/app/Http/Requests/ListBossRawMaterialsRequest.php` — list query validation.
- `backend/tests/Feature/BossRawMaterialCatalogApiTest.php` — response, validation, errors, cache, and restriction behavior.
- `backend/tests/Feature/BossRawMaterialCatalogAuthorizationTest.php` — authorization and negative cases.
- `backend/tests/Feature/BossRawMaterialCatalogConcurrencyTest.php` — multi-process locking and revocation tests.
- `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php` — approval evidence and organization isolation tests.
- `backend/tests/Support/concurrent_boss_raw_catalog.php` — concurrency test worker.

## Git status and scope

- Six tracked files modified and twelve implementation/test files added; the report itself is also a new untracked file.
- No staged changes, commits, pushes, or merges.
- No migrations or indexes added.
- Production `BOSS_RAW_CATALOG_READ` readiness remains `false`. No real organization capability was activated.
- Ordinary RAW readiness, permissions, grants, and routes, and Stage 1D/RBAC permissions were not changed.
- No unresolved defects or contract deviations identified.

## Recommendation

Ready for owner review against `VELTRA_PA_STAGE_07B3B_FINAL_CONTRACT_V1.md`. This report is not an acceptance, readiness activation, or authorization to begin PA-07B.3C.
