<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")
            || ! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")) {
            throw new RuntimeException('Brak zaufanego mechanizmu podpisanych mutacji wymagany przez migrację 033.');
        }

        foreach ([
            'raw_material_revision_allergen_attestations',
            'raw_material_revision_allergen_declarations',
            'raw_material_revision_storage_variants',
        ] as $table) {
            if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', ['public.'.$table])) {
                throw new RuntimeException('Brak tabeli Stage 2D wymaganej przez migrację 033: '.$table.'.');
            }
        }

        DB::transaction(function (): void {
            DB::table('veltra_private.authorization_mutation_scopes')
                ->whereIn('table_name', [
                    'raw_material_revision_allergen_attestations',
                    'raw_material_revision_allergen_declarations',
                    'raw_material_revision_storage_variants',
                ])
                ->where('operation', 'INSERT')
                ->where('scope_prefix', 'raw-material.mutate')
                ->delete();

            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                [
                    'table_name' => 'raw_material_revision_allergen_attestations',
                    'operation' => 'INSERT',
                    'scope_prefix' => 'raw-material.allergen.manage',
                ],
                [
                    'table_name' => 'raw_material_revision_allergen_declarations',
                    'operation' => 'INSERT',
                    'scope_prefix' => 'raw-material.allergen.manage',
                ],
                [
                    'table_name' => 'raw_material_revision_storage_variants',
                    'operation' => 'INSERT',
                    'scope_prefix' => 'raw-material.storage.manage',
                ],
            ]);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION veltra_private.enforce_stage2d_revision_mutation_subject()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    configured_migration_role name;
                    ticket_text text;
                    ticket jsonb;
                    expected_scope text;
                    actor_id uuid;
                BEGIN
                    SELECT migration_role INTO configured_migration_role
                    FROM veltra_private.mutation_signing_keys
                    WHERE singleton;

                    IF session_user = configured_migration_role
                        OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = session_user AND rolsuper) THEN
                        RETURN NEW;
                    END IF;

                    ticket_text := current_setting('veltra.authorization_payload', true);
                    IF ticket_text IS NULL OR ticket_text = '' THEN
                        RAISE EXCEPTION 'A signed Stage 2D authorization ticket is required'
                            USING ERRCODE = '42501';
                    END IF;
                    BEGIN
                        ticket := ticket_text::jsonb;
                    EXCEPTION WHEN invalid_text_representation THEN
                        RAISE EXCEPTION 'The Stage 2D authorization ticket is invalid'
                            USING ERRCODE = '42501';
                    END;

                    expected_scope := CASE TG_TABLE_NAME
                        WHEN 'raw_material_revision_allergen_attestations' THEN 'raw-material.allergen.manage'
                        WHEN 'raw_material_revision_allergen_declarations' THEN 'raw-material.allergen.manage'
                        WHEN 'raw_material_revision_storage_variants' THEN 'raw-material.storage.manage'
                        ELSE NULL
                    END;
                    IF expected_scope IS NULL
                        OR ticket->>'scope' IS DISTINCT FROM expected_scope
                        OR ticket->>'subject_id' IS DISTINCT FROM NEW.organization_id::text
                        OR coalesce(ticket->>'actor_id', '') !~* '^[0-9a-f-]{36}$' THEN
                        RAISE EXCEPTION 'The Stage 2D authorization ticket is out of scope'
                            USING ERRCODE = '42501';
                    END IF;

                    actor_id := (ticket->>'actor_id')::uuid;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM public.users users
                        JOIN public.organizations organizations
                            ON organizations.id = users.organization_id
                        WHERE users.id = actor_id
                            AND users.organization_id = NEW.organization_id
                            AND users.is_active
                            AND users.email_verified_at IS NOT NULL
                            AND organizations.is_active
                    ) THEN
                        RAISE EXCEPTION 'The Stage 2D actor does not belong to the authorized organization'
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NEW;
                END
                $function$;

                REVOKE ALL ON FUNCTION veltra_private.enforce_stage2d_revision_mutation_subject()
                    FROM PUBLIC, veltra_app;

                CREATE TRIGGER raw_material_revision_allergen_attestations_authorization_subject
                    BEFORE INSERT ON public.raw_material_revision_allergen_attestations
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_stage2d_revision_mutation_subject();
                CREATE TRIGGER raw_material_revision_allergen_attestations_authorization_ticket
                    BEFORE INSERT ON public.raw_material_revision_allergen_attestations
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();

                CREATE TRIGGER raw_material_revision_allergen_declarations_authorization_subject
                    BEFORE INSERT ON public.raw_material_revision_allergen_declarations
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_stage2d_revision_mutation_subject();
                CREATE TRIGGER raw_material_revision_allergen_declarations_authorization_ticket
                    BEFORE INSERT ON public.raw_material_revision_allergen_declarations
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();

                CREATE TRIGGER raw_material_revision_storage_variants_authorization_subject
                    BEFORE INSERT ON public.raw_material_revision_storage_variants
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_stage2d_revision_mutation_subject();
                CREATE TRIGGER raw_material_revision_storage_variants_authorization_ticket
                    BEFORE INSERT ON public.raw_material_revision_storage_variants
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 033 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
