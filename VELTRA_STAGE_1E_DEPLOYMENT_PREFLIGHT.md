# VELTRA 2.0 — Stage 1E Deployment Preflight

## Recommendation: NO-GO

Do not apply migration `2026_11_02_000010_create_location_module_configuration.php` yet. The live database is at migration 000009, but the currently running backend image does not contain migration 000010. In addition, the backend container's internal health probe succeeds while the host-published `127.0.0.1:8000` health request resets its connection. A backup archive has been created and its format/TOC validated, but a full isolated restore rehearsal has not been performed. PostgreSQL WAL archiving/PITR is not configured.

These are preflight gates, not authorization to rebuild containers or change the database. Stop here pending explicit authorization and resolution of the listed blockers.

## Environment identification

| Item | Observed |
|---|---|
| Docker context and engine | `default`; client/server 29.9.2 |
| Compose project/network | `veltra` / `veltra_default` |
| Application containers | `veltra-backend-1`, `veltra-queue-1`, `veltra-frontend-1`, `veltra-mailpit-1`, `veltra-postgres-1`, `veltra-redis-1`; all running |
| Health | Backend, frontend, Mailpit, PostgreSQL and Redis report healthy. Queue is running and has no Docker healthcheck. PostgreSQL `pg_isready` succeeds; Redis returns `PONG`. |
| Backend image and mount | `veltra-backend:stage1a`; `/var/www/html` is baked into the image, with no source bind mount |
| Backend runtime DB config | `pgsql`, host `postgres`, port 5432, database `veltra`, configured migration table `migrations`; runtime query confirms role `veltra`, server `172.18.0.6/32`, PostgreSQL 17.11 |
| PostgreSQL persistent volume | `veltra_postgres-data` mounted at `/var/lib/postgresql/data` |
| Redis persistent volume | `veltra_redis-data` mounted at `/data` |
| Current app DB migration state | Migrations 000001–000009 ran; 000010 is not in the live migration repository |
| Current data footprint relevant to this migration | `security_events`: 0 rows, 16 kB relation; `locations`: 0 rows; `location_module_states` does not exist; database size 8,678 kB |
| Current Git state before this report | No uncommitted changes. Branch `main` is one local commit ahead of `origin/main` (`db767dd`, `008`); that commit is preserved and was not pushed. |

### Runtime artifact and health gates

The migration exists in the checkout and in the current local commit, SHA-256:

```text
8874a01ebac7fad19bff42fad9e92ea91c59636c51fd3db95df4bcf595c1c2cd
```

It is **missing from the running backend container** (`MIGRATION_MISSING`). The running backend cannot apply it until a future, explicitly authorized deployment builds and starts an image containing the migration.

The configured in-container health endpoint `http://127.0.0.1:8000/api/health` returned status `ok` with PostgreSQL and Redis `up`; frontend-to-backend access returned HTTP 200. However, requests from this WSL environment to the published `http://127.0.0.1:8000/api/health` reset the connection (curl status 000) on repeated probes. Resolve and independently retest host-published reachability before deployment. Do not treat Docker's healthy status as proof that the host-facing route works.

### Worktree preservation

The worktree was clean before this preflight report was created. No existing uncommitted source/configuration changes were found to preserve. The one-ahead local commit remains untouched. This report is the only file created by this preflight.

All future user-facing VELTRA functionality must remain in Polish.

## Migration readiness and impact

The migration:

1. Adds seven module-operation/audit columns to `security_events`. `event_kind` is non-null with default `security`; CHECK constraints validate event kind, module key and event shape.
2. Adds a composite index on `(organization_id, operation_id, event_kind)`.
3. Creates `location_module_states`, including organization/location/user foreign keys, a unique `(location_id, module_key)` key, and an organization/location index.
4. Creates a trigger that locks the owning organization row for each module-state insert/update/delete, plus a trigger protecting immutable state identity.

Read-only checks found no existing state table, index, or trigger-function name conflicts. Existing `security_events` rows are zero, so the `security` default and new shape check have no legacy rows to validate in this environment. No existing module-state or location data will be transformed by `up()`.

### Locking and failure behavior

`ALTER TABLE security_events` needs a strong table lock; added CHECK constraints are validated against existing rows. The new index is created normally, not `CONCURRENTLY`, and can block writes to `security_events` while it builds. The table is currently empty and only 16 kB, so expected work is small, but lock acquisition can still wait behind active transactions. The state-table trigger also serializes state writes on the organization row.

