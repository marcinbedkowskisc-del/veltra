# VELTRA 2.0 — Stage 1E Compose Configuration Report

## Result: PASS

The shared Compose backend image reference now selects the verified Stage 1E candidate for both `backend` and `queue`. Effective configuration was inspected, the referenced local image digest matches the deployed candidate, and an ordinary `docker compose up -d` dry run kept all six current containers running. No container was recreated, and no database migration or volume operation was performed.

## Change made

In [compose.yaml](./compose.yaml), the shared `x-backend` anchor changed its image from:

```yaml
image: veltra-backend:stage1a
```

to:

```yaml
image: veltra-backend:stage1e-candidate-db767dd
```

Both `backend` and `queue` inherit this shared anchor, so they now use one explicit, consistent Stage 1E image reference. No service definitions, build context, environment, dependencies, volumes, or ports were changed.

This removes Stage 1A as the ordinary Compose image selection. The existing backend build context remains configured; it does not change the image reference used by regular `docker compose up -d`. An explicit build operation can rebuild/tag the selected Stage 1E image from the local backend source, but it does not point either service back to Stage 1A.

## Image identity

The local candidate tag exists and resolves to the same image ID used by both running services:

```text
veltra-backend:stage1e-candidate-db767dd
sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21
```

Before this configuration edit, backend and queue already ran that exact image ID under the temporary Stage 1E Compose override. Their container identities were unchanged by this task.

## Effective Compose configuration verification

Commands used:

```sh
docker compose config --images
docker compose config --format json | python3 -c '
import json,sys
s=json.load(sys.stdin)["services"]
print("backend="+s["backend"]["image"])
print("queue="+s["queue"]["image"])
assert s["backend"]["image"] == "veltra-backend:stage1e-candidate-db767dd"
assert s["queue"]["image"] == "veltra-backend:stage1e-candidate-db767dd"
assert "stage1a" not in s["backend"]["image"] + s["queue"]["image"]
'
docker image inspect veltra-backend:stage1e-candidate-db767dd --format '{{.Id}}'
```

Observed:

```text
backend=veltra-backend:stage1e-candidate-db767dd
queue=veltra-backend:stage1e-candidate-db767dd
sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21
```

The Compose image list contains the Stage 1E candidate twice (backend and queue) and no `veltra-backend:stage1a` reference.

## Routine-up dry run and service verification

Ran a dry run only:

```sh
docker compose --dry-run up -d
```

Compose reported all six containers as `Running`; it proposed no rebuild or recreation. No real `docker compose up` was run as part of this configuration-only task.

Current container IDs and image references after verification:

| Service | Container ID | Image | State |
|---|---|---|---|
| Backend | `c6d2d8fe1ada675eb63414afd8d13c764f0f3a3729a4e3f0c6f32171c46d18ce` | `veltra-backend:stage1e-candidate-db767dd`, image ID `sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21` | Running, healthy |
| Queue | `3aba01b3719d9fd7d2ef1f42a2af491d0abbbec6662ec73c3a626dbf64e9983e` | Same candidate image ID | Running |
| Frontend | `855cbacd5a7378f261aedbea3dbe247e9e0e9ddf08119d9edf435f3ccb31b2f8` | `veltra-frontend` | Running, healthy |
| PostgreSQL | `a1fed478ec4dfaf500f0fb4fe97117f2156f98ae92c5509a524770a0dc94134c` | `postgres:17-alpine` | Running, healthy |
| Redis | `581fcf39a65df8dc36ac73e320e4753ca7641b086325f8cc5cbba62839a56abe` | `redis:7.4-alpine` | Running, healthy |
| Mailpit | `ef38802707258749910323e8c9a7d42b01fd5c84e7c940a4cf4301b4979a949b` | Pinned Mailpit digest | Running, healthy |

Additional read-only checks:

- `http://127.0.0.1:8000/api/health`: HTTP 200; PostgreSQL and Redis report `up`.
- `http://127.0.0.1:5173/`: HTTP 200.
- `http://127.0.0.1:8025/`: HTTP 200.
- PostgreSQL `pg_isready`: accepting connections.
- Redis `redis-cli ping`: `PONG`.
- Migration `2026_11_02_000010_create_location_module_configuration`: `Ran` (batch 4).

The `veltra_postgres-data` and `veltra_redis-data` volumes were not changed.

## Portability and operational considerations

- The candidate image is currently available in this local Docker engine. Its tag is a local candidate tag, not a published registry reference.
- A different machine must first load or build the Stage 1E image under the same tag. The Compose backend build context remains available for an explicit build; if the image is unavailable elsewhere, Compose can build it from that machine's checkout or report a pull/build failure. It will not silently choose the Stage 1A tag because Stage 1A is no longer configured for backend or queue.
- This is a local-development setting, not a production image promotion or registry-publishing procedure.
- The Compose file change does not recreate running containers; it affects future Compose service reconciliation. The currently running containers already use the target image.

## Scope and final disposition

- Only the one shared image line in `compose.yaml` changed.
- No rebuild, real Compose up, container recreation, migration, database write, or volume change was performed.
- `git diff --check` passed. No commit or push was created.
- Stage 1F was not started.

**PASS — ordinary Compose configuration now selects the verified Stage 1E image for both backend and queue, and all six services remain operational.**
