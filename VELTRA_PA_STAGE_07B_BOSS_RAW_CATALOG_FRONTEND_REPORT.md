# VELTRA 2.0 — BOSS RAW Catalog Frontend Implementation

## Result

Implemented a dedicated, read-only BOSS catalog route at `/boss/raw-materials`. It uses only the accepted BOSS list and detail endpoints; it does not infer a user's BOSS role in the client or fall back to ordinary RAW endpoints.

## Changed files

- `frontend/src/App.tsx` — direct route, session/context handling, and keyed view lifecycle.
- `frontend/src/ApplicationShell.tsx` — explicit “Katalog surowców BOSS” navigation entry; existing RAW navigation remains.
- `frontend/src/App.css` — responsive BOSS catalog styling.
- `frontend/src/App.test.tsx` — navigation and direct-route coverage.
- `frontend/src/bossRawMaterials/api.ts` — typed BOSS-only list/detail client and strict response validation.
- `frontend/src/bossRawMaterials/api.test.ts` — list/detail contract, supplier semantics, malformed payloads, and HTTP 403/503 coverage.
- `frontend/src/bossRawMaterials/BossRawMaterialsCatalogPage.tsx` — read-only listing, search, pagination, detail, supplier display, errors, request cancellation, and stale-data clearing.
- `frontend/src/bossRawMaterials/BossRawMaterialsCatalogPage.test.tsx` — list/detail, empty state, search/pagination, supplier semantics, denial/unavailability, session expiry, context cancellation, and no-RAW-fallback coverage.

Supplier display preserves the API distinction: `null` is shown as no verified snapshot, `[]` as no suppliers in the approved revision, and names are rendered only from the BOSS API response.

## Verification

- `cd frontend && npm test` — **26 files, 259 tests passed**.
- `cd frontend && npm run build` — passed. Existing warning: generated JS chunk exceeds 500 kB.
- `cd frontend && npm run lint` — passed; only the existing `visualPreview.tsx` Fast Refresh warning remains.
- Isolated backend regression through `scripts/compose-test` on the isolated PostgreSQL 17 `tmpfs` project — **191 tests, 2,130 assertions passed**. Covered `BossRawMaterialCatalogApiTest`, `BossRawMaterialCatalogAuthorizationTest`, `BossRawMaterialCatalogProvenanceTest`, and `RawMaterialsTest`; migrations through 057 passed. The isolated test containers/network were removed afterward; no volumes were removed.
- `git diff --check` — passed.

## Remaining blockers and boundaries

- Production `BOSS_RAW_CATALOG_READ` remains code-level `NOT_READY`; organization activation remains disabled. The BOSS endpoint will therefore remain unavailable until readiness and activation are separately authorized and performed.
- `RAW_MATERIALS` readiness, capability activation, RBAC, backend authorization, visibility policy, and existing databases/environments were not changed.
- No deployment, commit, push, or Google Drive upload was performed.

## Git status

- Branch: `main`
- HEAD: `8acc51bd910c222494ec1123642c3a4e97d3f541`
- Changes are local and uncommitted.
