# VELTRA — PA-07B.2 Isolated Preview and Remote Push Audit

**Recommendation: ready for owner acceptance within the implemented PA-07B.2 scope, with the RAW readiness limitation and outstanding usability observations below explicitly accepted.** This report does not claim that category exclusions filter business projections that do not use the visibility contract. No PA-07B.3, PA-08, redesign, push, deployment, or live-environment change was performed.

## Repository and remote push audit

- Branch: `main`.
- Local HEAD and `origin/main`: `09a8cd982f1496a3d6cb80843212a2770540dd70`.
- The local `origin/main` reflog records `update by push` at `2026-11-19 23:10:24 +0100`. It is local reflog evidence only; it does not identify the person who initiated or authorized the push.
- Public GitHub event history did not expose a matching push event. The tracked `.github` content contains no workflow, and the repository reported no GitHub Actions workflows or runs; no GitHub deployment was found. The Hooks API returned HTTP 401, so remote webhook configuration and audit metadata could not be verified. No executable local Git hook is configured.
- The repository contains `scripts/deploy_stage6a1.py`, a local Docker deployment utility for the separate Stage 6A.1 Compose project. It was inspected but not run; its presence is not evidence that it ran and does not establish a PA-07B.2 or remote deployment.
- Repository/commit author and committer fields do not establish push attribution.
- No deployment is evidenced by the available sources. External automation and deployment activity outside the inspected GitHub evidence cannot be ruled out. No push or deployment was performed by this work.

## Isolated preview environment

The actual VELTRA frontend and Laravel backend were run as the disposable Compose project `veltra-pa07b2-browser-preview`. The frontend was reachable only at `http://127.0.0.1:5197/`; the frontend, backend, PostgreSQL, Redis, and ClamAV containers were healthy during verification.

Isolation controls:

- PostgreSQL database `veltra_pa07b2_browser_preview` and Redis data use `tmpfs`. The database, Redis, ClamAV, and backend have no published host ports.
- The backend uses the dedicated Compose PostgreSQL service on internal networks, a non-superuser application role, and preview-only credentials. It does not use STAGING, production, the primary local database, or the accepted PA-07A preview.
- The preview is explicitly marked `APP_ENV=browser-preview` and `VELTRA_BROWSER_PREVIEW=1`. The guarded seeder verifies the database name, host, application role, table owner, and non-superuser/no-TRUNCATE conditions before creating synthetic fixtures.
- The only published port is the frontend on loopback. `preview` and `browser-api` are internal Docker networks; only the frontend is attached to `browser-ingress`, whose bridge has masquerading disabled. `browser-ingress` is not an internal network, so complete egress isolation is not established by this configuration.
- Existing local images were reused; no image pull or dependency download was needed. The preview-specific Dockerfile removes `.env` from the backend image. The local credential file `tests/compose/pa07b2-browser-preview.env` is ignored by Git and was not read into output or committed.
- Fixtures are synthetic: an administrator, a BOSS without an independent RAW grant, a BOSS with independent RAW grants, two central-kitchen locations, and two restaurants. No customer data was used.

The earlier [browser verification report](./VELTRA_PA_STAGE_07B2_BROWSER_VERIFICATION_REPORT.md) is preserved unchanged as the historical record of the initial preview blocker. This report records the later successful preview setup and browser evidence.

## Browser and API verification

All application interaction below used the actual running frontend and backend with synthetic accounts. This was verification only; no application authorization or business-permission behavior was changed.

