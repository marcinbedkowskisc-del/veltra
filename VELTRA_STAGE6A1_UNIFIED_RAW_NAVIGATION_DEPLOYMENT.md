# VELTRA Stage 6A.1 — Unified RAW navigation deployment

**Status: DEPLOYED** — local Stage 6A.1 frontend updated on 2026-11-24.

## Release

- Source: `release/stage6a1-raw-candidate`, based on deployed release `795356faae84bd2185d615dc3764c97811d07184`.
- Navigation commit: `22e3320cc4abafbd5c9db3625299f9f19274ed5d` (`feat(raw): unify BOSS catalog under RAW`).
- Frontend image: `veltra-stage6a1-frontend:rc-22e3320-20261124-unified-raw`.
- Image ID: `sha256:412fc5bf6b426dc841b249f125dc3a825055355fcf8f8f30a8e73984ba751d40`.
- Image revision label and packaged navigation sources verified against the commit; allergen dictionary SHA-256 verified.

## Deployment and smoke tests

- Docker context `default`; Compose project `veltra-stage6a1`.
- Only the frontend service was recreated. It is healthy on `127.0.0.1:5174`.
- Backend remained healthy; queue remained running; PostgreSQL and Redis remained healthy with their original containers and images. No migrations were run.
- Frontend root and legacy `/boss/raw-materials` route return HTTP 200. Browser smoke test showed one RAW navigation entry and the login form. API health returned HTTP 200 directly and through the frontend proxy. Unauthenticated BOSS catalog API request returned HTTP 401.
- Compose initially used the default port 5173 and could not start because that port belongs to another project. Re-running with the Stage environment configuration used the existing Stage port 5174; no dependencies were recreated.

## Verification and limitations

- Frontend tests: **32 files, 324 tests passed**.
- Lint: passed; existing warning in `frontend/src/visualPreview.tsx`.
- Frontend build: passed; existing bundle-size warning remains.
- `git diff --check`: passed.
- No authenticated BOSS/admin role matrix was available for post-deployment testing. The UI access check and unauthenticated denial were verified; backend authorization code was not changed. Authenticated role, location-restriction, and price/cost visibility behavior requires manual verification.
- No Google Drive upload was performed because a Drive upload tool was not available.
- No push, merge, backend deployment, schema change, or migration.
