# VELTRA 2.0 — Stage 1E Deployment Blockers Resolution

## Revised recommendation: NO-GO for deployment; preflight artifacts verified

The candidate image and database backup restoration are verified. The host-published backend and frontend endpoints still fail because Docker Desktop is forwarding to stale container IPs. Refreshing those mappings requires recreating the affected service containers, which was not authorized and was not done. Therefore **do not deploy the candidate image or apply migration 000010 yet**.

The live application database remains at migrations 000001–000009. No existing application container was restarted/recreated, no application database/schema or existing volume was changed, and Stage 1F was not started.

## 1. Candidate backend image

### Cause of the missing migration

The running services use `veltra-backend:stage1a`, image ID:

```text
sha256:7025b7890e4bcc661e6c1bc67701b5c29c642b155d389592c2752fb3311cd46b
```

The backend Dockerfile uses `COPY --chown=www-data:www-data . .` from the backend build context and does not exclude migration files. The migration is present in the current repository commit but absent from the already-built Stage 1A image. The running image predates the current source containing migration 000010; the migration was not removed by a Docker ignore rule. This explains the artifact mismatch without requiring an application-code change.

### Candidate build and verification

Built without changing the existing tag or starting/replacing a container:

```sh
docker build --pull=false \
  --tag veltra-backend:stage1e-candidate-db767dd \
  --file backend/Dockerfile backend
```

| Property | Result |
|---|---|
| Source commit | `db767dd72921f4829c3159816cf0b939c5b75b9c` |
| Candidate tag | `veltra-backend:stage1e-candidate-db767dd` |
| Candidate image ID | `sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21` |
| Candidate image size | 308,834,567 bytes |
| Existing `stage1a` image | Remains tagged and unchanged at its original image ID |
| Migration SHA-256 inside candidate | `8874a01ebac7fad19e6c1bc67701b5c29c642b155d389592c2752fb3311cd46b` |

The migration hash matched the source file byte-for-byte. The hashes of these current Stage 1E files also matched the candidate image:

- `app/Domain/Modules/ModuleConfigurationService.php`: `b0db646a833b728ca02fe7c261b2788f8ee579abb80e44fe340d5959245807f7`
- `app/Domain/Modules/DefinedModuleCatalog.php`: `82c365e11e615865bb427ee0481bfc989bfa30f9b5cb7fd0941a0994c7fa81b0`
- `app/Http/Controllers/Api/V1/ModuleConfigurationController.php`: `e22be8c5cd427ed3ed4ce7e27f77a3503a98354d52fd40d46c0addcb043872be`

Candidate checks passed: migration PHP syntax, `php artisan --version` (Laravel 13.39.0), and presence of the module service, catalog, and API controller. The inspection used `docker run --rm --network none` and did not connect the candidate to application services. The candidate image was **not deployed or started**.

## 2. Host-published health endpoint

### Findings

- Backend listens on `0.0.0.0:8000` inside its container. Its own health check succeeds, and the frontend container receives HTTP 200 from `http://backend:8000/api/health`.
- Docker reports the publication as `127.0.0.1:8000`; Windows `com.docker.backend.exe` owns the host listener.
- Requests from both WSL and Windows to host ports 8000 and 5173 establish TCP but receive a reset/empty HTTP response. The Mailpit publication on 8025 works from both sides (HTTP 200). This is not a general WSL localhost-forwarding failure, nor an application health-route failure.
- Docker Desktop's host-forward table in its backend log recorded these destinations:

| Published host port | Forward target recorded by Docker Desktop | Current container IP | IP currently belongs to |
|---:|---|---|---|
| 8000 | backend container ID `11c978ed…`, `172.18.0.6:8000` | backend is `172.18.0.5` | `172.18.0.6` is PostgreSQL |
| 5173 | frontend container ID `74b6da82…`, `172.18.0.2:5173` | frontend is `172.18.0.3` | `172.18.0.2` is Redis |
| 8025 | Mailpit container ID `ef388027…`, `172.18.0.4:8025` | Mailpit is `172.18.0.4` | Correct target; publication works |

The host-forward entries point to old IPs reused by PostgreSQL and Redis. This stale Docker Desktop forwarding state is the root cause of the resets. The backend application and frontend service are healthy on the Compose network.

### Smallest safe correction

No application source or broad network binding change is indicated. In particular, do **not** change the host bind from `127.0.0.1` to `0.0.0.0`; that would unnecessarily expose development services to other interfaces.

The forwarding entries need to be refreshed against current container IPs. The smallest practical correction is to recreate only the affected `backend` and `frontend` service containers using the approved image/configuration, then verify Docker Desktop maps them to their new current IPs. That would restart/recreate existing application containers, so it was deliberately not performed. Obtain explicit authorization before doing so. A Docker Desktop networking restart may refresh its forwarding service but can disrupt all containers and is not recommended as the first action.

