<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runtimeUsername = (string) env('APP_DB_USERNAME', 'veltra_app');
        $migrationUsername = (string) DB::scalar('SELECT session_user');
        $configuredMigrationUsername = (string) env('APP_DB_MIGRATION_USERNAME', 'veltra_migrator');
        $applicationKey = config('app.key');
        if ($runtimeUsername === '' || $migrationUsername === ''
            || $migrationUsername !== $configuredMigrationUsername
            || $runtimeUsername === $migrationUsername
            || ! is_string($applicationKey) || $applicationKey === '') {
            throw new RuntimeException('Nie skonfigurowano wymaganych danych do ochrony mutacji autoryzacyjnych.');
        }

        $runtimeRole = DB::selectOne('SELECT quote_ident(?) AS role_name', [$runtimeUsername])->role_name;
        $migrationRole = DB::selectOne('SELECT quote_ident(?) AS role_name', [$migrationUsername])->role_name;
        $roles = DB::select(<<<'SQL'
            SELECT roles.oid,
                roles.rolname,
                CASE WHEN roles.rolsuper THEN 1 ELSE 0 END AS is_superuser,
                CASE WHEN roles.rolcreatedb THEN 1 ELSE 0 END AS can_create_database,
                CASE WHEN roles.rolcreaterole THEN 1 ELSE 0 END AS can_create_roles,
                CASE WHEN roles.rolbypassrls THEN 1 ELSE 0 END AS bypasses_rls,
                CASE WHEN databases.datdba = roles.oid THEN 1 ELSE 0 END AS owns_database,
                CASE WHEN has_database_privilege(roles.rolname, databases.datname, 'CREATE') THEN 1 ELSE 0 END AS can_create_schema_objects,
                CASE WHEN EXISTS (
                    SELECT 1 FROM pg_auth_members memberships WHERE memberships.member = roles.oid
                ) THEN 1 ELSE 0 END AS has_role_membership
            FROM pg_roles roles
            CROSS JOIN pg_database databases
            WHERE roles.rolname IN (?, ?) AND databases.datname = current_database()
            SQL, [$runtimeUsername, $migrationUsername]);
        $runtimeRoleDetails = collect($roles)->firstWhere('rolname', $runtimeUsername);
        $migrationRoleDetails = collect($roles)->firstWhere('rolname', $migrationUsername);
        if (! $runtimeRoleDetails || ! $migrationRoleDetails
            || $runtimeRoleDetails->is_superuser || $runtimeRoleDetails->can_create_database
            || $runtimeRoleDetails->can_create_roles || $runtimeRoleDetails->bypasses_rls
            || $runtimeRoleDetails->owns_database || $runtimeRoleDetails->has_role_membership
            || $migrationRoleDetails->is_superuser || $migrationRoleDetails->can_create_database
            || $migrationRoleDetails->can_create_roles || $migrationRoleDetails->bypasses_rls
            || $migrationRoleDetails->owns_database || $migrationRoleDetails->has_role_membership
            || ! $migrationRoleDetails->can_create_schema_objects) {
            throw new RuntimeException('Role PostgreSQL nie spełniają wymagań least-privilege dla mutacji autoryzacyjnych.');
        }

        $databaseName = DB::connection()->getDatabaseName();
        $triggerTables = [
            'organizations',
            'locations',
            'location_management_assignments',
            'location_supply_relationships',
            'users',
            'location_memberships',
            'role_assignments',
            'security_events',
            'membership_requests',
            'permission_grants',
            'report_shares',
            'activation_tokens',
            'password_reset_tokens',
            'account_email_deliveries',
            'location_module_states',
            'raw_material_categories',
            'raw_material_code_counters',
            'raw_materials',
            'raw_material_revisions',
            'raw_material_revision_units',
            'raw_material_aliases',
            'raw_material_revision_approval_events',
            'raw_material_audit_events',
            'organization_permission_grants',
            'organization_permission_grant_events',
            'raw_material_permission_upgrade_operations',
            'raw_material_permission_grant_operation_uses',
            'organization_suppliers',
            'supplier_products',
            'supplier_product_revisions',
            'supplier_offers',
            'supplier_offer_events',
            'supplier_offer_fx_normalization_events',
            'organization_exchange_rate_events',
            'raw_material_reference_price_events',
            'raw_material_financial_restatements',
            'raw_materials_commercial_idempotency',
            'raw_materials_commercial_audit_events',
        ];
        $populatedTables = [];
        foreach ($triggerTables as $table) {
            if (DB::table($table)->exists()) {
                $populatedTables[] = $table;
            }
        }

        $expectedHistoryAttestation = 'REVIEWED:'.$databaseName.':2026_11_05_000022';
        $providedHistoryAttestation = (string) env('APP_DB_AUTHORIZATION_HISTORY_ATTESTATION', '');
        if ($populatedTables !== [] && ! hash_equals($expectedHistoryAttestation, $providedHistoryAttestation)) {
            throw new RuntimeException(
                'Baza zawiera dane sprzed migracji 022 w tabelach: '.implode(', ', $populatedTables)
                    .'. Wymagany jest przegląd historii i jednorazowe potwierdzenie APP_DB_AUTHORIZATION_HISTORY_ATTESTATION='.$expectedHistoryAttestation.'.',
            );
        }

        $historyAttestation = $populatedTables === [] ? 'NO_LEGACY_DATA' : $expectedHistoryAttestation;

        $signingKey = hash_hmac('sha256', 'veltra-db-authorization-ticket-v1', $applicationKey, true);
        $signingKeyHex = bin2hex($signingKey);
        $cryptoSchema = DB::selectOne(<<<'SQL'
            SELECT namespace.nspname
            FROM pg_extension extension
            JOIN pg_namespace namespace ON namespace.oid = extension.extnamespace
            WHERE extension.extname = 'pgcrypto'
            SQL);
        if (! $cryptoSchema) {
            DB::unprepared('CREATE EXTENSION pgcrypto WITH SCHEMA public');
            $cryptoSchema = (object) ['nspname' => 'public'];
        }
        $cryptoFunction = DB::selectOne('SELECT quote_ident(?) AS schema_name', [$cryptoSchema->nspname])->schema_name.'.hmac';

        DB::transaction(function () use (
            $runtimeRole,
            $migrationRole,
            $runtimeUsername,
            $migrationUsername,
            $signingKeyHex,
            $cryptoFunction,
            $databaseName,
            $historyAttestation,
            $populatedTables,
            $triggerTables,
        ): void {
            DB::unprepared(<<<'SQL'
                CREATE SCHEMA veltra_private;
                REVOKE ALL ON SCHEMA veltra_private FROM PUBLIC;
                CREATE TABLE veltra_private.authorization_history_attestations (
                    database_name name PRIMARY KEY,
                    migration_name text NOT NULL,
                    attestation text NOT NULL,
                    populated_tables jsonb NOT NULL,
                    attested_by name NOT NULL,
                    attested_at timestamptz NOT NULL DEFAULT clock_timestamp()
                );
                CREATE TABLE veltra_private.mutation_signing_keys (
                    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
                    signing_key bytea NOT NULL,
                    migration_role name NOT NULL,
                    runtime_role name NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
                );
                CREATE TABLE veltra_private.runtime_table_allowlist (
                    table_name name PRIMARY KEY
                );
                CREATE TABLE veltra_private.runtime_sequence_allowlist (
                    sequence_name name PRIMARY KEY
                );
                CREATE TABLE veltra_private.authorization_mutation_scopes (
                    table_name name NOT NULL,
                    operation text NOT NULL CHECK (operation IN ('INSERT', 'UPDATE', 'DELETE')),
                    scope_prefix text NOT NULL,
                    PRIMARY KEY (table_name, operation, scope_prefix)
                );
                REVOKE ALL ON ALL TABLES IN SCHEMA veltra_private FROM PUBLIC;
                SQL);

            DB::unprepared(sprintf(
                "INSERT INTO veltra_private.mutation_signing_keys (signing_key, migration_role, runtime_role) VALUES (decode('%s', 'hex'), %s, %s)",
                $signingKeyHex,
                DB::getPdo()->quote($migrationUsername),
                DB::getPdo()->quote($runtimeUsername),
            ));
            DB::table('veltra_private.authorization_history_attestations')->insert([
                'database_name' => $databaseName,
                'migration_name' => '2026_11_05_000022',
                'attestation' => $historyAttestation,
                'populated_tables' => json_encode($populatedTables, JSON_THROW_ON_ERROR),
                'attested_by' => $migrationUsername,
            ]);

            DB::unprepared(<<<'SQL'
                INSERT INTO veltra_private.runtime_table_allowlist (table_name)
                SELECT relation.relname
                FROM pg_class relation
                JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
                WHERE namespace.nspname = 'public'
                    AND relation.relkind IN ('r', 'p')
                    AND relation.relname NOT IN (
                        'migrations',
                        'raw_material_permission_grant_operation_uses',
                        'raw_materials_currency_codes'
                    );

                INSERT INTO veltra_private.runtime_sequence_allowlist (sequence_name)
                SELECT relation.relname
                FROM pg_class relation
                JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
                LEFT JOIN pg_depend dependency
                    ON dependency.classid = 'pg_class'::regclass
                    AND dependency.objid = relation.oid
                    AND dependency.refclassid = 'pg_class'::regclass
                    AND dependency.deptype = 'a'
                LEFT JOIN pg_class owning_table ON owning_table.oid = dependency.refobjid
                WHERE namespace.nspname = 'public' AND relation.relkind = 'S'
                    AND owning_table.relname IS DISTINCT FROM 'migrations';

                INSERT INTO veltra_private.authorization_mutation_scopes (table_name, operation, scope_prefix)
                SELECT rules.table_name, operations.operation, rules.scope_prefix
                FROM (VALUES
                    ('organizations', 'installation.'),
                    ('organizations', 'account.'),
                    ('organizations', 'administrator.bootstrap'),
                    ('locations', 'installation.'),
                    ('location_management_assignments', 'installation.'),
                    ('location_supply_relationships', 'installation.'),
                    ('users', 'identity.'),
                    ('users', 'account.'),
                    ('users', 'personnel.'),
                    ('users', 'membership.'),
                    ('users', 'role.'),
                    ('location_memberships', 'identity.'),
                    ('location_memberships', 'membership.'),
                    ('location_memberships', 'personnel.'),
                    ('role_assignments', 'administrator.bootstrap'),
                    ('role_assignments', 'identity.'),
                    ('role_assignments', 'membership.'),
                    ('role_assignments', 'personnel.'),
                    ('role_assignments', 'role.'),
                    ('membership_requests', 'membership.'),
                    ('membership_requests', 'personnel.'),
                    ('permission_grants', 'membership.'),
                    ('permission_grants', 'permission.'),
                    ('report_shares', 'report.'),
                    ('activation_tokens', 'account.'),
                    ('password_reset_tokens', 'account.'),
                    ('account_email_deliveries', 'account.'),
                    ('location_module_states', 'module.'),
                    ('security_events', 'security.event'),
                    ('security_events', 'module.audit'),
                    ('raw_material_categories', 'raw-material.mutate'),
                    ('raw_material_code_counters', 'raw-material.mutate'),
                    ('raw_materials', 'raw-material.mutate'),
                    ('raw_material_revisions', 'raw-material.mutate'),
                    ('raw_material_revision_units', 'raw-material.mutate'),
                    ('raw_material_aliases', 'raw-material.mutate'),
                    ('raw_material_revision_approval_events', 'raw-material.mutate'),
                    ('raw_material_audit_events', 'permission.'),
                    ('raw_material_audit_events', 'raw-material.mutate'),
                    ('raw_material_audit_events', 'identity.'),
                    ('raw_material_audit_events', 'membership.'),
                    ('organization_permission_grants', 'permission.'),
                    ('organization_permission_grant_events', 'account.'),
                    ('organization_permission_grant_events', 'identity.'),
                    ('organization_permission_grant_events', 'membership.'),
                    ('organization_permission_grant_events', 'permission.'),
                    ('raw_material_permission_upgrade_operations', 'permission.'),
                    ('raw_material_permission_grant_operation_uses', 'permission.'),
                    ('organization_suppliers', 'raw-material.commercial'),
                    ('supplier_products', 'raw-material.commercial'),
                    ('supplier_product_revisions', 'raw-material.commercial'),
                    ('supplier_offers', 'raw-material.commercial'),
                    ('supplier_offer_events', 'raw-material.commercial'),
                    ('supplier_offer_fx_normalization_events', 'raw-material.commercial'),
                    ('organization_exchange_rate_events', 'raw-material.commercial'),
                    ('raw_material_reference_price_events', 'raw-material.commercial'),
                    ('raw_material_financial_restatements', 'raw-material.commercial'),
                    ('raw_materials_commercial_idempotency', 'raw-material.commercial'),
                    ('raw_materials_commercial_audit_events', 'raw-material.commercial')
                ) AS rules(table_name, scope_prefix)
                CROSS JOIN (VALUES ('INSERT'), ('UPDATE'), ('DELETE')) AS operations(operation);
                SQL);

            DB::unprepared(sprintf(<<<'SQL'
                CREATE OR REPLACE FUNCTION veltra_private.enforce_authorization_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    ticket_text text;
                    signature_text text;
                    ticket jsonb;
                    signing_key bytea;
                    configured_migration_role name;
                BEGIN
                    SELECT keys.signing_key, keys.migration_role
                    INTO signing_key, configured_migration_role
                    FROM veltra_private.mutation_signing_keys keys
                    WHERE keys.singleton;

                    IF session_user = configured_migration_role
                        OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = session_user AND rolsuper) THEN
                        IF TG_OP = 'DELETE' THEN
                            RETURN OLD;
                        END IF;
                        RETURN NEW;
                    END IF;

                    IF TG_TABLE_SCHEMA <> 'public' THEN
                        RAISE EXCEPTION 'Protected mutation target is not trusted' USING ERRCODE = '42501';
                    END IF;

                    ticket_text := current_setting('veltra.authorization_payload', true);
                    signature_text := current_setting('veltra.authorization_signature', true);
                    IF ticket_text IS NULL OR ticket_text = '' OR signature_text IS NULL
                        OR signature_text !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'A signed authorization mutation ticket is required' USING ERRCODE = '42501';
                    END IF;

                    ticket := ticket_text::jsonb;
                    IF jsonb_typeof(ticket) IS DISTINCT FROM 'object'
                        OR %s(convert_to(ticket_text, 'UTF8'), signing_key, 'sha256')
                        <> decode(signature_text, 'hex')
                        OR ticket->>'transaction_id' IS DISTINCT FROM txid_current()::text
                        OR coalesce(ticket->>'expires_at', '') !~ '^[0-9]{1,12}$'
                        OR (ticket->>'expires_at')::bigint <= extract(epoch FROM clock_timestamp())::bigint
                        OR coalesce(ticket->>'scope', '') = ''
                        OR coalesce(ticket->>'nonce', '') !~* '^[0-9a-f-]{36}$'
                        OR (ticket->>'actor_id' IS NOT NULL AND ticket->>'actor_id' !~* '^[0-9a-f-]{36}$')
                        OR NOT EXISTS (
                            SELECT 1
                            FROM veltra_private.authorization_mutation_scopes scopes
                            WHERE scopes.table_name = TG_TABLE_NAME
                                AND scopes.operation = TG_OP
                                AND ticket->>'scope' LIKE scopes.scope_prefix || '%%'
                        ) THEN
                        RAISE EXCEPTION 'Authorization mutation ticket is invalid or out of scope' USING ERRCODE = '42501';
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END
                $function$;
                SQL, $cryptoFunction));

            foreach ($triggerTables as $table) {
                DB::unprepared(sprintf(
                    'CREATE TRIGGER %s_authorization_ticket BEFORE INSERT OR UPDATE OR DELETE ON public.%s FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation()',
                    $table,
                    $table,
                ));
            }

            DB::unprepared(sprintf(
                'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public REVOKE INSERT, UPDATE, DELETE ON TABLES FROM %s',
                $migrationRole,
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public REVOKE USAGE, SELECT, UPDATE ON SEQUENCES FROM %s',
                $migrationRole,
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON ALL TABLES IN SCHEMA public FROM PUBLIC, %s',
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'REVOKE CREATE ON SCHEMA public FROM PUBLIC, %s',
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'REVOKE USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public FROM PUBLIC, %s',
                $runtimeRole,
            ));
            $runtimeTables = DB::select('SELECT table_name FROM veltra_private.runtime_table_allowlist ORDER BY table_name');
            if ($runtimeTables !== []) {
                DB::unprepared(sprintf(
                    'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%s TO %s',
                    implode(', public.', array_map(static fn (object $table): string => $table->table_name, $runtimeTables)),
                    $runtimeRole,
                ));
            }
            DB::unprepared(sprintf(
                'GRANT SELECT ON TABLE public.raw_material_permission_grant_operation_uses TO %s',
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'GRANT SELECT ON TABLE public.raw_materials_currency_codes TO %s',
                $runtimeRole,
            ));
            $runtimeSequences = DB::select('SELECT sequence_name FROM veltra_private.runtime_sequence_allowlist ORDER BY sequence_name');
            if ($runtimeSequences !== []) {
                DB::unprepared(sprintf(
                    'GRANT USAGE, SELECT, UPDATE ON SEQUENCE public.%s TO %s',
                    implode(', public.', array_map(static fn (object $sequence): string => $sequence->sequence_name, $runtimeSequences)),
                    $runtimeRole,
                ));
            }

            DB::unprepared(sprintf(
                'REVOKE ALL ON SCHEMA veltra_private FROM PUBLIC, %s',
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'REVOKE ALL ON ALL TABLES IN SCHEMA veltra_private FROM PUBLIC, %s',
                $runtimeRole,
            ));
            DB::unprepared(sprintf(
                'REVOKE ALL ON FUNCTION veltra_private.enforce_authorization_mutation() FROM PUBLIC, %s',
                $runtimeRole,
            ));
            DB::unprepared(<<<'SQL'
                DO $block$
                DECLARE
                    privileged_function record;
                    configured_runtime_role name;
                BEGIN
                    SELECT runtime_role INTO configured_runtime_role
                    FROM veltra_private.mutation_signing_keys
                    WHERE singleton;
                    FOR privileged_function IN
                        SELECT functions.oid::regprocedure AS identity
                        FROM pg_proc functions
                        JOIN pg_namespace namespace ON namespace.oid = functions.pronamespace
                        WHERE functions.prosecdef
                            AND namespace.nspname IN ('public', 'veltra_private')
                    LOOP
                        EXECUTE format(
                            'REVOKE ALL ON FUNCTION %s FROM PUBLIC, %I',
                            privileged_function.identity,
                            configured_runtime_role
                        );
                    END LOOP;
                END
                $block$;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można wycofać ochrony podpisanych mutacji autoryzacyjnych.');
    }
};
