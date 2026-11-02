# VELTRA Stage 1F Polish Localization Remediation

Date: 2026-11-02

## Executive result

The localization remediation is implemented and verified in separate candidate images. Backend defaults and API responses are Polish, the existing React screens and shared API error handling are Polish, and the frontend does not display untrusted or non-Polish server messages.

Verification passed:

- Focused backend localization/security regressions: **58 tests, 525 assertions passed**.
- Complete backend regression suite: **145 tests, 1,103 assertions passed**.
- Frontend lint: **0 warnings, 0 errors**.
- Frontend production TypeScript/Vite build: **passed**.
- Candidate Laravel runtime locale and fallback: **pl / pl**.
- Candidate HTTP probes: localized 401 and 404 responses with `Content-Language: pl`.

**Deployment status is separate:** no existing application container was modified or restarted. The currently deployed backend still reports `en|en|en` for configured locale, fallback locale, and active locale. The code remediation is ready in the candidate image, but the live application will not become Polish until a separately authorized deployment.

## Changes

### Backend locale, catalogs, and API responses

- Set `locale` and `fallback_locale` to `pl` in `backend/config/app.php`; set the example fallback in `backend/.env.example`.
- Added `APP_LOCALE: pl` and `APP_FALLBACK_LOCALE: pl` to the shared backend environment in `compose.yaml`. This is a source configuration change; Compose services were not reconciled.
- Added Polish Laravel translations for authentication, passwords, pagination, validation, generic framework messages, and application messages under `backend/lang/pl/`.
- Added `PolishApiResponses` and API exception handling for localized authentication, authorization, validation, missing-resource, expired-session/CSRF, and rate-limit responses. Existing HTTP status codes and response error structures are preserved.
- Translated exposed controller/domain messages and the personnel deactivation reason without changing authentication/authorization decisions, stable API identifiers, permissions, or database structures.
- Added regression assertions for validation, generic authentication failures, authorization, framework 404, CSRF 419, and rate limiting.

### Frontend

- Updated `frontend/src/api.ts` to use a server-provided error message only when the response explicitly has `Content-Language: pl` and is below HTTP 500. Other responses use safe Polish status text; network, timeout, malformed-response, and server failures also use Polish text.
- Updated the health and authentication UI error handling in `frontend/src/App.tsx` and `frontend/src/AuthPanel.tsx`.
- Existing visible account-link and authentication/location screen text is Polish. No untranslated user-facing English strings were identified in the scanned current screens or normal API error paths. English protocol identifiers, enum values, internal diagnostics, and implementation terminology remain intentionally unchanged.

## Before and after examples

| Case | Before | Candidate after |
|---|---|---|
| Default/fallback locale | `en` / `en` | `pl` / `pl` |
| Unauthenticated API request | `Unauthenticated.` | `Sesja wygasła lub użytkownik nie jest zalogowany.` |
| Authorization failure | `This action is unauthorized.` | `Nie masz uprawnień do wykonania tej czynności.` |
| Validation summary | Framework-generated English suffixes could appear | `Podane dane są nieprawidłowe.` with translated field errors |
| Unknown API route | Framework-generated English not-found text | `Nie znaleziono żądanego zasobu.` |
| CSRF expiry / mismatch | `Page Expired` or `CSRF token mismatch.` | `Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.` |
| Frontend response handling | Arbitrary server `message` could be displayed | Only explicitly Polish messages below 500 are trusted; otherwise a Polish status fallback is used |

## Isolation and safety

Tests ran only against the disposable stack:

- Private Docker network: `veltra-stage1f-loc-20261102-internal` (`Internal=true`).
- Dedicated PostgreSQL container and volume: `veltra-stage1f-loc-postgres` / `veltra_stage1f_loc_20261102_pgdata`.
- Disposable database/user: `veltra_testing` / `stage1f_localization`.
- Dedicated Redis container: `veltra-stage1f-loc-redis`.
- No host-published ports; the test network contained only the disposable PostgreSQL, Redis, and candidate API containers.

The test database had the required migrations applied during disposable-environment setup. Test cases that truncate or otherwise alter rows therefore could not reach the application database. No migration, schema change, or data change was performed against the running application database. Existing application containers and volumes were not recreated, removed, or modified.

## Candidate image identities

- Backend: `veltra-backend:stage1f-polish-localization` — `sha256:2760d0bdba3348383b34e5dd86e7ef5f95d617dac7e2dfe1bcad4455bb4ca884`
- Frontend: `veltra-frontend:stage1f-polish-localization` — `sha256:46ecae0b7973800439e042ab81ec95ae65f4c290a26f21c5d7a60003e37e0ada`

These are local candidate images; neither was deployed to the existing application services.

## Verification commands and results

### Backend focused tests

Executed inside the backend candidate container with `APP_ENV=testing`, `APP_LOCALE=pl`, `APP_FALLBACK_LOCALE=pl`, `DB_CONNECTION=pgsql`, `DB_DATABASE=veltra_testing`, `DB_USERNAME=stage1f_localization`, and Redis directed to the disposable test service. The disposable DB password is intentionally redacted.

```sh
php artisan test --do-not-cache-result \
  tests/Feature/PolishLocalizationTest.php \
  tests/Feature/IdentityAuthenticationTest.php \
  tests/Feature/AccountSecurityTest.php \
  tests/Feature/InstallationApiTest.php \
  tests/Feature/ModuleConfigurationTest.php
```

Result: **58 passed (525 assertions)**.

### Complete backend regression suite

Executed in the same isolated candidate/test environment:

```sh
php artisan test --do-not-cache-result
```

Result: **145 passed (1,103 assertions)**, including existing authentication, RBAC, installation, module configuration, integrity, and concurrency tests.

### Frontend checks

Executed in the candidate frontend image:

```sh
npm run lint
npm run build
```

Results: lint found **0 warnings and 0 errors**; TypeScript and Vite production build passed.

### Candidate runtime checks

Laravel bootstrap inside the candidate container reported:

```text
pl|pl|pl
```

The first-party-free `GET /api/v1/auth/me` probe returned HTTP 401 with `Content-Language: pl` and the translated generic session message. `GET /api/v1/nonexistent-route` returned HTTP 404 with `Content-Language: pl` and the translated not-found message. Both probes were made on the private test network; no application database write was issued.

### Existing deployed runtime check

Read-only Laravel bootstrap in `veltra-backend-1` reported:

```text
en|en|en
```

This confirms the existing Stage 1E backend has not received the candidate localization changes. It remains running and unchanged; this task did not deploy or restart it.

## Remaining findings and recommendation

- **Confirmed operational limitation:** the currently deployed backend remains English (`en|en|en`) until the candidate is deployed. The frontend and backend localization changes are present in source and verified candidate images, but are not active in the existing application environment.
- No other untranslated user-facing strings were identified in the current frontend screens or inspected API/framework response paths. Internal error diagnostics, stable API identifiers, permission keys, and enum values remain English by design and are not displayed directly by the frontend.
- No authentication/authorization semantics, database schema/data, module readiness behavior, or business-module code was changed.
- No commit or push was created.

**Verdict:** localization remediation is **PASS in source and candidate images**. **Live-environment localization is pending** a separate deployment authorization; this report does not claim the running application has been localized.
