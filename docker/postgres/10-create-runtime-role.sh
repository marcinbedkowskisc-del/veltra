#!/bin/sh
set -eu

if [ "$APP_DB_USERNAME" = "$POSTGRES_USER" ] \
    || [ "$APP_DB_MIGRATION_USERNAME" = "$POSTGRES_USER" ] \
    || [ "$APP_DB_MIGRATION_USERNAME" = "$APP_DB_USERNAME" ]; then
    printf '%s\n' "Nazwy roli runtime, migracyjnej i administratora PostgreSQL musza byc rozne." >&2
    exit 1
fi

psql \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set=ON_ERROR_STOP=1 \
    --set=app_db_username="$APP_DB_USERNAME" \
    --set=app_db_password="$APP_DB_PASSWORD" \
    --set=migration_username="$APP_DB_MIGRATION_USERNAME" \
    --set=migration_password="$APP_DB_MIGRATION_PASSWORD" <<'SQL'
SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS',
    :'app_db_username',
    :'app_db_password'
) WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_db_username')
\gexec
SELECT format(
    'ALTER ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS',
    :'app_db_username',
    :'app_db_password'
) \gexec
SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS',
    :'migration_username',
    :'migration_password'
) WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'migration_username')
\gexec
SELECT format(
    'ALTER ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS',
    :'migration_username',
    :'migration_password'
) \gexec
SELECT format('REVOKE %I FROM %I', granted.rolname, member.rolname)
FROM pg_auth_members memberships
JOIN pg_roles granted ON granted.oid = memberships.roleid
JOIN pg_roles member ON member.oid = memberships.member
WHERE member.rolname IN (:'app_db_username', :'migration_username')
\gexec
SELECT format('GRANT CONNECT, CREATE ON DATABASE %I TO %I', current_database(), :'migration_username') \gexec
SELECT format('ALTER SCHEMA public OWNER TO %I', :'migration_username') \gexec
SELECT format(
    CASE c.relkind
        WHEN 'v' THEN 'ALTER VIEW %I.%I OWNER TO %I'
        WHEN 'm' THEN 'ALTER MATERIALIZED VIEW %I.%I OWNER TO %I'
        WHEN 'f' THEN 'ALTER FOREIGN TABLE %I.%I OWNER TO %I'
        ELSE 'ALTER TABLE %I.%I OWNER TO %I'
    END,
    n.nspname,
    c.relname,
    :'migration_username'
)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
  AND c.relowner <> (SELECT oid FROM pg_roles WHERE rolname = :'migration_username')
  AND NOT EXISTS (
      SELECT 1
      FROM pg_depend d
      WHERE d.classid = 'pg_class'::regclass
        AND d.objid = c.oid
        AND d.refclassid = 'pg_extension'::regclass
        AND d.deptype = 'e'
  )
\gexec
SELECT format('ALTER SEQUENCE %I.%I OWNER TO %I', n.nspname, c.relname, :'migration_username')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind = 'S'
  AND c.relowner <> (SELECT oid FROM pg_roles WHERE rolname = :'migration_username')
  AND NOT EXISTS (
      SELECT 1
      FROM pg_depend d
      WHERE d.classid = 'pg_class'::regclass
        AND d.objid = c.oid
        AND d.refclassid = 'pg_extension'::regclass
        AND d.deptype = 'e'
  )
\gexec
SELECT format(
    CASE p.prokind
        WHEN 'p' THEN 'ALTER PROCEDURE %I.%I(%s) OWNER TO %I'
        WHEN 'a' THEN 'ALTER AGGREGATE %I.%I(%s) OWNER TO %I'
        ELSE 'ALTER FUNCTION %I.%I(%s) OWNER TO %I'
    END,
    n.nspname,
    p.proname,
    pg_get_function_identity_arguments(p.oid),
    :'migration_username'
)
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public'
  AND p.proowner <> (SELECT oid FROM pg_roles WHERE rolname = :'migration_username')
  AND NOT EXISTS (
      SELECT 1
      FROM pg_depend d
      WHERE d.classid = 'pg_proc'::regclass
        AND d.objid = p.oid
        AND d.refclassid = 'pg_extension'::regclass
        AND d.deptype = 'e'
  )
\gexec
SELECT format('ALTER TYPE %I.%I OWNER TO %I', n.nspname, t.typname, :'migration_username')
FROM pg_type t
JOIN pg_namespace n ON n.oid = t.typnamespace
WHERE n.nspname = 'public'
  AND t.typrelid = 0
  AND t.typtype IN ('c', 'd', 'e', 'r', 'm')
  AND t.typowner <> (SELECT oid FROM pg_roles WHERE rolname = :'migration_username')
  AND NOT EXISTS (
      SELECT 1
      FROM pg_depend d
      WHERE d.classid = 'pg_type'::regclass
        AND d.objid = t.oid
        AND d.refclassid = 'pg_extension'::regclass
        AND d.deptype = 'e'
  )
\gexec
BEGIN;
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'app_db_username') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'migration_username') \gexec
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_db_username') \gexec
SELECT format(
    'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I',
    :'app_db_username'
) \gexec
SELECT format(
    'GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO %I',
    :'app_db_username'
) \gexec
SELECT format(
    'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I',
    :'migration_username',
    :'app_db_username'
) \gexec
SELECT format(
    'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO %I',
    :'migration_username',
    :'app_db_username'
) \gexec
SELECT format(
    'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE %s FROM %I',
    to_regclass('public.raw_material_permission_grant_operation_uses'),
    :'app_db_username'
)
WHERE to_regclass('public.raw_material_permission_grant_operation_uses') IS NOT NULL
\gexec
COMMIT;
SQL
