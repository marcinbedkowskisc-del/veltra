# VELTRA 2.0 — Stage 1E Local Deployment Report

## Final verdict: PASS — LOCAL DEVELOPMENT ONLY

The verified Stage 1E candidate image is running in the local backend and queue services. Migration `2026_11_02_000010_create_location_module_configuration.php` was the only pending migration and was applied successfully with bounded PostgreSQL lock and statement timeouts. Independent post-migration checks confirmed the expected schema, validated audit constraint, indexes, triggers, migration repository, application health, and module readiness state.

No production modules were enabled. PostgreSQL, Redis, Mailpit, frontend, and their existing data volumes were preserved. No destructive regression tests were run against the application database, no application source was changed, and no commit or push was created. Stage 1F was not started.

## Environment and pre-deployment checks

| Item | Verified value |
|---|---|
| Scope | Local development; not staging or production |
| Docker context | `default` |
| Compose project | `veltra` |
| Backend runtime | `APP_ENV=local` |
| Database connection | `pgsql`, host `postgres`, port `5432`, database `veltra`, role `veltra` |
| Database server | PostgreSQL 17.11 |
| Git revision | `db767dd72921f4829c3159816cf0b939c5b75b9c` |
| Git state before deployment | `main` one local commit ahead of `origin/main`; three pre-existing untracked reports preserved |
| Before migration status | 000001–000009 `Ran`; 000010 `Pending`; no other migration pending |
| Before deployment health | Backend `/api/health` HTTP 200 with PostgreSQL and Redis `up`; frontend HTTP 200 |
| Database volume | `veltra_postgres-data`, mounted at `/var/lib/postgresql/data` |
| Redis volume | `veltra_redis-data`, mounted at `/data` |

The Compose file originally selected `veltra-backend:stage1a` for both backend and queue. `docker compose config --images` was inspected before the rollout. The other configured images were `veltra-frontend`, `postgres:17-alpine`, `redis:7.4-alpine`, and the pinned Mailpit digest.

## Fresh pre-deployment backup

Created at `2026-11-02T17:44:30Z`, outside Docker's database volume:

| File | Size | Mode | SHA-256 | Verification |
|---|---:|---:|---|---|
| `/home/veltra/.local/share/veltra/backups/veltra-pre-stage1e-deploy-20261102T174430Z.dump` | 78,404 bytes | 0600 | `d39c0ae1bb2cba4a0984797099c8a3a7003bc1fdb9d7b41ef42c2172bb831c1b` | Non-empty; `pg_restore --list` parsed successfully |
| `/home/veltra/.local/share/veltra/backups/veltra-globals-pre-stage1e-deploy-20261102T174430Z.sql` | 522 bytes | 0600 | `b105161e082f380b58556b89c775d9ffe282d5329ba1ff30960437ef390200e2` | Non-empty globals snapshot, generated without role password hashes |

The backup directory is under `/home/veltra/.local/share/veltra/backups/`, not under Docker's volume mount. The database archive was dumped from the verified `veltra` database using the PostgreSQL container's `POSTGRES_USER` and `POSTGRES_DB`; both backup checksums were rechecked after migration and remained unchanged.

Exact backup commands:

```sh
BACKUP_DIR=/home/veltra/.local/share/veltra/backups
umask 077
install -d -m 700 "$BACKUP_DIR"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DB_BACKUP="$BACKUP_DIR/veltra-pre-stage1e-deploy-$STAMP.dump"
GLOBALS_BACKUP="$BACKUP_DIR/veltra-globals-pre-stage1e-deploy-$STAMP.sql"
docker exec veltra-postgres-1 sh -c \
  'pg_dump -Fc --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
  > "$DB_BACKUP"
chmod 600 "$DB_BACKUP"
docker exec veltra-postgres-1 sh -c \
  'pg_dumpall --globals-only --no-role-passwords -U "$POSTGRES_USER"' \
  > "$GLOBALS_BACKUP"
chmod 600 "$GLOBALS_BACKUP"
test -s "$DB_BACKUP" && test -s "$GLOBALS_BACKUP"
docker exec -i veltra-postgres-1 pg_restore --list < "$DB_BACKUP" > /dev/null
sha256sum "$DB_BACKUP" "$GLOBALS_BACKUP"
```

## Candidate verification and deployment

