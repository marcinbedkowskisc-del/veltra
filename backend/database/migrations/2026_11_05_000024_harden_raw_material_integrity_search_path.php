<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROTECTED_TABLES = [
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
        'raw_material_revision_approval_events',
        'raw_material_audit_events',
        'supplier_product_revisions',
        'supplier_offer_events',
        'supplier_offer_fx_normalization_events',
        'organization_exchange_rate_events',
        'raw_material_reference_price_events',
        'raw_material_financial_restatements',
        'raw_materials_commercial_idempotency',
        'raw_materials_commercial_audit_events',
        'organization_permission_grants',
        'organization_permission_grant_events',
        'raw_material_permission_upgrade_operations',
        'raw_material_permission_grant_operation_uses',
    ];

    private const UNPROTECTED_TABLES = [
        'organization_suppliers',
        'supplier_products',
        'supplier_offers',
        'raw_material_categories',
        'raw_material_code_counters',
        'raw_materials',
        'raw_material_revisions',
        'raw_material_revision_units',
        'raw_material_aliases',
    ];

    private const INTEGRITY_TABLES = [
        ...self::UNPROTECTED_TABLES,
        'raw_material_revision_approval_events',
        'raw_material_audit_events',
        'supplier_product_revisions',
        'supplier_offer_events',
        'supplier_offer_fx_normalization_events',
        'organization_exchange_rate_events',
        'raw_material_reference_price_events',
        'raw_material_financial_restatements',
        'raw_materials_commercial_idempotency',
        'raw_materials_commercial_audit_events',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertPrerequisites();
            $this->hardenRoleAssignmentMutationScopes();

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION veltra_private.validate_role_assignment_ticket()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $$
                DECLARE
                    ticket jsonb;
                    expected_actor text;
                    expected_scope text;
                    configured_migration_role name;
                BEGIN
                    SELECT keys.migration_role INTO configured_migration_role
                    FROM veltra_private.mutation_signing_keys keys
                    WHERE keys.singleton;
                    IF session_user = configured_migration_role
                        OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = session_user AND rolsuper) THEN
                        RETURN NEW;
                    END IF;

                    ticket := current_setting('veltra.authorization_payload', true)::jsonb;
                    IF TG_OP = 'INSERT' THEN
                        IF ticket->>'scope' = 'administrator.bootstrap' THEN
                            expected_actor := NEW.user_id::text;
                            expected_scope := 'administrator.bootstrap';
                        ELSE
                            expected_actor := NEW.granted_by::text;
                            expected_scope := 'role.grant.' || NEW.role;
                        END IF;
                    ELSE
                        expected_actor := NEW.revoked_by::text;
                        expected_scope := CASE
                            WHEN ticket->>'scope' = 'membership.remove' THEN 'membership.remove'
                            ELSE 'role.revoke.' || OLD.role
                        END;
                    END IF;

                    IF ticket->>'scope' IS DISTINCT FROM expected_scope
                        OR ticket->>'actor_id' IS DISTINCT FROM expected_actor THEN
                        RAISE EXCEPTION 'Role assignment does not match the signed actor and operation'
                            USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END
                $$;

                REVOKE ALL ON FUNCTION veltra_private.validate_role_assignment_ticket() FROM PUBLIC;
                CREATE TRIGGER role_assignments_ticket_semantics
                    BEFORE INSERT OR UPDATE ON public.role_assignments
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.validate_role_assignment_ticket();

                CREATE OR REPLACE FUNCTION public.raw_materials_preserve_master()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Raw materials cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.code IS DISTINCT FROM OLD.code OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Raw material identity and code are immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.approved_revision_id IS DISTINCT FROM OLD.approved_revision_id AND (
                        NEW.approved_revision_id IS NULL
                        OR NEW.approved_revision_id IS DISTINCT FROM OLD.pending_revision_id
                        OR NOT EXISTS (
                            SELECT 1
                            FROM public.raw_material_revision_approval_events approvals
                            JOIN public.raw_material_revisions revisions
                                ON revisions.id = approvals.revision_id
                            WHERE approvals.organization_id = NEW.organization_id
                                AND approvals.revision_id = NEW.approved_revision_id
                                AND approvals.outcome = 'APPROVED'
                                AND approvals.content_hash = revisions.content_hash
                        )
                    ) THEN
                        RAISE EXCEPTION 'An approved revision requires a matching approval event' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.pending_revision_id IS NOT NULL
                        AND NEW.pending_revision_id IS DISTINCT FROM OLD.pending_revision_id
                        AND NEW.pending_revision_id IS DISTINCT FROM OLD.working_revision_id THEN
                        RAISE EXCEPTION 'A pending revision must be the current working revision' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.working_revision_id IS NOT NULL
                        AND NEW.working_revision_id IS DISTINCT FROM OLD.working_revision_id
                        AND NEW.working_revision_id IS DISTINCT FROM OLD.pending_revision_id
                        AND EXISTS (
                            SELECT 1
                            FROM public.raw_material_revisions
                            WHERE id = NEW.working_revision_id
                        ) THEN
                        RAISE EXCEPTION 'A working revision cannot reuse a historical revision' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state AND NOT (
                        (OLD.lifecycle_state = 'DRAFT' AND NEW.lifecycle_state = 'VERIFICATION')
                        OR (OLD.lifecycle_state = 'VERIFICATION' AND NEW.lifecycle_state IN ('DRAFT', 'ACTIVE'))
                        OR (OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state IN ('BLOCKED', 'ARCHIVED'))
                        OR (OLD.lifecycle_state = 'BLOCKED' AND NEW.lifecycle_state IN ('ACTIVE', 'ARCHIVED'))
                        OR (OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state = 'VERIFICATION')
                    ) THEN
                        RAISE EXCEPTION 'Raw material lifecycle transition is invalid' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $$;
                SQL);

            $functions = DB::select(
                <<<'SQL'
                    SELECT DISTINCT
                        format(
                            'ALTER FUNCTION %s SET search_path TO pg_catalog, public, pg_temp',
                            functions.oid::regprocedure
                        ) AS statement
                    FROM pg_trigger triggers
                    JOIN pg_class relations ON relations.oid = triggers.tgrelid
                    JOIN pg_namespace relation_schemas ON relation_schemas.oid = relations.relnamespace
                    JOIN pg_proc functions ON functions.oid = triggers.tgfoid
                    JOIN pg_namespace function_schemas ON function_schemas.oid = functions.pronamespace
                    WHERE relation_schemas.nspname = 'public'
                        AND function_schemas.nspname = 'public'
                        AND relations.relname = ANY (?::name[])
                        AND NOT triggers.tgisinternal
                    SQL,
                ['{'.implode(',', self::INTEGRITY_TABLES).'}'],
            );

            if ($functions === []) {
                throw new RuntimeException('Nie znaleziono funkcji triggerów integralności do zabezpieczenia.');
            }

            foreach ($functions as $function) {
                DB::unprepared($function->statement);
            }

            $unsecuredFunctionCount = (int) DB::scalar(
                <<<'SQL'
                    SELECT count(DISTINCT functions.oid)
                    FROM pg_trigger triggers
                    JOIN pg_class relations ON relations.oid = triggers.tgrelid
                    JOIN pg_namespace relation_schemas ON relation_schemas.oid = relations.relnamespace
                    JOIN pg_proc functions ON functions.oid = triggers.tgfoid
                    JOIN pg_namespace function_schemas ON function_schemas.oid = functions.pronamespace
                    WHERE relation_schemas.nspname = 'public'
                        AND function_schemas.nspname = 'public'
                        AND relations.relname = ANY (?::name[])
                        AND NOT triggers.tgisinternal
                        AND NOT coalesce(
                            functions.proconfig @> ARRAY['search_path=pg_catalog, public, pg_temp'],
                            false
                        )
                    SQL,
                ['{'.implode(',', self::INTEGRITY_TABLES).'}'],
            );

            if ($unsecuredFunctionCount !== 0) {
                throw new RuntimeException('Nie wszystkie funkcje triggerów integralności mają zaufany search_path.');
            }
        });
    }

    private function hardenRoleAssignmentMutationScopes(): void
    {
        DB::table('veltra_private.authorization_mutation_scopes')
            ->where('table_name', 'role_assignments')
            ->delete();

        $scopes = [
            ['table_name' => 'role_assignments', 'operation' => 'INSERT', 'scope_prefix' => 'administrator.bootstrap'],
            ['table_name' => 'role_assignments', 'operation' => 'UPDATE', 'scope_prefix' => 'membership.remove'],
        ];

        foreach ([
            'ADMINISTRATOR',
            'CENTRAL_KITCHEN_CHEF',
            'BOSS',
            'RESTAURANT_MANAGER',
            'RESTAURANT_CHEF',
            'EMPLOYEE',
        ] as $role) {
            $scopes[] = [
                'table_name' => 'role_assignments',
                'operation' => 'INSERT',
                'scope_prefix' => 'role.grant.'.$role,
            ];
            $scopes[] = [
                'table_name' => 'role_assignments',
                'operation' => 'UPDATE',
                'scope_prefix' => 'role.revoke.'.$role,
            ];
        }

        DB::table('veltra_private.authorization_mutation_scopes')->insert($scopes);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 024 jest nieodwracalna; powrót wymaga osobnej, przetestowanej migracji do przodu.');
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'veltra_private.mutation_signing_keys',
            'veltra_private.authorization_mutation_scopes',
            'public.raw_materials',
            'public.raw_material_revisions',
            'public.raw_material_revision_approval_events',
        ] as $relation) {
            if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$relation])) {
                throw new RuntimeException("Brak wymaganej relacji migracji 022/023: {$relation}.");
            }
        }

        foreach ([
            '2026_11_05_000022_enforce_signed_authorization_mutations',
            '2026_11_05_000023_narrow_authorization_mutation_boundary',
        ] as $migration) {
            if (! DB::table('migrations')->where('migration', $migration)->exists()) {
                throw new RuntimeException("Brak wymaganej migracji poprzedzającej: {$migration}.");
            }
        }

        $keyCount = (int) DB::scalar(
            'SELECT count(*) FROM veltra_private.mutation_signing_keys WHERE singleton AND octet_length(signing_key) = 32',
        );
        if ($keyCount !== 1) {
            throw new RuntimeException('Wymagany jest dokładnie jeden poprawny klucz mutacji w schemacie prywatnym.');
        }

        $protectedTriggerCount = (int) DB::scalar(
            <<<'SQL'
                SELECT count(DISTINCT relations.oid)
                FROM pg_trigger triggers
                JOIN pg_class relations ON relations.oid = triggers.tgrelid
                JOIN pg_namespace relation_schemas ON relation_schemas.oid = relations.relnamespace
                JOIN pg_proc functions ON functions.oid = triggers.tgfoid
                JOIN pg_namespace function_schemas ON function_schemas.oid = functions.pronamespace
                WHERE relation_schemas.nspname = 'public'
                    AND function_schemas.nspname = 'veltra_private'
                    AND functions.proname = 'enforce_authorization_mutation'
                    AND relations.relname = ANY (?::name[])
                    AND NOT triggers.tgisinternal
                    AND triggers.tgenabled <> 'D'
                SQL,
            ['{'.implode(',', self::PROTECTED_TABLES).'}'],
        );
        if ($protectedTriggerCount !== count(self::PROTECTED_TABLES)) {
            throw new RuntimeException('Brakuje aktywnych triggerów ochronnych dla tabel RBAC/audytu z migracji 023.');
        }

        $unexpectedProtectedTriggerCount = (int) DB::scalar(
            <<<'SQL'
                SELECT count(*)
                FROM pg_trigger triggers
                JOIN pg_class relations ON relations.oid = triggers.tgrelid
                JOIN pg_namespace relation_schemas ON relation_schemas.oid = relations.relnamespace
                JOIN pg_proc functions ON functions.oid = triggers.tgfoid
                JOIN pg_namespace function_schemas ON function_schemas.oid = functions.pronamespace
                WHERE relation_schemas.nspname = 'public'
                    AND function_schemas.nspname = 'veltra_private'
                    AND functions.proname = 'enforce_authorization_mutation'
                    AND relations.relname = ANY (?::name[])
                    AND NOT triggers.tgisinternal
                SQL,
            ['{'.implode(',', self::UNPROTECTED_TABLES).'}'],
        );
        if ($unexpectedProtectedTriggerCount !== 0) {
            throw new RuntimeException('Migracja 023 nie usunęła wszystkich ogólnych triggerów spoza zatwierdzonego zakresu.');
        }

        foreach (self::INTEGRITY_TABLES as $table) {
            if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', ['public.'.$table])) {
                throw new RuntimeException("Brak wymaganej tabeli domeny integralności: public.{$table}.");
            }
        }

        $approvalTriggerCount = (int) DB::scalar(
            <<<'SQL'
                SELECT count(*)
                FROM pg_trigger triggers
                JOIN pg_class relations ON relations.oid = triggers.tgrelid
                JOIN pg_namespace relation_schemas ON relation_schemas.oid = relations.relnamespace
                JOIN pg_proc functions ON functions.oid = triggers.tgfoid
                JOIN pg_namespace function_schemas ON function_schemas.oid = functions.pronamespace
                WHERE relation_schemas.nspname = 'public'
                    AND relations.relname = 'raw_materials'
                    AND functions.proname = 'raw_materials_preserve_master'
                    AND function_schemas.nspname = 'public'
                    AND NOT triggers.tgisinternal
                    AND triggers.tgenabled <> 'D'
                SQL,
        );
        if ($approvalTriggerCount !== 1) {
            throw new RuntimeException('Brak aktywnego walidatora zatwierdzenia materiału z migracji 011.');
        }
    }
};
