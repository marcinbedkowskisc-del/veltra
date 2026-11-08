<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")
            || ! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")) {
            throw new RuntimeException('Migracja 037 wymaga istniejącej granicy podpisanych mutacji.');
        }

        DB::transaction(function (): void {
            foreach ([
                'raw_material_categories' => [
                    'INSERT' => ['raw-material.mutate'],
                    'UPDATE' => ['raw-material.mutate'],
                    'DELETE' => ['raw-material.mutate'],
                ],
                'raw_materials' => [
                    'INSERT' => ['raw-material.mutate'],
                    'UPDATE' => ['raw-material.mutate', 'raw-material.unblock'],
                    'DELETE' => ['raw-material.mutate'],
                ],
                'raw_material_revisions' => [
                    'INSERT' => ['raw-material.mutate'],
                    'UPDATE' => ['raw-material.mutate'],
                    'DELETE' => ['raw-material.mutate'],
                ],
                'raw_material_revision_units' => [
                    'INSERT' => ['raw-material.mutate'],
                    'UPDATE' => ['raw-material.mutate'],
                    'DELETE' => ['raw-material.mutate'],
                ],
                'raw_material_aliases' => [
                    'INSERT' => ['raw-material.mutate'],
                    'UPDATE' => ['raw-material.mutate'],
                    'DELETE' => ['raw-material.mutate'],
                ],
                'organization_suppliers' => [
                    'INSERT' => ['raw-material.commercial'],
                    'UPDATE' => ['raw-material.commercial', 'raw-material.supplier.correct'],
                    'DELETE' => ['raw-material.commercial'],
                ],
            ] as $table => $operations) {
                if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', ['public.'.$table])) {
                    throw new RuntimeException('Brak tabeli wymaganej przez migrację 037: '.$table.'.');
                }

                foreach ($operations as $operation => $scopes) {
                    foreach ($scopes as $scope) {
                        DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                            'table_name' => $table,
                            'operation' => $operation,
                            'scope_prefix' => $scope,
                        ]);
                    }
                }
            }

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER raw_material_categories_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_categories
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_materials_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_materials
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_material_revisions_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revisions
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_material_revision_units_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_units
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_material_aliases_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_aliases
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER organization_suppliers_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.organization_suppliers
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();

                CREATE OR REPLACE FUNCTION public.raw_materials_commercial_supplier_preserve()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                    actor_id uuid;
                    details_changed boolean;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Suppliers cannot be deleted' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.supplier_code IS DISTINCT FROM OLD.supplier_code
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Supplier identity is immutable' USING ERRCODE = '23514';
                    END IF;

                    details_changed := NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR NEW.legal_name IS DISTINCT FROM OLD.legal_name
                        OR NEW.tax_identifier_ciphertext IS DISTINCT FROM OLD.tax_identifier_ciphertext;

                    IF details_changed THEN
                        IF OLD.lifecycle_state <> 'ACTIVE'
                            OR NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state THEN
                            RAISE EXCEPTION 'Only active supplier details can be corrected'
                                USING ERRCODE = '23514';
                        END IF;

                        ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                        IF ticket->>'scope' IS DISTINCT FROM 'raw-material.supplier.correct'
                            OR ticket->>'subject_id' IS DISTINCT FROM OLD.id::text
                            OR coalesce(ticket->>'actor_id', '') !~* '^[0-9a-f-]{36}$' THEN
                            RAISE EXCEPTION 'Supplier correction requires a signed, supplier-bound actor'
                                USING ERRCODE = '42501';
                        END IF;

                        actor_id := (ticket->>'actor_id')::uuid;
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.users users
                            JOIN public.organizations organizations
                                ON organizations.id = users.organization_id
                            WHERE users.id = actor_id
                                AND users.organization_id = OLD.organization_id
                                AND users.is_active
                                AND users.email_verified_at IS NOT NULL
                                AND organizations.is_active
                        ) THEN
                            RAISE EXCEPTION 'Supplier correction actor is not active in this organization'
                                USING ERRCODE = '42501';
                        END IF;
                    ELSIF NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state THEN
                        IF OLD.lifecycle_state <> 'ACTIVE' OR NEW.lifecycle_state <> 'ARCHIVED' THEN
                            RAISE EXCEPTION 'Supplier archive history is immutable'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSIF NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
                        RAISE EXCEPTION 'Supplier timestamps cannot be changed independently'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$;

                REVOKE ALL ON FUNCTION public.raw_materials_commercial_supplier_preserve()
                    FROM PUBLIC, veltra_app;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 037 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