Laravel's PostgreSQL schema grammar supports schema transactions, and the migration's default `withinTransaction` behavior means its DDL is run in a transaction by the migration runner. Still set a bounded `lock_timeout` for deployment, monitor lock waits and application health, and stop if the migration waits or health degrades. Do not infer success from a command starting; confirm both schema and migration repository after completion.

`down()` is destructive: it drops `location_module_states` and removes the module-specific `security_events` columns, destroying configuration and audit detail. Never use rollback as the routine recovery response.

## Backup and recovery readiness

### Backup created during this preflight

The live database was dumped using PostgreSQL 17.11 tools. Backup files are outside Docker's named database volume, in a restricted host directory:

```text
/home/veltra/.local/share/veltra/backups/
```

Directory mode is 0700 and each backup file mode is 0600.

| File | Size | SHA-256 | Validation |
|---|---:|---|---|
| `veltra-pre-stage1e-20261102-20261102T170908Z.dump` | 78,404 bytes | `4925376aaccf9a92bd49f6dda1a26084450554d32395550b92d59a410e10fed2` | Non-empty; `pg_restore --list` parsed the archive TOC |
| `veltra-globals-pre-stage1e-20261102-20261102T170908Z.sql` | 522 bytes | `9878858f2ac93c2dd2474f248ae4d50e7a57af5a59fe67e2d65b3ae8be7c32dc` | Non-empty globals dump; password hashes excluded |

The globals file was created with `--no-role-passwords`; on recovery, provision role credentials from the approved secret store. The archive listing and checksums verify file integrity and archive readability, **not a successful restore**. No database or container was created for a restore rehearsal because this task is read-only and prohibits container/volume recreation. A full restore rehearsal in a separately approved disposable environment is still a mandatory GO gate.

PostgreSQL reports `wal_level=replica`, `archive_mode=off`, `max_wal_senders=10`. WAL archiving/PITR is therefore not configured by this observed setting. The logical backup provides a snapshot recovery point only; it does not provide point-in-time recovery for writes after that snapshot.

### Repeatable backup commands

Run these commands before any approved schema operation. They stream the database dump from PostgreSQL to host storage; they do not write into the PostgreSQL data volume. Keep the directory private and verify the exit status, archive TOC, checksum and file modes.

```sh
set -eu
BACKUP_DIR=/home/veltra/.local/share/veltra/backups
umask 077
install -d -m 700 "$BACKUP_DIR"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DB_BACKUP="$BACKUP_DIR/veltra-pre-stage1e-$STAMP.dump"
GLOBALS_BACKUP="$BACKUP_DIR/veltra-globals-pre-stage1e-$STAMP.sql"

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
stat -c '%n %s bytes mode=%a' "$DB_BACKUP" "$GLOBALS_BACKUP"
```

Before calling a backup *restorable*, restore it into a fresh isolated PostgreSQL 17 database/environment, verify roles and ownership handling, schema, row counts, constraints, application reads and the expected migration inventory; retain the restore log and checksum comparison. Do not restore over the running `veltra` database as a verification step.

### Recovery plan

1. Prefer forward recovery. If deployment fails, preserve the current database/schema, capture migration and PostgreSQL logs, diagnose, and apply a corrective forward migration or application image.
2. For an exception requiring restoration, obtain recorded approval from the database operator and responsible service and security/data owners. Freeze writes, record the recovery point, and reconcile all writes newer than the backup.
3. Rehearse the complete restore into a disposable isolated PostgreSQL 17 instance first. Verify schema, all pre-existing table data, security-event continuity, migration inventory, grants/roles, constraints and application health. Keep a checksummed export of any post-migration `location_module_states` and extended `security_events` data if those tables/columns have been populated.
4. Restore the live database only under an explicitly approved disaster-recovery plan and maintenance window, after confirming how writes since the snapshot will be recovered. The current configuration has no WAL archiving/PITR, so those writes cannot be recovered from WAL here.
5. After recovery, verify the same checks below and obtain operator sign-off. Do **not** run the migration's `down()` to recover: it discards configuration and audit detail. If history cannot be preserved, operational rollback is prohibited.

## Exact deployment commands — future authorized window only

These are a proposed sequence, **not executed in this preflight**. First resolve NO-GO items, complete and record the isolated restore rehearsal, receive explicit authorization, confirm the worktree/source commit is the approved release, and schedule an outage/maintenance window.

From the repository root:

