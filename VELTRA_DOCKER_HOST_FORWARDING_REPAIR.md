# VELTRA 2.0 — Docker Desktop Host Forwarding Repair

## Verdict: PASS

Localhost access to the VELTRA backend and frontend is restored from both WSL and Windows. Docker Desktop now forwards ports 8000 and 5173 to the recreated backend/frontend containers at their current IP addresses. PostgreSQL, Redis, Mailpit and queue were not recreated; their container identities and the database/cache volumes remain unchanged.

The backend continues to use the deployed `veltra-backend:stage1a` image. The Stage 1E candidate image was not deployed. Migration 000010 remains absent from the running image and unapplied in the application database.

## Before repair

Compose configuration published only the affected services to loopback:

- Backend: `127.0.0.1:8000 -> 8000/tcp`, image `veltra-backend:stage1a`.
- Frontend: `127.0.0.1:5173 -> 5173/tcp`, image `veltra-frontend`.
- Dependencies: PostgreSQL and Redis on `veltra_default`; named volumes `veltra_postgres-data` and `veltra_redis-data`.

The Compose dependency graph includes PostgreSQL/Redis for backend and backend for frontend. `docker compose config --images` confirmed the backend and queue images were both `veltra-backend:stage1a`, and frontend was `veltra-frontend`.

Before the repair:

| Port | Service IP / container | Docker Desktop forward target |
|---:|---|---|
| 8000 | Backend `11c978ed…`, `172.18.0.5` | Backend ID, stale IP `172.18.0.6` (then PostgreSQL) |
| 5173 | Frontend `74b6da82…`, `172.18.0.3` | Frontend ID, stale IP `172.18.0.2` (then Redis) |
| 8025 | Mailpit `ef388027…`, `172.18.0.4` | Mailpit ID, `172.18.0.4` |

Both `curl http://127.0.0.1:8000/api/health` and `curl http://127.0.0.1:5173/` from WSL failed with “connection reset”. Windows requests also had an empty response. Internal backend health and frontend-to-backend requests worked, isolating the issue to stale Docker Desktop host-forward records.

## Repair command

Executed from the repository root:

```sh
docker compose up -d --no-deps --force-recreate --no-build backend frontend
```

Compose output showed only `veltra-backend-1` and `veltra-frontend-1` were recreated and started. `--no-deps` prevented dependency recreation, and `--no-build` ensured the existing backend image was reused.

## After repair

| Service | New/current container ID | Current IP | Docker Desktop target | Published host address |
|---|---|---|---|---|
| Backend | `cc2121e00b2b386174230b82199c6d5d867761986c29834cdfb1030d07395de8` | `172.18.0.3` | Same container ID, `172.18.0.3:8000` | `127.0.0.1:8000` |
| Frontend | `855cbacd5a7378f261aedbea3dbe247e9e0e9ddf08119d9edf435f3ccb31b2f8` | `172.18.0.5` | Same container ID, `172.18.0.5:5173` | `127.0.0.1:5173` |

Docker Desktop `GET /forwards/list` records showed the refreshed backend/frontend container IDs and current IPs. The backend mapping is explicitly container-ID-to-IP. Docker Desktop's frontend registration was also for the new container ID and current IP; subsequent `docker port` output and successful HTTP tests verify its loopback publication.

The IPs changed because recreated services received fresh addresses from the Compose network. No network configuration or host bind address was changed.

## Endpoint and service checks

### Host endpoints

| Probe | WSL result | Windows result |
|---|---|---|
| `http://127.0.0.1:8000/api/health` | HTTP 200; JSON reports `status: ok`, PostgreSQL `up`, Redis `up` | HTTP 200; same health JSON |
| `http://127.0.0.1:5173/` | HTTP 200; page title `VELTRA 2.0` | HTTP 200 |
| `http://127.0.0.1:8025/` (Mailpit) | HTTP 200 | HTTP 200 |

Exact WSL commands:

```sh
curl --max-time 10 -fsS -w '\nbackend_http=%{http_code}\n' \
  http://127.0.0.1:8000/api/health
curl --max-time 10 -fsS -o /tmp/veltra-frontend-after.html -w 'frontend_http=%{http_code}\n' \
  http://127.0.0.1:5173/
grep -o '<title>[^<]*</title>' /tmp/veltra-frontend-after.html
curl --max-time 10 -fsS -o /dev/null -w 'mailpit_http=%{http_code}\n' \
  http://127.0.0.1:8025/
```

Exact Windows check:

```powershell
foreach ($x in @(
  @{p=8000; path="/api/health"},
  @{p=5173; path="/"},
  @{p=8025; path="/"}
)) {
  $u = "http://127.0.0.1:$($x.p)$($x.path)"
  $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 10 $u
  "WINDOWS $u status=$($r.StatusCode) bytes=$($r.RawContentLength)"
}
```

### All services and protected dependencies

| Service | Result |
|---|---|
| Backend | Running, Docker health `healthy`; image remains `veltra-backend:stage1a`, image ID `sha256:7025b7890e4bcc661e6c1bc67701b5c29c642b155d389592c2752fb3311cd46b` |
| Frontend | Running, Docker health `healthy`; image remains `veltra-frontend` |
| PostgreSQL | Original container ID `a1fed478…` unchanged, healthy; `pg_isready` accepts connections |
| Redis | Original container ID `581fcf39…` unchanged, healthy; `redis-cli ping` returns `PONG` |
| Mailpit | Original container ID `ef388027…` unchanged, healthy; UI and API return HTTP 200 |
| Queue | Original container ID `226212a0…` unchanged, running; `php artisan queue:work redis --sleep=3 --tries=3 --timeout=60` process present |
| PostgreSQL volume | `veltra_postgres-data` unchanged and still mounted at `/var/lib/postgresql/data` |
| Redis volume | `veltra_redis-data` unchanged and still mounted at `/data` |

The application migration repository still contains only migrations 000001–000009 as `Ran`. No database migration or schema/data operation was executed.

## Scope and safeguards

- Only backend and frontend service containers were recreated.
- Compose used `--no-deps` and `--no-build`; PostgreSQL, Redis, Mailpit and queue container IDs did not change.
- Existing PostgreSQL and Redis volumes were not removed, replaced, or recreated.
- Backend image remained `veltra-backend:stage1a`; candidate image `veltra-backend:stage1e-candidate-db767dd` was not deployed.
- Migration 000010 was not applied; Stage 1F was not started.
- No application source or configuration was changed. The worktree changes are limited to the pre-existing untracked Stage 1E reports plus this repair report; no commit or push was made.
- All future user-facing VELTRA functionality remains required to be in Polish.

## Final result

**PASS — Docker Desktop localhost forwarding restored and verified from WSL and Windows.** Stop after this networking repair. Deployment of the Stage 1E candidate and migration 000010 remain separate actions requiring explicit authorization.