The candidate tag was `veltra-backend:stage1e-candidate-db767dd`, image ID:

```text
sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21
```

The candidate's migration checksum matched the approved report, checked-out source, and deployed container:

```text
8874a01ebac7fad19bff42fad9e92ea91c59636c51fd3db95df4bcf595c1c2cd
```

The following Stage 1E code hashes also matched the candidate verification report and deployed image:

| File | SHA-256 |
|---|---|
| `app/Domain/Modules/ModuleConfigurationService.php` | `b0db646a833b728ca02fe7c261b2788f8ee579abb80e44fe340d5959245807f7` |
| `app/Domain/Modules/DefinedModuleCatalog.php` | `82c365e11e615865bb427ee0481bfc989bfa30f9b5cb7fd0941a0994c7fa81b0` |
| `app/Http/Controllers/Api/V1/ModuleConfigurationController.php` | `e22be8c5cd427ed3ed4ce7e27f77a3503a98354d52fd40d46c0addcb043872be` |

The running backend was checked for the exact migration file and its SHA-256 before applying the migration. Candidate image verification ran with network disabled.

To leave `compose.yaml` unchanged, a Compose stdin override selected the candidate for backend and queue. The command explicitly disabled dependencies and image builds:

```sh
docker compose -f compose.yaml -f - up -d --no-deps --force-recreate --no-build backend queue <<'YAML'
services:
  backend:
    image: veltra-backend:stage1e-candidate-db767dd
  queue:
    image: veltra-backend:stage1e-candidate-db767dd
YAML
```

The only recreated containers were backend and queue. Both now run image ID `sha256:4df36f9ed05b1c5b10706ed81d5c99f0c076bd1074b1261e3a8cede23c1f6a21`.

### Container identities

| Service | Before | After | Result |
|---|---|---|---|
| Backend | `cc2121e00b2b386174230b82199c6d5d867761986c29834cdfb1030d07395de8`, Stage 1A image `sha256:7025b7890e4bcc661e6c1bc67701b5c29c642b155d389592c2752fb3311cd46b` | `c6d2d8fe1ada675eb63414afd8d13c764f0f3a3729a4e3f0c6f32171c46d18ce`, candidate image | Recreated and healthy |
| Queue | `226212a0e5dbe88a7a10e09d092ab0ce4e7dfc99257597033b85afefc35d9b40`, Stage 1A image | `3aba01b3719d9fd7d2ef1f42a2af491d0abbbec6662ec73c3a626dbf64e9983e`, candidate image | Recreated and running |
| Frontend | `855cbacd5a7378f261aedbea3dbe247e9e0e9ddf08119d9edf435f3ccb31b2f8` | Same | Unchanged, healthy |
| PostgreSQL | `a1fed478ec4dfaf500f0fb4fe97117f2156f98ae92c5509a524770a0dc94134c` | Same | Unchanged, healthy |
| Redis | `581fcf39a65df8dc36ac73e320e4753ca7641b086325f8cc5cbba62839a56abe` | Same | Unchanged, healthy |
| Mailpit | `ef38802707258749910323e8c9a7d42b01fd5c84e7c940a4cf4301b4979a949b` | Same | Unchanged, healthy |

The existing named volumes `veltra_postgres-data` and `veltra_redis-data` remained attached to their original containers. Neither volume was removed, recreated, or changed.

**Operational note:** `compose.yaml` still defaults the backend/queue image reference to `veltra-backend:stage1a`. The deployment used the inline candidate override and did not edit tracked Compose configuration. Future Compose `up` operations must use the same candidate override (or a separately authorized persistent deployment configuration); invoking the base Compose file alone could select the Stage 1A image.

## Migration execution

Immediately before applying, the candidate backend reported exactly one pending migration: `2026_11_02_000010_create_location_module_configuration`. Migrations 000001–000009 were already `Ran`.

Applied only migration 000010 with a 5-second PostgreSQL lock timeout and 300-second statement timeout:

```sh
docker exec \
  -e 'PGOPTIONS=-c lock_timeout=5s -c statement_timeout=300s' \
  -w /var/www/html \
  veltra-backend-1 \
  php artisan migrate --force --database=pgsql \
    --path=database/migrations/2026_11_02_000010_create_location_module_configuration.php \
    --no-interaction
```

Result:

```text
2026_11_02_000010_create_location_module_configuration ........ 39.56ms DONE
```

