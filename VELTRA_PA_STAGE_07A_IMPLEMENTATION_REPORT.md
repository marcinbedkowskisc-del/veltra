# VELTRA 2.0 — PA-07A Completion Audit and Backend Verification

**Date:** 2026-11-19
**Functional acceptance:** Accepted by the owner on 2026-11-19.
**Visual/UX acceptance:** Deferred. The current interface is not visually approved.
**Release status:** Not deployed.
**Scope:** PA-07A only. PA-07B and PA-08 were not started.

## Executive summary

The seven original backend failures were caused by unavailable ClamAV in the isolated test environment. After starting the approved isolated PostgreSQL/Redis/ClamAV services, the scanner returned `PONG`; the final full backend suite passed: **484 tests, 4,769 assertions**. The increase from the earlier 483-test run includes a regression test for a membership-revocation defect found during browser testing.

The local API mismatch was caused by the Vite preview proxying to an older backend. The preview was pointed to the current workspace backend; the required `effective_permissions` validation remains strict. The actual user-details response returned HTTP 200 with four effective-permission contexts, and the details panel rendered in the browser.

The PA-07A interface uses existing Stage 1D routes and authorization services. The audit added effective-permission visibility through the existing authorizer, surfaced the existing module-delegation choices, and uses a focus-managed alert dialog for sensitive confirmations. Browser testing found and fixed a database-trigger actor-context defect in membership removal. No Stage 1D authorization rule, BOSS restriction, or BOSS entitlement was changed.

The remaining scope boundary is RAW organization-level permission delegation. Its separate backend contract has no per-user grant-list endpoint; the existing RAW authority is unchanged and no administrator override was added.

The earlier [PA-07 report](./VELTRA_PA_STAGE_07_IMPLEMENTATION_REPORT.md) records the original blocked assessment. The [Stage 1D RBAC amendment v1.1](./VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md) remains approved policy; implementing its BOSS visibility and restriction changes was outside PA-07A.

## Full backend failure investigation

The original seven failures were:

- Six cases in `RawMaterialCompatibilityAssessmentTest`.
- One case in `RawMaterialDocumentStorageTest`.

All reported `CLAMAV_UNAVAILABLE`. The first full run did not have the ClamAV service running. A separate initial focused attempt also ran before test migrations and failed because `public.organizations` was absent; this was a setup error, not one of the seven failures.

Verification used the approved isolated wrapper and test project only:

- Started `postgres`, `redis`, and `clamav` with `scripts/compose-test`.
- Verified the scanner with `clamdscan --ping=60:2` → `PONG` (the first local-socket probes failed while the daemon initialized).
- Re-ran the two affected test classes: **18 passed, 151 assertions**.
- Re-ran the full suite after the PA-07A regression fix with Composer's child-process timeout disabled: **484 passed, 4,769 assertions**.

Some RAW tests intentionally simulate unavailable scanners and log `CLAMAV_UNAVAILABLE`; those cases passed and verify fail-closed behavior. No failures remained. No PA-07A regression or unrelated defect was found among the original seven.

## Local preview API alignment and membership-revocation fix

The host-run Vite preview used its default proxy target, an older backend instance that omitted `effective_permissions`. The response parser was not relaxed and no permission data was fabricated. After the preview was aligned to the current workspace backend using synthetic data, `GET /api/v1/administration/users/{user}` returned HTTP 200 with the required `effective_permissions` array (four active operating-context entries), assignments, requests, and audit history.

During real browser testing, removing a location membership while a Stage 1D grant was active returned HTTP 500. PostgreSQL reported SQLSTATE `42501`: the `revoke_membership_authorization()` trigger updated the grant without `revoked_by`, and `guard_authorization_actors()` rejected the actor against the signed mutation ticket. Migration `2026_11_19_000053_preserve_actor_when_revoking_membership_permissions.php` now writes the actor from the existing signed authorization context when the trigger revokes those grants. It does not relax the ticket or change authorization rules. The regression test verifies the grant's `revoked_at`/`revoked_by` and the membership's revoked state; it passed (**1 test, 4 assertions**) and is included in the final full backend run.

## PA-07A implementation matrix

`IMPLEMENTED AND TESTED` means the behavior has supporting automated coverage and was verified in the browser where applicable. `IMPLEMENTED BUT NOT FULLY VERIFIED` means part of the end-to-end lifecycle or UI coverage remains incomplete. `BLOCKED BY EXISTING BACKEND CONTRACT` means the existing contract does not safely support the requested PA surface.

