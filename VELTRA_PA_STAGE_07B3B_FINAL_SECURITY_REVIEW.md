# VELTRA PA-07B.3B Final Security Review

## Verdict

# BLOCKED

The implementation’s BOSS authorization path appears to enforce the required BOSS role and does not use ordinary RAW grants, Stage 1D permissions, or synthetic central-kitchen membership. However, the required negative tests proving RAW-only and Stage 1D-only access cannot substitute for BOSS authorization are absent. This is an acceptance-evidence blocker, not a demonstrated production authorization bypass.

## Review baseline and scope

- Worktree: `/home/veltra/projekty/veltra-pa-07b3b`
- Branch: `pa-07b3b-boss-raw-catalog`
- HEAD: `b47085e17810fea5d659b3245d04c542608f07d6` (expected)
- Binding contract: [VELTRA_PA_STAGE_07B3B_FINAL_CONTRACT_V1.md](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3B_FINAL_CONTRACT_V1.md)
- Implementation report: [VELTRA_PA_STAGE_07B3B_IMPLEMENTATION_REPORT.md](./VELTRA_PA_STAGE_07B3B_IMPLEMENTATION_REPORT.md)
- The complete tracked diff and all new implementation/test files were reviewed. This was a read-only review; no tests were run independently and no application files were changed.

## Findings

### 1. Required fallback-negative authorization tests are missing