After an authorized refresh, verify from both Windows and WSL:

```sh
curl -fsS --max-time 5 http://127.0.0.1:8000/api/health
curl -fsS --max-time 5 http://127.0.0.1:5173/
docker port veltra-backend-1 8000/tcp
```

Also compare Docker Desktop's forwarding targets with `docker inspect` container IPs. Do not treat internal health checks alone as host-port verification.

## 3. PostgreSQL backup restoration rehearsal

Used the pre-existing backup and globals snapshot unchanged:

```text
/home/veltra/.local/share/veltra/backups/veltra-pre-stage1e-20261102-20261102T170908Z.dump
/home/veltra/.local/share/veltra/backups/veltra-globals-pre-stage1e-20261102-20261102T170908Z.sql
```

The database backup SHA-256 remained `4925376aaccf9a92bd49f6dda1a26084450554d32395550b92d59a410e10fed2`; the globals file SHA-256 remained `9878858f2ac93c2dd2474f248ae4d50e7a57af5a59fe67e2d65b3ae8be7c32dc`. Both stayed outside Docker's database volume with the original restricted permissions.

Created an isolated PostgreSQL 17.11 instance on internal-only network `veltra_stage1e_restore_internal_20261102`, using only the dedicated volume `veltra_stage1e_restore_pgdata_20261102`, with no published port. Restored the globals snapshot, created the target database, then restored the custom-format backup:

```sh
docker exec -i veltra-stage1e-restore-postgres-20261102 \
  psql -v ON_ERROR_STOP=1 -U postgres -d postgres \
  < /home/veltra/.local/share/veltra/backups/veltra-globals-pre-stage1e-20261102-20261102T170908Z.sql

docker exec veltra-stage1e-restore-postgres-20261102 \
  createdb -U postgres --owner=veltra veltra

docker exec -i veltra-stage1e-restore-postgres-20261102 \
  pg_restore --exit-on-error --no-owner --no-acl -U veltra -d veltra \
  < /home/veltra/.local/share/veltra/backups/veltra-pre-stage1e-20261102-20261102T170908Z.dump
```

### Restore verification results

| Check | Result |
|---|---|
| Server version and restored DB | PostgreSQL 17.11; database `veltra` restored successfully |
| Public base tables | 15 in source and restored DB |
| Per-table row counts | All 15 public base-table row counts matched exactly |
| Migration repository | 9 rows; latest is migration 000009 |
| Existing `security_events` rows | 0 in both source and restored DB |
| Locations | 0 in both source and restored DB |
| Migration-000010 state table | Absent, as expected for the pre-migration backup |
| Constraints | 91 total; all 91 validated |
| Indexes | 36 total; all 36 valid |
| Existing audit protection | `security_events_append_only` trigger present and enabled |
| Backup checksums | Both files unchanged after restore |

The disposable container, dedicated volume and internal network were removed after verification. No existing application volumes were used by the rehearsal.

## 4. Recovery policy and PITR

### Local development

PITR is **not proportionate or necessary for this current local development database**: the observed database is only 8,678 kB, has no locations or security-event data, and the verified restricted logical backup has been restored successfully. For this environment, retain timestamped logical backups outside Docker volumes, verify checksums and archive readability, and periodically rehearse restore into a disposable PostgreSQL 17 instance. A developer can accept restoring to the last snapshot for local-only data.

Operational risk remains: because `archive_mode=off`, changes after a logical snapshot cannot be recovered to an arbitrary point in time. This is acceptable only while the data remains disposable/local. Do not interpret the backup as a WAL/PITR recovery point.

### Future production

Production needs a separately approved RPO/RTO and recovery design. For business-critical or security/audit data, use managed PITR or continuous WAL archiving to access-controlled storage in a separate failure domain, retain base backups and WAL according to policy, monitor archive failures, and regularly perform full restore/PITR drills. Revisit this before production; this report did not enable WAL archiving or change PostgreSQL configuration.

## Remaining limitations and gates

1. **Host forwarding remains broken** for backend port 8000 and frontend port 5173. The stale targets are identified, but correcting them requires recreating the affected service containers, which remains unauthorized.
2. The candidate image was built and verified but was not started or deployed.
3. Migration 000010 remains pending in the live database. No migration or database-changing operation was run against it.
4. The restored backup proves a successful snapshot restore, not point-in-time recovery. PITR remains intentionally absent for local development.
5. No Stage 1F work was performed.

## Final recommendation

**NO-GO to deployment for now.** Candidate-image availability and backup restorability are resolved. The host-port blocker is diagnosed but not operationally corrected under the no-restart restriction. Obtain explicit authorization to refresh only backend/frontend container forwarding mappings, verify host health from Windows and WSL, then reassess deployment. Do not deploy the candidate image or apply migration 000010 until that gate is cleared and deployment is separately authorized.

