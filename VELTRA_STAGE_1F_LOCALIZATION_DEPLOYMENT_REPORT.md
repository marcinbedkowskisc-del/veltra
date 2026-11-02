# VELTRA 2.0 — Stage 1F Polish Localization Deployment Report

Deployment date: 2026-11-02  
Environment: local development (`COMPOSE_PROJECT_NAME=veltra`, Docker context `default`)  
Final verdict: **PASS**

## Summary

The verified Stage 1F localization candidates were deployed to the existing local development environment. The backend, queue, and frontend alone were recreated. PostgreSQL, Redis, Mailpit, their container identities, and both persistent data volumes were preserved.

The running Laravel backend now reports locale/default/fallback `pl|pl|pl`. Host-published backend, frontend, and Mailpit endpoints return HTTP 200. Actual API probes returned Polish 401, 404, and validation 422 responses with `Content-Language: pl`.

No migration or database-changing command was run. Migration 000010 was already `Ran` before deployment and remains `Ran`. No commit or push was created.

## Pre-deployment verification

### Git and Compose

- Revision: `d280ca5d2d460095767d06f1522f84f7a4a0d4e6`.
- Initial worktree: clean; branch `main`, three commits ahead of `origin/main`.
- Six application services were running before deployment.
- Effective pre-deployment Compose images:
  - Backend and queue: `veltra-backend:stage1e-candidate-db767dd`.
  - Frontend: implicit local tag `veltra-frontend` from the frontend build context.
- Compose already set backend `APP_LOCALE=pl` and `APP_FALLBACK_LOCALE=pl`; the Stage 1E runtime nevertheless reported `en|en|en`.
- Existing Compose already referenced only the `postgres-data` and `redis-data` named volumes; no volume change was needed.

### Database and migration identity

Verified through a read-only query from the running backend:

```text
config host=postgres
database=veltra
user=veltra
server=172.18.0.6/32
PostgreSQL=17.11
```

PostgreSQL container `veltra-postgres-1` mounted `veltra_postgres-data` at `/var/lib/postgresql/data`. Redis container `veltra-redis-1` mounted `veltra_redis-data` at `/data`.

Before deployment, `php artisan migrate:status --no-ansi` showed migrations 000001–000010 as `Ran`; migration 000010 was batch 4. No migration was pending.

### Approved candidate image digests

Both local candidate image identities matched the Stage 1F remediation report exactly before deployment and were rechecked afterward:

| Service | Image | Verified image ID |
|---|---|---|
| Backend and queue | `veltra-backend:stage1f-polish-localization` | `sha256:2760d0bdba3348383b34e5dd86e7ef5f95d617dac7e2dfe1bcad4455bb4ca884` |
| Frontend | `veltra-frontend:stage1f-polish-localization` | `sha256:46ecae0b7973800439e042ab81ec95ae65f4c290a26f21c5d7a60003e37e0ada` |

Reference: [Stage 1F localization remediation report](./VELTRA_STAGE_1F_LOCALIZATION_REMEDIATION.md).

## Compose configuration change

Only two image selections changed in `compose.yaml`:

```diff
-  image: veltra-backend:stage1e-candidate-db767dd
+  image: veltra-backend:stage1f-polish-localization
...
  frontend:
+    image: veltra-frontend:stage1f-polish-localization
```

Backend and queue inherit the shared backend image. Their existing `APP_LOCALE=pl` and `APP_FALLBACK_LOCALE=pl` settings remain unchanged. The frontend retains its build context and now also has an explicit local candidate image tag.

Effective Compose assertions passed:

```text
backend   veltra-backend:stage1f-polish-localization
queue     veltra-backend:stage1f-polish-localization
frontend  veltra-frontend:stage1f-polish-localization
locales   pl pl
```

`docker compose config --images` lists those Stage 1F tags and no Stage 1E or Stage 1A backend tag. A routine `docker compose --dry-run up -d` reported all six services as running; it proposed no rebuild or recreation. The image references are persisted in Compose, so routine operations do not fall back to Stage 1E or Stage 1A.

## Controlled deployment

The dry run of the limited operation proposed only backend, queue, and frontend recreation. The actual deployment command was:

```sh
docker compose up -d --no-deps --force-recreate --no-build backend queue frontend
```

`--no-deps` prevented dependency recreation, and `--no-build` ensured Compose used the already-verified image IDs. No migration command was executed.

### Container identities before and after