- **Severity:** BLOCKER
- **Classification:** Acceptance-evidence gap; no production bypass demonstrated
- **Confidence:** 10/10
- **Location:** [BossRawMaterialCatalogAuthorizationTest.php](./backend/tests/Feature/BossRawMaterialCatalogAuthorizationTest.php#L88)
- **Related implementation:** [BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L195), [BusinessVisibility.php](./backend/app/Domain/Authorization/BusinessVisibility.php#L85)

The test at lines 88–110 revokes the BOSS role and expects denial, but its setup at lines 39–49 does not give the actor RAW permission or Stage 1D authority. It therefore does not prove that either alternate authority, by itself, is insufficient for BOSS catalog access.

Static inspection found that the catalog service builds a typed BOSS visibility surface and invokes `BusinessVisibility::decide()` directly ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L195); [BusinessVisibility.php](./backend/app/Domain/Authorization/BusinessVisibility.php#L34)). The BOSS branch requires eligibility and an organization BOSS role ([BusinessVisibility.php](./backend/app/Domain/Authorization/BusinessVisibility.php#L85)); otherwise it denies. `BusinessAuthorization::allows()` is a separate permission-based path and is not called by the catalog service ([BusinessAuthorization.php](./backend/app/Domain/Authorization/BusinessAuthorization.php#L61)).

**Reproduction condition for the missing evidence:** Use a valid operating membership and READY+ENABLED test-only capability, but give the actor only (a) ordinary RAW permission or (b) Stage 1D authority and no BOSS role. The current authorization test does not construct either condition.

**Impact:** A future refactor could introduce permission fallback without the required tests detecting it. The reviewed implementation path itself showed no such fallback.

**Minimal correction:** Add separate RAW-only and Stage 1D-only denial cases, for both list and detail intents as required by the contract, using only isolated test providers and synthetic fixtures.

### 2. Implementation report file-count wording is inaccurate

- **Severity:** NON-BLOCKING
- **Classification:** Documentation discrepancy
- **Confidence:** 10/10
- **Location:** [VELTRA_PA_STAGE_07B3B_IMPLEMENTATION_REPORT.md](./VELTRA_PA_STAGE_07B3B_IMPLEMENTATION_REPORT.md#L79)

The inventory says thirteen implementation files were added, but the reviewed worktree status contains twelve untracked implementation/test files plus the implementation report. This does not affect implementation behavior or the security assessment.

**Minimal correction:** Update the inventory count to twelve implementation/test files, or clarify what additional file is included in the count.

## Security checklist

### Authorization and permission fallback

- The typed intent is limited to list/detail ([BossRawCatalogIntent.php](./backend/app/Domain/Authorization/BossRawCatalogIntent.php#L5)).
- Surface validation binds the intent to the BOSS source, matching operation, `RAW_SUPPLIERS` category, and organization provenance ([BusinessVisibilitySurface.php](./backend/app/Domain/Authorization/BusinessVisibilitySurface.php#L30)).
- The catalog path constructs that surface and calls `BusinessVisibility::decide()` directly ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L195)). It does not call `BusinessAuthorization::allows()`, whose independent permission-based path is separate ([BusinessAuthorization.php](./backend/app/Domain/Authorization/BusinessAuthorization.php#L61)).
- The BOSS decision requires eligibility and the organization BOSS role; there is no RAW-grant or Stage 1D fallback in that branch ([BusinessVisibility.php](./backend/app/Domain/Authorization/BusinessVisibility.php#L85)).
- The service requires the request membership ID and revalidates that exact active membership against the actor, organization, and operating location before the visibility decision ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L168)). No synthetic central-kitchen membership fallback was found.

### Lock order and concurrency tests

- The service transaction first takes the organization row shared lock, then reloads/revalidates the actor, then validates the exact membership ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L141)).
- The concurrency test source starts separate PHP processes and checks PostgreSQL lock waits ([BossRawMaterialCatalogConcurrencyTest.php](./backend/tests/Feature/BossRawMaterialCatalogConcurrencyTest.php#L296)). Its worker invokes the real read/mutation services ([concurrent_boss_raw_catalog.php](./backend/tests/Support/concurrent_boss_raw_catalog.php#L68)).
- This supports that the tests are genuinely multi-process rather than simulated locking. The tests were not rerun as part of this read-only review; their passing status is taken from the implementation report, not independently verified.

### Approved projection, organization isolation, and response fields

- List, count, and detail use the same approved-material query, with filters applied to the list/count clone ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L62)).
- The query scopes material, revision, and category joins to the organization and requires active, non-deleted materials ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L232)).
- It requires the current approved revision and an `APPROVED` event whose content hash matches; it does not fall back to working or pending revisions ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L248)).
- The selected columns are limited to material ID/code, approved name, category ID/name, and base unit ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L261)); the DTO emits only the contracted keys ([BossRawMaterialCatalogItem.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php#L25)).
- Search is limited to name/code with wildcard escaping; category/unit filters and sort columns operate on the approved projection ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L282)). Request validation bounds accepted parameters ([ListBossRawMaterialsRequest.php](./backend/app/Http/Requests/ListBossRawMaterialsRequest.php#L9)).
- The inspected API and service paths did not expose supplier, financial, description, allergen, document, share, audit, or working/pending revision fields.

### Errors, caching, routes, and readiness

- The service returns a not-found response for absent detail items, denies unauthorized decisions, and maps catalog dependency query failures to unavailable responses ([BossRawMaterialCatalogService.php](./backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php#L114)).
- Route-scoped bootstrap handling normalizes error envelopes and applies no-store behavior, including errors raised before route middleware ([bootstrap/app.php](./backend/bootstrap/app.php#L41)); successful responses use the catalog no-store middleware ([BossRawCatalogNoStore.php](./backend/app/Http/Middleware/BossRawCatalogNoStore.php#L9)).
- The route group exposes only the two GET catalog endpoints; no write, export, or supplier operation is registered there ([api.php](./backend/routes/api.php#L131)).
- Production `BOSS_RAW_CATALOG_READ` remains `ready: false` ([CodeOrganizationCapabilityCatalog.php](./backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php#L26)). Capability access also requires a ready definition and enabled organization state ([OrganizationCapabilityAccess.php](./backend/app/Domain/Modules/OrganizationCapabilityAccess.php#L12)).
- No RAW readiness, permission, grant, route, or Stage 1D/RBAC change was found in the diff.

### Tests, Git state, and artifacts

- Static inspection of the four added feature test files found no skip calls. Fixtures use synthetic test-only providers/data; the concurrency worker checks its test database/runtime identity ([concurrent_boss_raw_catalog.php](./backend/tests/Support/concurrent_boss_raw_catalog.php#L26)).
- The implementation report claims the focused and full test suites passed, but those executions were not independently reproduced during this read-only review.
- Verified Git state: six tracked files modified, no staged changes, and thirteen untracked files including twelve implementation/test files and the implementation report. No migrations, generated binaries, or unrelated worktree files were identified.
- No production credentials were identified. The literal concurrency-worker password is confined to its test-only code path ([concurrent_boss_raw_catalog.php](./backend/tests/Support/concurrent_boss_raw_catalog.php#L119)).

## Recommendation

Do not grant owner acceptance until the required RAW-only and Stage 1D-only denial tests are added and reviewed. No application changes, test changes, migrations, commits, deployments, or capability activation were made as part of this review.
