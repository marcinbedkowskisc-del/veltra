<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! DB::scalar("SELECT to_regclass('public.raw_material_code_counters') IS NOT NULL")
                || ! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")) {
                throw new RuntimeException('Migracja SKU wymaga istniejącego licznika i granicy podpisanych mutacji.');
            }

            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS raw_material_code_counter_validate ON public.raw_material_code_counters;
                DROP TRIGGER IF EXISTS raw_material_code_counter_consistency ON public.raw_material_code_counters;
                DROP TRIGGER IF EXISTS raw_materials_code_allocation_validate ON public.raw_materials;
                DROP TRIGGER IF EXISTS raw_materials_code_counter_consistency ON public.raw_materials;
                DROP FUNCTION IF EXISTS public.raw_material_code_counter_validate();
                DROP FUNCTION IF EXISTS public.raw_material_code_allocation_validate();
                DROP FUNCTION IF EXISTS public.raw_material_code_counter_consistency_validate();

                ALTER TABLE public.raw_material_code_counters
                    DROP CONSTRAINT raw_material_code_counters_prefix_check;
                ALTER TABLE public.raw_material_code_counters
                    RENAME TO organization_sku_counters;
                ALTER TABLE public.organization_sku_counters
                    ADD CONSTRAINT organization_sku_counters_prefix_check
                        CHECK (prefix IN ('SUR', 'RAW', 'INT', 'PRD', 'MEN'));

                ALTER TABLE public.raw_materials
                    DROP CONSTRAINT raw_materials_code_check,
                    ADD CONSTRAINT raw_materials_code_check
                        CHECK (code ~ '^(SUR|RAW)-[0-9]{6,9}$');
                ALTER TABLE public.raw_material_import_rows
                    DROP CONSTRAINT raw_material_import_rows_ingredient_code_check,
                    ADD CONSTRAINT raw_material_import_rows_ingredient_code_check
                        CHECK (ingredient_code ~ '^(SUR|RAW)-[0-9]{6,9}$');

                CREATE TABLE public.organization_sku_assignments (
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    sku varchar(16) NOT NULL,
                    prefix varchar(3) NOT NULL CHECK (prefix IN ('SUR', 'RAW', 'INT', 'PRD', 'MEN')),
                    sequence_number bigint NOT NULL CHECK (sequence_number BETWEEN 1 AND 999999999),
                    entity_type varchar(3) NOT NULL CHECK (entity_type IN ('RAW', 'INT', 'PRD', 'MEN')),
                    entity_id uuid NOT NULL,
                    allocation_source varchar(10) NOT NULL
                        CHECK (allocation_source IN ('GENERATED', 'IMPORTED', 'LEGACY')),
                    assigned_by uuid NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    PRIMARY KEY (organization_id, sku),
                    UNIQUE (organization_id, prefix, sequence_number),
                    UNIQUE (organization_id, entity_type, entity_id),
                    FOREIGN KEY (assigned_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    CHECK (
                        (prefix = 'SUR' AND entity_type = 'RAW')
                        OR prefix = entity_type
                    ),
                    CHECK (
                        sku = prefix || '-' || CASE
                            WHEN length(sequence_number::text) < 6
                                THEN lpad(sequence_number::text, 6, '0')
                            ELSE sequence_number::text
                        END
                    )
                );

                INSERT INTO public.organization_sku_assignments (
                    organization_id, sku, prefix, sequence_number, entity_type,
                    entity_id, allocation_source, assigned_by, created_at
                )
                SELECT materials.organization_id, materials.code, 'SUR',
                    substring(materials.code FROM 5)::bigint, 'RAW',
                    materials.id, 'LEGACY', materials.created_by, materials.created_at
                FROM public.raw_materials materials;

                INSERT INTO public.organization_sku_counters (organization_id, prefix, last_value)
                SELECT organizations.id, prefixes.prefix,
                    CASE WHEN prefixes.prefix = 'SUR' THEN coalesce(counters.last_value, 0) ELSE 0 END
                FROM public.organizations organizations
                CROSS JOIN (VALUES ('SUR'), ('RAW'), ('INT'), ('PRD'), ('MEN')) AS prefixes(prefix)
                LEFT JOIN public.organization_sku_counters counters
                    ON counters.organization_id = organizations.id AND counters.prefix = prefixes.prefix
                ON CONFLICT (organization_id, prefix) DO NOTHING;

                UPDATE veltra_private.runtime_table_allowlist
                SET table_name = 'organization_sku_counters'
                WHERE table_name = 'raw_material_code_counters';
                UPDATE veltra_private.authorization_mutation_scopes
                SET table_name = 'organization_sku_counters'
                WHERE table_name = 'raw_material_code_counters';

                DELETE FROM veltra_private.authorization_mutation_scopes
                WHERE table_name = 'organization_sku_counters';
                INSERT INTO veltra_private.authorization_mutation_scopes (table_name, operation, scope_prefix)
                VALUES
                    ('organization_sku_counters', 'INSERT', 'sku.allocate'),
                    ('organization_sku_counters', 'UPDATE', 'sku.allocate'),
                    ('organization_sku_assignments', 'INSERT', 'sku.allocate');

                INSERT INTO veltra_private.runtime_table_allowlist (table_name)
                VALUES ('organization_sku_assignments');

                CREATE FUNCTION public.organization_sku_counter_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'SKU counters cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.last_value <> 0 THEN
                            RAISE EXCEPTION 'New SKU counters must start at zero' USING ERRCODE = '23514';
                        END IF;
                    ELSIF NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.prefix IS DISTINCT FROM OLD.prefix
                        OR NEW.last_value <= OLD.last_value THEN
                        RAISE EXCEPTION 'SKU counters are immutable and may only advance' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE FUNCTION public.organization_sku_counter_consistency_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    current_counter bigint;
                    highest_reserved bigint;
                BEGIN
                    SELECT counters.last_value
                    INTO current_counter
                    FROM public.organization_sku_counters counters
                    WHERE counters.organization_id = NEW.organization_id
                        AND counters.prefix = NEW.prefix;
                    SELECT coalesce(max(assignments.sequence_number), 0)
                    INTO highest_reserved
                    FROM public.organization_sku_assignments assignments
                    WHERE assignments.organization_id = NEW.organization_id
                        AND assignments.prefix = NEW.prefix;
                    IF current_counter IS NULL OR highest_reserved IS DISTINCT FROM current_counter THEN
                        RAISE EXCEPTION 'SKU counter must equal the highest assigned or reserved number'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;

                CREATE FUNCTION public.organization_sku_assignment_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                    migration_role name;
                BEGIN
                    SELECT keys.migration_role INTO migration_role
                    FROM veltra_private.mutation_signing_keys keys WHERE keys.singleton;
                    IF session_user <> migration_role THEN
                        ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                        IF ticket->>'scope' IS DISTINCT FROM 'sku.allocate'
                            OR ticket->>'subject_id' IS DISTINCT FROM NEW.entity_id::text
                            OR ticket->>'actor_id' IS DISTINCT FROM NEW.assigned_by::text
                            OR NOT EXISTS (
                                SELECT 1
                                FROM public.users users
                                JOIN public.organizations organizations ON organizations.id = users.organization_id
                                WHERE users.id = NEW.assigned_by
                                    AND users.organization_id = NEW.organization_id
                                    AND users.is_active
                                    AND users.email_verified_at IS NOT NULL
                                    AND organizations.is_active
                            ) THEN
                            RAISE EXCEPTION 'SKU assignment requires a signed, tenant-bound actor and subject'
                                USING ERRCODE = '42501';
                        END IF;
                    END IF;
                    IF NEW.prefix = 'SUR' AND session_user <> migration_role THEN
                        RAISE EXCEPTION 'New SUR identifiers are not allowed; use RAW'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE FUNCTION public.organization_sku_assignment_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    RAISE EXCEPTION 'SKU assignments are immutable and append-only' USING ERRCODE = '23514';
                END
                $function$;

                CREATE FUNCTION public.raw_material_sku_allocation_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    migration_role name;
                BEGIN
                    SELECT keys.migration_role INTO migration_role
                    FROM veltra_private.mutation_signing_keys keys WHERE keys.singleton;
                    IF NEW.code LIKE 'SUR-%' THEN
                        IF session_user <> migration_role THEN
                            RAISE EXCEPTION 'New SUR identifiers are not allowed; use RAW' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.organization_sku_assignments assignments
                        WHERE assignments.organization_id = NEW.organization_id
                            AND assignments.sku = NEW.code
                            AND assignments.entity_type = 'RAW'
                            AND assignments.entity_id = NEW.id
                    ) THEN
                        RAISE EXCEPTION 'A RAW material must use its signed SKU assignment'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER organization_sku_counter_validate
                    BEFORE INSERT OR UPDATE OR DELETE ON public.organization_sku_counters
                    FOR EACH ROW EXECUTE FUNCTION public.organization_sku_counter_validate();
                CREATE CONSTRAINT TRIGGER organization_sku_counter_consistency
                    AFTER INSERT OR UPDATE ON public.organization_sku_counters
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.organization_sku_counter_consistency_validate();
                CREATE CONSTRAINT TRIGGER organization_sku_assignment_counter_consistency
                    AFTER INSERT ON public.organization_sku_assignments
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.organization_sku_counter_consistency_validate();
                CREATE TRIGGER organization_sku_assignment_validate
                    BEFORE INSERT ON public.organization_sku_assignments
                    FOR EACH ROW EXECUTE FUNCTION public.organization_sku_assignment_validate();
                CREATE TRIGGER organization_sku_assignment_immutable
                    BEFORE UPDATE OR DELETE ON public.organization_sku_assignments
                    FOR EACH ROW EXECUTE FUNCTION public.organization_sku_assignment_immutable();
                CREATE TRIGGER raw_material_sku_allocation_validate
                    BEFORE INSERT ON public.raw_materials
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_sku_allocation_validate();

                CREATE TRIGGER organization_sku_counters_mutation
                    BEFORE INSERT OR UPDATE ON public.organization_sku_counters
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER organization_sku_assignments_mutation
                    BEFORE INSERT ON public.organization_sku_assignments
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();

                REVOKE ALL ON FUNCTION public.organization_sku_counter_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.organization_sku_counter_consistency_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.organization_sku_assignment_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.organization_sku_assignment_immutable() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_sku_allocation_validate() FROM PUBLIC;
                SQL);

            $runtimeRole = (string) env('APP_DB_USERNAME', 'veltra_app');
            $quotedRuntimeRole = DB::selectOne('SELECT quote_ident(?) AS role_name', [$runtimeRole])->role_name;
            DB::unprepared(sprintf(
                'REVOKE ALL ON TABLE public.organization_sku_assignments FROM PUBLIC, %s; GRANT SELECT, INSERT ON TABLE public.organization_sku_assignments TO %s; REVOKE DELETE, TRUNCATE ON TABLE public.organization_sku_counters FROM PUBLIC, %s;',
                $quotedRuntimeRole,
                $quotedRuntimeRole,
                $quotedRuntimeRole,
            ));
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 035 jest forward-only; cofnięcie wymaga osobnej, przetestowanej migracji.');
    }
};