| Service | Before ID | After ID | After image ID | Final state |
|---|---|---|---|---|
| Backend | `c6d2d8fe1ada675eb63414afd8d13c764f0f3a3729a4e3f0c6f32171c46d18ce` | `fab99eb93efaaffabee65c4653780e1d7dee6983823acf99dce22913834f35e3` | `sha256:2760d0bdba3348383b34e5dd86e7ef5f95d617dac7e2dfe1bcad4455bb4ca884` | Running, healthy |
| Queue | `3aba01b3719d9fd7d2ef1f42a2af491d0abbbec6662ec73c3a626dbf64e9983e` | `25d74a81cb5bae31e391c5f1b425de5f13b43d647176f85923e5a15c62ae58a6` | Same backend image ID | Running |
| Frontend | `855cbacd5a7378f261aedbea3dbe247e9e0e9ddf08119d9edf435f3ccb31b2f8` | `9f1af421474f2c0c1b9d00e58937786fb000139a40b1acd34ededfbc26e9fb72` | `sha256:46ecae0b7973800439e042ab81ec95ae65f4c290a26f21c5d7a60003e37e0ada` | Running, healthy |
| PostgreSQL | `a1fed478ec4dfaf500f0fb4fe97117f2156f98ae92c5509a524770a0dc94134c` | Same | `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b` | Running, healthy |
| Redis | `581fcf39a65df8dc36ac73e320e4753ca7641b086325f8cc5cbba62839a56abe` | Same | `sha256:8da19c7399dca900c6822a1cf30f9d2285585e2c4b457ff4095b0f4500054d3f` | Running, healthy |
| Mailpit | `ef38802707258749910323e8c9a7d42b01fd5c84e7c940a4cf4301b4979a949b` | Same | Pinned digest `sha256:9b3a463be15354c0f0eba91697d80e3e0d8a5623ef0e627cacc836bc17fd5231` | Running, healthy |

The existing named volumes `veltra_postgres-data` and `veltra_redis-data` remain mounted to their original containers. Neither dependency container nor volume was recreated, removed, or modified.

## Post-deployment verification

### Runtime locale

The read-only Laravel bootstrap check:

```sh
docker exec veltra-backend-1 php -r \
  'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("app.locale"), "|", config("app.fallback_locale"), "|", app()->getLocale(), PHP_EOL;'
```

Before deployment: `en|en|en`  
After deployment: **`pl|pl|pl`**

### Host-published endpoints and service health

| Check | Result |
|---|---|
| `http://127.0.0.1:8000/api/health` | HTTP 200; application `ok`, PostgreSQL `up`, Redis `up` |
| `http://127.0.0.1:5173/` | HTTP 200; frontend container healthy |
| `http://127.0.0.1:8025/` | HTTP 200; Mailpit healthy |
| PostgreSQL `pg_isready -U veltra -d veltra` | Accepting connections |
| Redis `redis-cli ping` | `PONG` |
| Queue worker | Running |

### Actual Polish API responses

Read-only unauthenticated probes:

```sh
curl -i -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/v1/auth/me

curl -i -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/v1/not-a-real-route
```

Results:

- HTTP 401, `Content-Language: pl`, `Sesja wygasła lub użytkownik nie jest zalogowany.`
- HTTP 404, `Content-Language: pl`, `Nie znaleziono żądanego zasobu.`

For the validation check, one CSRF-valid empty login request was submitted with a first-party session:

```text
GET /sanctum/csrf-cookie                 -> 204
POST /api/v1/auth/login with body {}    -> 422
Content-Language                        -> pl
message                                 -> Podane dane są nieprawidłowe.
errors.email                            -> Pole adres e-mail jest wymagane.
errors.password                         -> Pole hasło jest wymagane.
```

The validation request was rejected before controller authentication logic and did not modify business data or database schema. As expected for a first-party request, it created transient session/rate-limit state in Redis; no Redis container or volume was recreated.

### Migration status after deployment

`php artisan migrate:status --no-ansi` still reports migration 000010 as:

```text
2026_11_02_000010_create_location_module_configuration ........ [4] Ran
```

No migration was run during this deployment.

## Final disposition

- **PASS:** verified backend and frontend candidate digests are active.
- **PASS:** runtime locale/default/fallback is `pl|pl|pl`.
- **PASS:** Polish 401, 404, and validation 422 responses include `Content-Language: pl`.
- **PASS:** all six services are operational; PostgreSQL, Redis, Mailpit, and both data volumes retain their original identities.
- **PASS:** migration repository and database identity are unchanged; migration 000010 remains `Ran`.
- **PASS:** Compose now persistently selects Stage 1F images for backend, queue, and frontend; routine Compose configuration does not select Stage 1E or Stage 1A.
- Only `compose.yaml` was changed for deployment configuration. No commit or push was created.

**Final verdict: PASS — Stage 1F Polish localization is deployed and verified in local development.**
