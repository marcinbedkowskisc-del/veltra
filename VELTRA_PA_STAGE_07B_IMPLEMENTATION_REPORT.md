# VELTRA 2.0 — PA-07B

**Status: PARTIALLY IMPLEMENTED — not ready for owner acceptance.**

## Repository and scope

Preflight: clean `main` at `b2b41b59b838cf7b6ed47d0afc4e9049b8a8a406`, with implementation commit `c1d843c560d7d7f975107f71f78090d119c00bd3` in its history. Both commits were present before changes. The accepted PA-07A baseline, the [historical PA-07 assessment](./VELTRA_PA_STAGE_07_IMPLEMENTATION_REPORT.md), the [PA-07A acceptance record](./VELTRA_PA_STAGE_07A_IMPLEMENTATION_REPORT.md), and the [approved RBAC amendment](./VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md) are preserved. No redesign, PA-08, deployment, push, STAGING or production work.

## Feasibility and delivery matrix

| Surface | Existing access, available query boundary and risk | Required change and status |
|---|---|---|
| Location directory | Stage 1D BOSS had a restaurant permission check; `/api/v1/locations` is a development management endpoint, not a BOSS read API. A location row has a definitive installation and ID and can be filtered without projecting other locations or finances. | **SAFE TO IMPLEMENT / IMPLEMENTED AND VERIFIED (API):** dedicated read-only directory of active central kitchens and restaurants; excludes rows before projection and type filtering. No relationship, configuration, or financial data in the response. |
| Location permission probe and module metadata | Stage 1D reads restaurant location scope; module operations independently require their existing gates. Metadata is not a business-module data projection. | **IMPLEMENTED AND VERIFIED (location permission probe):** clamp the existing BOSS branch for excluded restaurants without changing ordinary-role decisions. Existing operating-context membership and Stage 1E activation checks remain unchanged. |
| RAW list, details, editor, revisions, history, sharing, suppliers, compatibility, and documents | `RawMaterialAuthorization` uses organization-wide, independently awarded grant lineages; restaurant shares express visibility, not ownership. The catalog and historical/commercial references cannot be partitioned soundly using those shares. Existing ordinary projections can show supplier display names without granting purchasing costs. Documents and history may combine or embed data. | **REQUIRES ARCHITECTURAL CHANGE / BLOCKED:** no BOSS-derived RAW grant. Preserve each account's existing independent RAW authorization unchanged, even if it also has BOSS restrictions. The route-level BOSS/RAW veto was removed; the regression test for an effective independent RAW grant is added but remains unverified. No delegation override or ownership/sharing migration. |
| Purchase costs, reference prices, exchange rates, price events and RAW exports | Existing endpoints use separate RAW financial permissions; CSV, document downloads and derived data have potentially different projections. An organization-wide grant does not establish a location for a financial fact. | **BLOCKED:** BOSS receives no new access to these endpoints, including exports. Future projections need proven attribution for every contributing record and permission-aware field selection before filtering, sorting, aggregation and serialization. |
| Foodcost, recipes, production and warehouses | Stage 1D permission names and Stage 1E readiness metadata do not establish implemented business-data projections. No applicable BOSS data path was identified. | **NOT_READY / NOT APPLICABLE:** no new grant, readiness change, or BOSS-derived data access. Future foodcost must remain independently restricted, withholding only values that reveal denied purchase prices when separable. |
| Business reports, exports, analytics and statistics | `ReportAuthorization` exposes an authorization contract; the application binds `ReportCatalog` to `UnavailableReports`. There is no business report payload/query API to filter. RAW downloads are separate and do not inherit report grants. | **NOT_READY / NOT APPLICABLE** for business report outputs. Existing report share and legacy BOSS permission checks now respect known source/target location exclusions. Future reports must authorize each source within one applicable scope, filter before aggregation, and withhold non-attributable results in every format. |
| Administrator controls | PA-07A already protects the user directory with SPA session, identity, operating context and administrator middleware. | **PARTIALLY IMPLEMENTED:** wired location, category, and category/location restrictions into BOSS details with persisted state and conflict feedback. No business-data projection currently consumes the categories, so no BOSS category-based data access is enabled. Current frontend regression test, build, and lint pass; API verification and live browser verification remain open. |