No other migration was invoked. No rollback or database restore was performed.

## Post-migration verification

### Migration repository and schema

- Migrations 000001–000010 all report `Ran`; migration 000010 is batch 4.
- All seven expected `security_events` columns exist. `event_kind` is `NOT NULL` with default `'security'`.
- `security_events_module_shape` is present and `convalidated = true`.
- Existing event-kind and module-key constraints remain present and validated.
- `location_module_states` has the expected primary key, `(location_id, module_key)` unique constraint, module-key check, user/organization foreign keys, and composite `(location_id, organization_id)` location foreign key. All reported constraints are validated.
- `security_events_module_operation_idx`, `location_module_states_organization_idx`, and the unique location/module index all report `indisvalid = true` and `indisready = true`.
- Enabled trigger state (`tgenabled = 'O'`) was confirmed for `location_module_states_identity`, `location_module_states_serialize`, and the pre-existing `security_events_append_only` trigger.
- The exact `security_events_module_shape` definition was read from PostgreSQL; it enforces required transition fields, equality of requested/effective state, non-no-op transitions, and accepted outcomes.

### Data and module readiness

Post-migration exact public-table row counts show all application tables empty, with 10 migration rows. Specifically, `security_events=0`, `locations=0`, and `location_module_states=0`; malformed transition count is 0. This agrees with the pre-deployment checks that there were no location or audit rows and with the migration adding one new public table.

The running production module catalog reported:

```text
TEMPERATURES|NOT_READY
CLEANING|NOT_READY
LOSSES|NOT_READY
INVENTORY|NOT_READY
```

No location module configuration rows exist, so no production module is enabled.

### Health and service checks

| Check | Result |
|---|---|
| Backend `http://127.0.0.1:8000/api/health` | HTTP 200; JSON `status: ok`, PostgreSQL `up`, Redis `up` |
| Frontend `http://127.0.0.1:5173/` | HTTP 200 |
| Mailpit `http://127.0.0.1:8025/` | HTTP 200 |
| PostgreSQL | `pg_isready` reports accepting connections |
| Redis | `redis-cli ping` returns `PONG` |
| Backend container | Running, healthy |
| Queue | Running; `php artisan queue:work redis --sleep=3 --tries=3 --timeout=60` process present |
| Frontend, PostgreSQL, Redis, Mailpit | Running; healthy |

Non-destructive smoke commands:

```sh
docker exec -w /var/www/html veltra-backend-1 php artisan --version
docker exec -w /var/www/html veltra-backend-1 php artisan route:list --path=api/health --no-interaction
curl --max-time 10 -fsS -w '\nbackend_http=%{http_code}\n' \
  http://127.0.0.1:8000/api/health
curl --max-time 10 -fsS -o /dev/null -w 'frontend_http=%{http_code}\n' \
  http://127.0.0.1:5173/
curl --max-time 10 -fsS -o /dev/null -w 'mailpit_http=%{http_code}\n' \
  http://127.0.0.1:8025/
docker exec veltra-postgres-1 pg_isready -U veltra -d veltra
docker exec veltra-redis-1 redis-cli ping
```

No destructive regression test was run against the application database. The independent Stage 1E final verification previously passed 30 focused tests / 295 assertions and the full backend suite of 139 tests / 1,052 assertions in a dedicated disposable PostgreSQL environment.

## Recovery and limitations

- The fresh logical backup and globals snapshot are preserved outside Docker volumes. If an issue is found, preserve diagnostics and pause rollout; do not automatically restore or overwrite the application database.
- Prefer forward recovery. Migration 000010's `down()` drops module configuration and module-specific audit details; do not operationally roll it back without the recorded operator/service/security approvals and a rehearsed, history-preserving recovery plan.
- This verification is local-development only. The database had no location or audit rows; post-migration live checks therefore validate schema and empty-state integrity, not historical transition records.
- `compose.yaml` remains on the Stage 1A image reference; use the documented override for future Compose operations to retain this candidate.
- No production module was activated. No Stage 1F work was performed.
- No application source or migration was modified; no commit or push was made.

## Final disposition

**PASS — the verified Stage 1E candidate is deployed to the local backend and queue, migration 000010 is applied, and required post-deployment checks passed.** The application database and Redis volumes were preserved. Do not treat this local result as production deployment authorization.
