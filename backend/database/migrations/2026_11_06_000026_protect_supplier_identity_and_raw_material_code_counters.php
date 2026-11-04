<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertPrerequisitesAndHistoricalData();

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.raw_materials_commercial_supplier_preserve()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Suppliers cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.supplier_code IS DISTINCT FROM OLD.supplier_code
                        OR NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR NEW.legal_name IS DISTINCT FROM OLD.legal_name
                        OR NEW.tax_identifier_ciphertext IS DISTINCT FROM OLD.tax_identifier_ciphertext
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR (
                            NEW.updated_at IS DISTINCT FROM OLD.updated_at
                            AND NOT (OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state = 'ARCHIVED')
                        )
                        OR (OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state <> 'ARCHIVED') THEN
                        RAISE EXCEPTION 'Supplier identity, business details or archive history are immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE FUNCTION public.raw_material_code_counter_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Raw material code counters cannot be deleted'
                            USING ERRCODE = '23514';
                    END IF;

                    PERFORM 1
                    FROM public.organizations
                    WHERE id = NEW.organization_id
                    FOR UPDATE;

                    IF TG_OP = 'INSERT' THEN
                        IF NEW.last_value <> 0 THEN
                            RAISE EXCEPTION 'A raw material code counter must start at zero'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSIF NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.prefix IS DISTINCT FROM OLD.prefix
                        OR NEW.last_value < OLD.last_value
                        OR NEW.last_value > OLD.last_value + 1 THEN
                        RAISE EXCEPTION 'Raw material code counters must advance monotonically by one'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE FUNCTION public.raw_material_code_allocation_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    counter_value bigint;
                    code_value bigint;
                BEGIN
                    IF NEW.code !~ '^SUR-[0-9]{6,9}$' THEN
                        RAISE EXCEPTION 'Raw material codes must use the supported SUR sequence'
                            USING ERRCODE = '23514';
                    END IF;
                    code_value := substring(NEW.code FROM 5)::bigint;

                    PERFORM 1
                    FROM public.organizations
                    WHERE id = NEW.organization_id
                    FOR UPDATE;

                    SELECT counters.last_value INTO counter_value
                    FROM public.raw_material_code_counters counters
                    WHERE counters.organization_id = NEW.organization_id
                        AND counters.prefix = 'SUR'
                    FOR UPDATE;

                    IF counter_value IS NULL OR code_value <> counter_value THEN
                        RAISE EXCEPTION 'A raw material code must match its allocated counter value'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE FUNCTION public.raw_material_code_counter_consistency_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    counter_value bigint;
                    allocated_code text;
                BEGIN
                    SELECT counters.last_value INTO counter_value
                    FROM public.raw_material_code_counters counters
                    WHERE counters.organization_id = NEW.organization_id
                        AND counters.prefix = 'SUR';

                    IF counter_value IS NULL THEN
                        RAISE EXCEPTION 'A raw material code counter is required for every allocation'
                            USING ERRCODE = '23514';
                    END IF;

                    IF counter_value = 0 THEN
                        IF EXISTS (
                            SELECT 1
                            FROM public.raw_materials materials
                            WHERE materials.organization_id = NEW.organization_id
                        ) THEN
                            RAISE EXCEPTION 'A zero raw material counter cannot have allocated materials'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSE
                        allocated_code := 'SUR-' || CASE
                            WHEN length(counter_value::text) < 6
                                THEN lpad(counter_value::text, 6, '0')
                            ELSE counter_value::text
                        END;
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.raw_materials materials
                            WHERE materials.organization_id = NEW.organization_id
                                AND materials.code = allocated_code
                        ) THEN
                            RAISE EXCEPTION 'The final raw material counter value must have a matching allocation'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;

                    RETURN NULL;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER raw_material_code_counter_validate
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_code_counters
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_code_counter_validate();
                CREATE TRIGGER raw_materials_code_allocation_validate
                    BEFORE INSERT ON public.raw_materials
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_code_allocation_validate();
                CREATE CONSTRAINT TRIGGER raw_material_code_counter_consistency
                    AFTER INSERT OR UPDATE ON public.raw_material_code_counters
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_code_counter_consistency_validate();
                CREATE CONSTRAINT TRIGGER raw_materials_code_counter_consistency
                    AFTER INSERT ON public.raw_materials
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_code_counter_consistency_validate();
                REVOKE ALL ON FUNCTION public.raw_material_code_counter_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_code_allocation_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_code_counter_consistency_validate() FROM PUBLIC;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 026 jest nieodwracalna; powrót wymaga osobnej, przetestowanej migracji do przodu.');
    }

    private function assertPrerequisitesAndHistoricalData(): void
    {
        if (! DB::table('migrations')
            ->where('migration', '2026_11_05_000025_enforce_raw_material_business_integrity')
            ->exists()) {
            throw new RuntimeException('Migracja 026 wymaga wcześniej zastosowanej migracji 025.');
        }

        foreach ([
            'public.organizations',
            'public.organization_suppliers',
            'public.raw_materials',
            'public.raw_material_code_counters',
        ] as $relation) {
            if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$relation])) {
                throw new RuntimeException("Brak wymaganej relacji przed migracją 026: {$relation}.");
            }
        }

        $inconsistentAllocations = (int) DB::scalar(<<<'SQL'
            WITH material_state AS (
                SELECT
                    materials.organization_id,
                    max(substring(materials.code FROM 5)::numeric) AS max_code,
                    bool_or(
                        materials.code !~ '^SUR-[0-9]{6,9}$'
                        OR substring(materials.code FROM 5)::numeric < 1
                        OR substring(materials.code FROM 5)::numeric > 999999999
                    ) AS invalid_code
                FROM public.raw_materials materials
                GROUP BY materials.organization_id
            )
            SELECT count(*)
            FROM public.organizations organizations
            LEFT JOIN material_state
                ON material_state.organization_id = organizations.id
            LEFT JOIN public.raw_material_code_counters counters
                ON counters.organization_id = organizations.id
                AND counters.prefix = 'SUR'
            WHERE coalesce(material_state.invalid_code, false)
                OR (material_state.organization_id IS NOT NULL AND counters.organization_id IS NULL)
                OR (
                    counters.organization_id IS NOT NULL
                    AND counters.last_value::numeric IS DISTINCT FROM coalesce(material_state.max_code, 0)
                )
            SQL);
        if ($inconsistentAllocations !== 0) {
            throw new RuntimeException(
                "Migracja 026 przerwana: znaleziono {$inconsistentAllocations} organizacji z niespójnym licznikiem lub kodami RAW_MATERIALS. Dane wymagają przeglądu; migracja ich nie naprawia.",
            );
        }

        $reservedObjects = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_catalog.pg_trigger
            WHERE tgname IN (
                'raw_material_code_counter_validate',
                'raw_materials_code_allocation_validate',
                'raw_material_code_counter_consistency',
                'raw_materials_code_counter_consistency'
            )
            AND NOT tgisinternal
            SQL);
        if ($reservedObjects !== 0
            || DB::scalar("SELECT to_regprocedure('public.raw_material_code_counter_validate()') IS NOT NULL")
            || DB::scalar("SELECT to_regprocedure('public.raw_material_code_allocation_validate()') IS NOT NULL")
            || DB::scalar("SELECT to_regprocedure('public.raw_material_code_counter_consistency_validate()') IS NOT NULL")) {
            throw new RuntimeException('Wykryto nieoczekiwany obiekt o nazwie zarezerwowanej przez migrację 026.');
        }
    }
};