## Contracts and persistence

- `GET /api/v1/security/boss/locations` requires effective BOSS and a valid operating context. Optional `type` filters the already restricted result. Response fields: `id`, `name`, `code`, `type`. No BOSS-derived write route.
- `GET` and `PUT /api/v1/administration/users/{user}/boss-location-restrictions` require an eligible administrator and a same-installation BOSS target other than the actor. `PUT` replaces the location, category, and category/location exclusions using the last read version; stale writes return HTTP 409. Version-1 clients may still update location-only restrictions without dropping category state. An empty set removes the corresponding exclusions. Private, no-store responses.
- Migration `2026_11_19_000054` adds `boss_location_restriction_revisions`; additive migration `2026_11_19_000055` extends append-only snapshots with category restrictions and verifies them in the database trigger. The runtime role may read and append using the existing signed ticket mechanism, not update or delete revisions. Each changed revision stores target, actor, previous and new policy, version, and time. Stage 1D `SecurityMutation` serializes writes with the installation lock and records accepted/rejected service outcomes in `security_events`. A no-op records its accepted outcome without adding a revision.
- The dedicated directory displays only BOSS-derived results, never a union with other roles. Existing Stage 1D local authorization still grants independently scoped access at that location; it does not become an installation-wide exception. A selected location can remain a valid operating context even when its BOSS-derived business visibility is excluded.

## Verification

The following earlier results predate the current PA-07B.2 edits and are not verification of the current worktree: **485 backend tests / 4,809 assertions**, **242 frontend tests**, PHP Pint, build, and lint.

Current checkpoint:

- The guarded `scripts/compose-test` wrapper validated and used only the isolated `veltra-test-application` Docker context/project. Migration 000054 and an initial revision of 000055 ran there. The targeted API tests exposed an invalid PostgreSQL function in the initial 000055 trigger. The migration source was corrected, including null-value validation, but a rebuild of the test image repeatedly failed because Composer downloads from GitHub returned HTTP 429. Therefore the corrected migration and current backend tests have **not** been verified. No primary database, STAGING, or production target was used.
- Full frontend suite: **24 files, 242 tests passed**. Frontend build passed. Lint passed with the existing `visualPreview.tsx` fast-refresh warning; build reports the existing large-chunk warning.
- Current backend regression coverage now includes preservation of an independent RAW grant in the presence of BOSS restrictions and enforcement of report category/location exclusions. Those tests are pending a successful isolated image build.
- `git diff --check` passed at the last check. PHP Pint and full backend suite have not been run against the current worktree.
- Browser: no verified PA-07B preview. Live administrator/BOSS flows, desktop/mobile layout, keyboard focus, ARIA and reduced motion remain unverified.

## Minimum remaining architecture work

Define authoritative source attribution for RAW projections and revisions, including the primary catalog steward, linked supplier/offer and price histories, sharing, documents, and contributions to derived values. Preserve historical semantics; do not infer ownership from a restaurant sharing row. Classify data dependencies before implementing scoped projections. Run positive/negative authorization and inference tests against every implemented format, and verify the actual UI using a separate synthetic preview. Until a category is wired to a data surface, the UI must identify it as a saved restriction without implying that it currently changes data visibility.

## Changed files and commits

- Backend: authorization scope, BOSS visibility contracts and restrictions, `ReportAuthorization`, `BossLocationController`, additive migrations, API routes, Polish message, and BOSS/RAW/report authorization tests. The route-level RAW scope middleware was removed to preserve independent RAW grants.
- Frontend: PA user details and role summary, new `BossLocationRestrictions` component and updated location/category regression test.
- Documentation: this report only. No PA-07B commits created before full verification and acceptance readiness.