| Scenario | Result and evidence |
|---|---|
| Administrator login, navigation, location selection, and user management | Passed in the browser. |
| Global category exclusion, R-1 location exclusion, and `FOODCOST`/R-1 pair | Saved by the administrator UI; PUT returned 200. Closing and reopening user details reloaded the policy. |
| Stale update | A competing update returned 200; the stale UI save returned 409 and displayed the conflict message. Reload succeeded. |
| Remove restrictions | UI removal returned 200. The final policy was empty at version 5. |
| Remove and regrant BOSS | BOSS role removal returned 200; restrictions remained stored and disabled while the role was absent. Regrant returned 201; restrictions were still present and editable. Test restrictions were then removed. |
| Self-escalation | Attempting to grant BOSS to the logged-in administrator's own account returned 403; the account remained Administrator-only. |
| BOSS administrator boundary | The BOSS browser view at `/administration/users` displayed “Brak dostępu” and omitted the administrator navigation link. Direct `GET /api/v1/administration/access` and the user directory endpoint returned 403. The independently RAW-granted BOSS also received 403 from the administrator access endpoint. |
| BOSS without independent RAW grant | `GET /api/v1/security/raw-materials/capabilities` returned 200 with all listed RAW permissions and actions false. `GET /api/v1/raw-materials/` returned 503, `RAW_MATERIALS_MODULE_UNAVAILABLE`, because the module was `NOT_READY`; this does not independently prove a route-level authorization denial. |
| BOSS with independent RAW grant | Capabilities returned 200 with the independent RAW permissions true, while available actions were false because readiness was `NOT_READY`. The RAW catalog still returned 503, `RAW_MATERIALS_MODULE_UNAVAILABLE`. No RAW business data was exposed or verified. |
| Desktop/mobile, Polish labels, keyboard and focus | The administrator UI was inspected at desktop and 375 CSS-pixel mobile width. Document/body width remained 375px with no page-level horizontal overflow. Eight Tab presses reached controls; focus was visible with a 2px solid outline and visible shadow. Polish labels were present. |
| Category restrictions on other business projections | Not verified and not claimed. The approved architecture defers the first RAW projection and further category-aware projections to PA-07B.3. |

### Browser console and observations

The browser console recorded expected HTTP 403 responses for denied administrator operations and HTTP 503 responses from the unavailable RAW module. Navigation and logout also canceled in-flight requests (`ERR_ABORTED`). These events are not reported as successful requests or as proof of a working RAW projection.

One separate synthetic permission attempt appeared in BOSS history with a 409 and “Użytkownik musi mieć aktywne przypisanie do lokalizacji.” No permission was granted; the exact initiating interaction could not be established.

### Screenshot evidence

Screenshots are actual captures of the running application attached to this session, not mockups. They are not repository files:

- Administrator desktop screens: login/home, user management, and saved restrictions.
- Administrator mobile viewport: 375 CSS pixels.
- BOSS RAW view: the captured catalog displays “Moduł niedostępny” and the `NOT_READY`-related message while logged in as the synthetic independently RAW-granted BOSS.

The BOSS administrator denial was observed in the live browser; its API outcomes are recorded above. The screenshot attachments do not establish access to any unavailable business projection.

## PA-07B.2 scope and remaining boundaries

- The restriction editor's save, reload, remove, stale-conflict, and role removal/regrant behavior was exercised against the live preview.
- The existing implementation report records the prior automated authorization and regression evidence. Those suites were not rerun for this environment-only verification.
- The live preview confirmed independent RAW permission capabilities remain separate from BOSS and from administrator access. The module readiness gate prevented testing successful RAW data access or a direct route-level 403/authorization denial for a BOSS without a RAW grant.
- The approved architecture and implementation report remain consistent: category/location restrictions apply only to surfaces that consume the visibility contract. The first approved BOSS RAW projection and additional category-aware business projections remain deferred; no change to readiness, Stage 1D, RBAC, RAW ownership, or delegation was made.
- No UI redesign was performed. Visual hierarchy, repeated permission descriptions, and technical terminology remain known usability work for the separate comprehensive UI/UX redesign.
- Owner acceptance is recommended for the implemented PA-07B.2 foundation, subject to the above disclosed RAW readiness and usability boundaries. This is not owner sign-off and does not authorize PA-07B.3.

## Files and final repository state

Preview-specific changes available for owner review:

- Modified `.gitignore` to ignore the local preview credential file.
- Added `compose.browser-preview.yaml`, `backend/Dockerfile.browser-preview`, `backend/database/seeders/BrowserPreviewSeeder.php`, and `frontend/vite.browser-preview.config.ts`.
- Added this report. The previously untracked browser verification report was preserved.

No business authorization code, RBAC rules, architecture specification, or production/STAGING configuration was changed. No commit was created.

At the time of browser verification, `git diff --check` passed, `HEAD` remained the expected commit, and the worktree contained only the preview changes and untracked reports listed above. During owner-acceptance checkpoint closure, the preview Compose services were stopped; the preview configuration and evidence remain in the worktree, but the URL is no longer serving the application.