| # | Capability / status | Frontend component | Backend contract | Supporting tests / verification |
|---|---|---|---|---|
| 1 | Administrator-only user directory — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `GET /api/v1/administration/users`, `GET /api/v1/administration/locations`; `AdministrationUserController::index/locations`, `AdministrationUserService::list` | `AdministrationUsersTest` checks directory behavior and non-admin denial; live isolated preview loaded the directory |
| 2 | Search, filtering, sorting, pagination — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx`, `usersApi.ts` | Directory endpoint; query supports `search`, `role`, `location_id`, `status`, `sort`, `direction`, `page`, `per_page` | Browser verified search, role/location/status filters, sorting; `AdministrationUsersPage.test.tsx` verifies page 2 with 26 synthetic records; backend directory tests cover filters |
| 3 | User details — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `GET /api/v1/administration/users/{user}`; roles, memberships, permission grants, requests, history, effective permissions | `AdministrationUsersTest::test_user_details_include_assignments_requests_and_safe_audit_history`; browser opened/closed details; current API returned HTTP 200 and four `effective_permissions` contexts |
| 4 | Profile editing — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `PATCH /api/v1/administration/users/{user}/profile`; `AdministrationUserService::updateProfile` | Backend profile/audit test and focused UI form test; browser edited a synthetic profile |
| 5 | User account creation — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `POST /api/v1/security/locations/{location}/personnel`; `SecurityController::personnel`, `PersonnelAdministration` | `SecurityApiTest`; focused UI creation test; browser created an isolated synthetic account |
| 6 | Email verification and activation — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx`, `AccountLinkForm.tsx` | `POST /api/v1/security/users/{user}/links` (`activation`); `POST /api/v1/account/activate` | `SecurityApiTest`, `AccountSecurityTest`; browser exercised activation-link flow and verified the synthetic account displays “Adres potwierdzony” |
| 7 | Password-reset initiation — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx`, `AccountLinkForm.tsx` | `POST /api/v1/security/users/{user}/links` (`reset`); `POST /api/v1/account/reset-password` | `SecurityApiTest::test_email_initiation_has_uniform_response_and_no_credentials`, `AccountSecurityTest`; focused UI test and browser exercised reset-link initiation |
| 8 | Account activation/deactivation — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `PATCH /api/v1/security/users/{user}/status`; `SecurityController::accountStatus` | `AdministratorAuthorizationTest`, `SecurityApiTest`, focused UI test; browser confirmed both status mutations returned HTTP 200 and final state is active |
| 9 | Assignment/removal of existing roles — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `POST /api/v1/security/users/{user}/roles`; `DELETE /api/v1/security/roles/{assignment}`; `RoleAdministration` | `RoleAdministrationTest`, `SecurityApiTest`, `AdministratorAuthorizationTest`, focused UI test; browser revoked and reassigned a synthetic user's role |
| 10 | Assignment/removal of location memberships — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` | `POST /api/v1/security/users/{user}/locations/{location}/membership`; `DELETE /api/v1/security/memberships/{membership}`; `PersonnelAdministration` | `IdentityMembershipTest`, `PersonnelAdministrationTest`, `SecurityApiTest`, new trigger regression test; browser removed and reassigned a membership after the fix |
| 11 | Additional permission management — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` “Uprawnienia” disclosure | `POST/DELETE /api/v1/security/users/{user}/locations/{location}/permissions`; `PermissionAdministration` | `PermissionDelegationTest`; browser revoked and re-granted `FOODCOST_VIEW` using the protected UI; current grant is visible and effective. UI supports immediate grants/revocations, not date-range editing |
| 12 | Existing delegation management — **IMPLEMENTED AND TESTED** for Stage 1D module grants; **BLOCKED BY EXISTING BACKEND CONTRACT** for RAW organization grants | Stage 1D: `AdministrationUsersPage.tsx` “Uprawnienia” disclosure. RAW: no PA-07A component | Stage 1D: protected permission grant/revoke endpoints; `PermissionAdministration` enforces employee-role and module-readiness rules. RAW: mutation endpoints require `RAW_MATERIALS_PERMISSION_DELEGATE`; no per-user grant-list endpoint exists | Stage 1D: `PermissionDelegationTest`, frontend effective-context tests, and browser grant/revoke. RAW: existing authority tests, but no PA listing API |
| 13 | Access-request approvals and rejections — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` “Prośby o dostęp” disclosure | `GET /api/v1/security/locations/{location}/membership-requests`; `POST /api/v1/security/membership-requests/{request}/decision`; `PersonnelAdministration::decide` | `PersonnelAdministrationTest` and `SecurityApiTest`; focused UI decision test; browser approved one synthetic request and rejected another |
| 14 | Protected administrator operations — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` and admin routes | `/api/v1/administration/*` protected by `RequireAdministrator`; security mutations retain their own `SecurityRequest` and service-level authorization | `AdministrationUsersTest::test_non_administrators_cannot_access_administration_user_endpoints`, `SecurityApiTest`, `AdministratorAuthorizationTest`; full backend suite |
| 15 | Audit history — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` “Historia zmian” disclosure | `GET /api/v1/administration/users/{user}` returns target-scoped history capped at 100 events; mutations use `SecurityMutation` | `AdministrationUsersTest` checks safe history projection and profile audit; browser read role, location, permission, access-request, and account-status events |
| 16 | Effective permission visibility — **IMPLEMENTED AND TESTED** | `AdministrationUsersPage.tsx` “Efektywne uprawnienia” disclosure; `usersApi.ts` parser | Same details endpoint; computed by existing `BusinessAuthorization::allows` for active operating contexts and locations | Backend projection test and frontend effective-context test; actual browser response/UI displayed four effective-permission contexts |
| 17 | Desktop/mobile layout behavior — **IMPLEMENTED AND TESTED**; usability approval deferred | `AdministrationUsersPage.tsx`, `App.css` | Directory and details endpoints | Browser at 1440×1000 and 390×844: no document-level horizontal overflow; mobile table (928px) scrolls inside its 299px container; details, permission descriptions, and audit history rendered at mobile width |
| 18 | Keyboard/accessibility behavior — **IMPLEMENTED AND TESTED** for critical confirmation flow | `AdministrationUsersPage.tsx`, shared `Drawer.tsx` | N/A | Frontend test and browser verified initial confirmation focus, Shift+Tab to Cancel, Escape close, and focus restoration. No full assistive-technology audit was run |

### Boundary for RAW delegation management

The PA user-details contract currently has no projection for `organization_permission_grants`, and the RAW APIs expose mutations but no per-user list. RAW grants are organization-scoped and follow a separate permission lineage; the existing mutation service requires the caller's effective delegation authority. This is not a missing Stage 1D module-delegation control: those are managed and tested in item 12. Do not add RAW grant visibility or administrator mutation rights without an approved scope and contract; continue to use the current RAW authorization service unchanged.

## Verification results

The backend, PHP Pint, focused frontend, and browser results below are from the isolated completion audit. During finalization, the full frontend suite, TypeScript/Vite build, and frontend lint were rerun locally against the unchanged implementation. Backend and browser checks were not repeated.

- Focused backend administration tests: **5 passed, 41 assertions**.
- Membership-revocation regression test: **1 passed, 4 assertions**.
- Focused frontend administration tests: **7 passed**.
- Full backend suite, isolated PostgreSQL/Redis/ClamAV: **484 passed, 4,769 assertions**.
- Full frontend suite: **23 files, 241 tests passed**.
- TypeScript/Vite production build: passed; existing bundle-size advisory (`516.95 kB` minified JS) remains.
- Frontend lint: passed with the existing `react(only-export-components)` warning in `src/visualPreview.tsx`.
- PHP Pint: **359 files passed**.
- Isolated browser preview: `/administration/users`. Details response returned HTTP 200 with `effective_permissions`; browser exercised search, filters, sorting, creation, profile editing, account links/status, role/membership/permission changes, access decisions, effective-permission display, and audit history. Account deactivation and reactivation both returned HTTP 200; the synthetic account ended active.
- Desktop/mobile: inspected at 1440×1000 and 390×844. Document width matched client width at both sizes. On mobile, the 928px table scrolls within a 299px container; effective permissions and audit history remained accessible in the details panel.
- Keyboard confirmation: browser verified initial focus on Confirm, Shift+Tab to Cancel, and Escape dismissal. Pagination's second page was verified with 26 synthetic rows in the UI test; the preview database itself contains only two directory users.
- `git diff --check` and `git diff --cached --check`: passed for finalization, including the newly staged files.

## Outstanding UI/UX redesign requirements

The owner accepted the functionality, not the current interface. A separate, comprehensive UI/UX redesign must:

- Improve the usability of the user directory, user details, and roles and permissions workflows on desktop and mobile.
- Establish a clear information hierarchy that separates routine tasks, assignment and access information, audit history, and sensitive actions.
- Consolidate repeated permission descriptions. Make it clear what is assigned versus what is effective in a given operating context.
- Replace technical terminology in the interface with plain, consistent language without losing the distinctions between roles, location memberships, delegated permissions, and effective access.

The redesign must preserve the existing authorization rules and protective confirmations and receive separate visual/UX approval. The functional browser and accessibility checks above do not constitute that approval. No redesign was started in finalization.

## Authorization and release notes

- Effective-permission visibility is a read projection through the existing Stage 1D authorizer; it does not add policy.
- Existing administrator gates and mutation-service authorization remain authoritative.
- No expanded BOSS rights, per-user BOSS restrictions, or BOSS behavior change was implemented.
- No STAGING or production environment was accessed or modified.
- Verified implementation commit: `c1d843c`. Finalization documentation is recorded in a separate local commit.
- No push or deployment was performed. PA-07B and PA-08 were not started.
