# VELTRA 2.0 — PA-07 Implementation Report

**Date:** 2026-11-19\
**Status at time of assessment:** **BLOCKED — no PA-07 code implementation or owner acceptance.**\
**Repository:** `/home/veltra/projekty/veltra`

This is the historical PA-07 assessment before PA-07A. See the [PA-07A implementation report](./VELTRA_PA_STAGE_07A_IMPLEMENTATION_REPORT.md) for subsequent verification and functional acceptance of that subset only.

## Summary

The four owner-provided source documents were retrieved and reviewed:

- [Stage 1D approved RBAC v1](https://docs.google.com/document/d/1Hbt31CoeEeJV9nmwOtkEXVmAFRsKK3VQabCtCuzvN6E/edit), via its public text export.
- [Stage 1E module architecture v1](https://docs.google.com/document/d/1lmGoiMqGptvi3rcn8umqeyYkrir9qW5V7-B4w4EyzB0/edit), via its public text export.
- [PA Functional UX Specification v1](https://drive.google.com/file/d/1uX7tTwoknjoPowCs5-TmbozcTKzPkSgo/view), via its Drive download.
- [VELTRA UI Component Standards v0.2](https://docs.google.com/document/d/1cKek1pqEmqN8EWe1ucbMiYy_okFfzADzJ8d9ndyGFCE/edit), via its public text export.

Existing code and Stage 1D/1E/PA-06 reports were also inspected. The requested BOSS policy was recorded in [`VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md`](./VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md). No source code, database schema, or access behavior was changed. The working tree was clean before these two documentation files were added.

## Current implementation findings

- The six approved roles and existing Stage 1D administration services are present. BOSS authorization in `BusinessAuthorization` currently grants only restaurant `LOCATION_VIEW`, `REPORTS_VIEW`, and `REPORTS_EXPORT`; it does not grant central-kitchen source access.
- `ReportAuthorization` is a report-sharing authorization contract. The repository has no implemented business-report data projection or report export API to filter.
- PA navigation contains the `Użytkownicy` and `Role i uprawnienia` entries, but the PA content is not implemented. Existing security APIs list users per location; they are not the required organization-wide PA directory with role/location/status filters, sorting, and pagination.
- There is no PA-facing BOSS restriction persistence or management API, nor a query-layer consumer for such restrictions.
- Stage 1E production definitions are `NOT_READY`; the configured module availability check fails closed for them. The RAW catalog is also not production-ready under the defined production catalog.
- RAW has a separate authorization system in `RawMaterialAuthorization`. Its permission grants are organization-wide and have no location identifier. The source comment in `SystemProvisioningRequestValidator` explicitly states that selecting a location does not make these grants location-scoped.
- RAW data is stored in an organization-level catalog. Restaurant visibility is based on existing sharing rules; it is not a general per-location data model. This does not currently provide the location-level query/projection boundary required for BOSS restrictions across raw records, financial data, and derived outputs.
- RAW supplier display names are already projected independently of financial permissions; this behavior must be preserved.

## Blocked surfaces and reasons

1. **BOSS read authorization for current RAW APIs:** granting BOSS general RAW reads while preserving restricted financial data requires separate category-aware projections for costs, reference prices, exchange rates, history, and derived values. The existing RAW grant model cannot express the requested per-location scope. A role-only allowance would risk exposing restricted financial data or bypassing location restrictions.
2. **Per-location BOSS restrictions for RAW:** the organization-level catalog and share-based restaurant visibility do not provide a complete location attribution boundary for all rows, related data, and derived outputs. In particular, allowing BOSS to read the primary catalog regardless of restaurant shares does not make the catalog's data separable by every restaurant restriction.
3. **Reports, exports, analytics, comparisons, and print:** no business report or export query surfaces exist to apply restriction filters to. Implementing only an authorization check would not prevent inference from report projections or aggregates.
4. **PA-07 users and roles screens:** the UI is absent and the existing per-location APIs do not expose the required organization-wide list, assignment summaries, full details, or readable audit history as a coherent PA contract.
5. **Mixed-role resolution on RAW:** ordinary `BusinessAuthorization` and RAW's organization-wide grant engine have different scope semantics. Existing local role permissions can be evaluated independently by location, but the RAW permission grants cannot be identified as location-scoped independent exceptions. They cannot safely override an individual BOSS restriction.

No BOSS business-read expansion, restriction UI that suggests enforcement, module readiness change, or partial financial projection was made. This follows the PA-07 requirement to withhold unsafe results rather than grant partial access.

## Reused infrastructure and changes

- **Reused/inspected:** existing Stage 1D role and permission enums, `BusinessAuthorization`, `RoleAdministration`, `PersonnelAdministration`, `PermissionAdministration`, `SecurityMutation`, account-link flows, `ReportAuthorization`, RAW authorization, Stage 1E module availability, existing PA shell/navigation and shared components.
- **Authorization, database, and API changes:** none.
- **Files changed:** `VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md`; `VELTRA_PA_STAGE_07_IMPLEMENTATION_REPORT.md`.
- **Commit hashes:** none.

## Verification

No tests, builds, lint, PHP Pint, Docker tests, or browser checks were run because no executable code changed. The requested desktop/mobile interaction verification remains outstanding.

## Next implementation prerequisites

- Add a secure BOSS restriction model and admin-only management API with durable audit.
- Establish location attribution and restriction-aware projections for the organization-level RAW catalog before granting BOSS access to RAW.
- Apply price-category restrictions to purchase costs, reference prices, relevant exchange rates, historical values, and all derived views while preserving unrelated names, supplier display names, units, and categories.
- Add report/export/aggregation filtering only when those data surfaces exist; do not activate Stage 1E modules to demonstrate access.
- Implement PA users/details/history and role-information views using existing authorization contracts; keep mutation controls on existing authorized backend flows.
- Add backend and frontend tests for the specified role combinations, category/location filtering, non-mutation, audit, data projections, and PA access control; then run the isolated PostgreSQL test suite, frontend tests/build/lint, and browser checks at desktop/mobile sizes.

PA-07 remains incomplete and requires further implementation and owner acceptance. No push, deployment, or PA-08 work was performed.