```sh
# Rebuild only the services whose image contains the backend application/migrations.
docker compose build backend queue

# Start the rebuilt app services; this is a future deployment action and recreates
# those service containers if Compose determines it is required.
docker compose up -d --no-deps backend queue

# Confirm the running image contains the exact migration and matches the approved file.
docker compose exec -T backend test -f \
  database/migrations/2026_11_02_000010_create_location_module_configuration.php
docker compose exec -T backend sha256sum \
  database/migrations/2026_11_02_000010_create_location_module_configuration.php

# Reconfirm target and pending status before the schema change.
docker compose exec -T backend php artisan migrate:status --database=pgsql

# Apply only with bounded PostgreSQL lock/statement waits.
docker compose exec -T -e 'PGOPTIONS=-c lock_timeout=5s -c statement_timeout=300s' \
  backend php artisan migrate --force --database=pgsql

# Confirm migration 000010 is Ran and all services recover.
docker compose exec -T backend php artisan migrate:status --database=pgsql
docker compose ps
curl -fsS --max-time 5 http://127.0.0.1:8000/api/health
```

The current image fails the migration-file check, so do not issue the migration command against the currently running backend.

## Post-migration verification plan

Run only after an authorized migration, without issuing configuration writes:

1. **Migration repository and app health**

   ```sh
   docker compose exec -T backend php artisan migrate:status --database=pgsql
   curl -fsS --max-time 5 http://127.0.0.1:8000/api/health
   docker compose ps
   ```

   Confirm 000001–000010 are `Ran`, HTTP health returns `status: ok` with PostgreSQL and Redis `up`, and all six application services are in their expected states. Also verify the frontend loads and frontend-to-backend requests work.

2. **Schema shape and validated constraints**

   ```sh
   docker exec veltra-postgres-1 sh -c \
     'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "\d+ public.location_module_states" -c "\d+ public.security_events"'
   docker exec veltra-postgres-1 sh -c \
     'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT conname, convalidated FROM pg_constraint WHERE conrelid = '\''public.security_events'\''::regclass AND conname = '\''security_events_module_shape'\'';"'
   docker exec veltra-postgres-1 sh -c \
     'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT tgname, tgenabled FROM pg_trigger WHERE tgrelid = '\''public.location_module_states'\''::regclass AND NOT tgisinternal ORDER BY tgname;"'
   docker exec veltra-postgres-1 sh -c \
     'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT tgname, tgenabled FROM pg_trigger WHERE tgrelid = '\''public.security_events'\''::regclass AND tgname = '\''security_events_append_only'\'';"'
   ```

   Confirm all seven audit columns, the new state table and expected indexes/FKs/unique constraint exist; `security_events_module_shape` is validated; both state triggers are enabled; and the existing `security_events_append_only` trigger remains enabled.

3. **Legacy audit integrity and defaults**

   ```sh
   docker exec veltra-postgres-1 sh -c \
     'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT event_kind, count(*) FROM security_events GROUP BY event_kind ORDER BY event_kind;" -c "SELECT count(*) AS malformed_transitions FROM security_events WHERE event_kind='\''module_transition'\'' AND (operation_id IS NULL OR module_key IS NULL OR previous_enabled IS NULL OR requested_enabled IS NULL OR effective_enabled IS NULL OR effective_enabled IS DISTINCT FROM requested_enabled OR previous_enabled IS NOT DISTINCT FROM effective_enabled OR outcome <> '\''accepted'\'' OR detail_code IS NULL);" -c "SELECT count(*) AS module_state_rows FROM location_module_states;"'
   ```

   The current baseline has no locations or module states, so state count should remain zero until an authorized configuration operation. Legacy pre-migration audit rows, if present at deployment time, must retain their original columns/values and map to `event_kind='security'` with module-specific columns NULL. The current observed event count is zero.

4. **Readiness and regression**

   Check the production catalog still reports all modules `NOT_READY`; do not enable anything. Run focused module-catalog, database-integrity, and API regression tests only in an isolated test database/environment, never against the live database. Compare table counts and the backup manifest for legacy data; investigate any unexpected difference before resuming writes.

## Final disposition

**NO-GO.** Migration 000010 has not been applied. Do not rebuild/recreate containers, modify the database, or begin Stage 1F under this preflight. Required gates: (1) rebuild plan and prove migration presence in the target image, (2) resolve and retest host-published backend health, (3) perform and document a successful isolated restore rehearsal, and (4) explicitly accept the absence of PITR or establish the required WAL-archive recovery point. Wait for explicit authorization before deployment.
