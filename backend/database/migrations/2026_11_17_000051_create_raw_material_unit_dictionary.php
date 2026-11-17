<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.raw_material_units (
                id uuid PRIMARY KEY,
                code varchar(24) NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]{0,23}$'),
                name varchar(100) NOT NULL CHECK (btrim(name) <> ''),
                symbol varchar(24) NOT NULL CHECK (btrim(symbol) <> ''),
                dimension varchar(12) NOT NULL CHECK (dimension IN ('MASS', 'VOLUME', 'COUNT')),
                base_unit_code varchar(24) NOT NULL,
                to_base_factor numeric(24,12) NOT NULL CHECK (to_base_factor > 0),
                archived_at timestamptz,
                archived_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                created_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (code, dimension),
                FOREIGN KEY (base_unit_code, dimension)
                    REFERENCES public.raw_material_units(code, dimension) ON DELETE RESTRICT
                    DEFERRABLE INITIALLY DEFERRED,
                CHECK ((code = base_unit_code AND to_base_factor = 1) OR code <> base_unit_code),
                CHECK ((archived_at IS NULL) = (archived_by IS NULL))
            );
            INSERT INTO public.raw_material_units
                (id, code, name, symbol, dimension, base_unit_code, to_base_factor)
            VALUES
                ('00000000-0000-4000-8000-000000000101', 'mg', 'miligram', 'mg', 'MASS', 'g', 0.001),
                ('00000000-0000-4000-8000-000000000102', 'g', 'gram', 'g', 'MASS', 'g', 1),
                ('00000000-0000-4000-8000-000000000103', 'kg', 'kilogram', 'kg', 'MASS', 'g', 1000),
                ('00000000-0000-4000-8000-000000000104', 'ml', 'mililitr', 'ml', 'VOLUME', 'ml', 1),
                ('00000000-0000-4000-8000-000000000105', 'l', 'litr', 'l', 'VOLUME', 'ml', 1000),
                ('00000000-0000-4000-8000-000000000106', 'pc', 'sztuka', 'szt.', 'COUNT', 'pc', 1);

            ALTER TABLE public.raw_material_revisions
                DROP CONSTRAINT IF EXISTS raw_material_revisions_base_unit_code_check,
                ADD CONSTRAINT raw_material_revisions_unit_definition_fk
                    FOREIGN KEY (base_unit_code, base_unit_dimension)
                    REFERENCES public.raw_material_units(code, dimension) ON DELETE RESTRICT;
            ALTER TABLE public.raw_material_revision_units
                DROP CONSTRAINT IF EXISTS raw_material_revision_units_unit_code_check,
                ADD CONSTRAINT raw_material_revision_units_definition_fk
                    FOREIGN KEY (unit_code, dimension)
                    REFERENCES public.raw_material_units(code, dimension) ON DELETE RESTRICT;
            ALTER TABLE public.raw_material_catalog_import_rows
                DROP CONSTRAINT IF EXISTS raw_material_catalog_import_rows_base_unit_code_check,
                ADD CONSTRAINT raw_material_catalog_import_rows_unit_fk
                    FOREIGN KEY (base_unit_code)
                    REFERENCES public.raw_material_units(code) ON DELETE RESTRICT;
            ALTER TABLE public.supplier_product_revisions
                DROP CONSTRAINT IF EXISTS supplier_product_revisions_content_unit_code_check,
                ADD CONSTRAINT supplier_product_revisions_content_unit_fk
                    FOREIGN KEY (content_unit_code)
                    REFERENCES public.raw_material_units(code) ON DELETE RESTRICT;

            ALTER TABLE public.raw_material_revisions
                DROP CONSTRAINT IF EXISTS raw_revision_pricing_input_check,
                ADD CONSTRAINT raw_revision_pricing_input_check CHECK (
                    (pricing_input_basis IS NULL AND pricing_input_amount IS NULL
                        AND pricing_input_unit_code IS NULL)
                    OR (pricing_input_basis = 'PACKAGE' AND pricing_input_amount IS NOT NULL
                        AND pricing_input_amount >= 0 AND pricing_input_unit_code IS NULL)
                    OR (pricing_input_basis IN ('NOMINAL_UNIT', 'LEGACY_BASE_UNIT')
                        AND pricing_input_amount IS NOT NULL AND pricing_input_amount >= 0
                        AND pricing_input_unit_code IS NOT NULL)
                ),
                ADD CONSTRAINT raw_revision_pricing_unit_fk
                    FOREIGN KEY (pricing_input_unit_code)
                    REFERENCES public.raw_material_units(code) ON DELETE RESTRICT;
            ALTER TABLE public.raw_material_reference_price_events
                DROP CONSTRAINT IF EXISTS raw_reference_price_input_check,
                ADD CONSTRAINT raw_reference_price_input_check CHECK (
                    (pricing_input_basis IS NULL AND pricing_input_amount IS NULL
                        AND pricing_input_unit_code IS NULL AND pricing_nominal_quantity IS NULL
                        AND pricing_usable_quantity IS NULL AND pricing_revision_id IS NULL)
                    OR (event_type = 'PRICE_SET' AND pricing_input_basis = 'PACKAGE'
                        AND pricing_input_amount IS NOT NULL AND pricing_input_amount >= 0
                        AND pricing_input_unit_code IS NULL AND pricing_nominal_quantity IS NOT NULL
                        AND pricing_nominal_quantity > 0 AND pricing_usable_quantity IS NOT NULL
                        AND pricing_usable_quantity > 0 AND pricing_revision_id IS NOT NULL)
                    OR (event_type = 'PRICE_SET'
                        AND pricing_input_basis IN ('NOMINAL_UNIT', 'LEGACY_BASE_UNIT')
                        AND pricing_input_amount IS NOT NULL AND pricing_input_amount >= 0
                        AND pricing_input_unit_code IS NOT NULL AND pricing_nominal_quantity IS NOT NULL
                        AND pricing_nominal_quantity > 0 AND pricing_usable_quantity IS NOT NULL
                        AND pricing_usable_quantity > 0 AND pricing_revision_id IS NOT NULL)
                ),
                ADD CONSTRAINT raw_reference_price_unit_fk
                    FOREIGN KEY (pricing_input_unit_code)
                    REFERENCES public.raw_material_units(code) ON DELETE RESTRICT;

            CREATE OR REPLACE FUNCTION public.raw_material_unit_definition_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Raw material unit definitions cannot be deleted'
                        USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.code <> OLD.code THEN
                        RAISE EXCEPTION 'Raw material unit codes are immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    IF (NEW.dimension, NEW.base_unit_code, NEW.to_base_factor)
                        IS DISTINCT FROM (OLD.dimension, OLD.base_unit_code, OLD.to_base_factor)
                        AND (
                            EXISTS (SELECT 1 FROM public.raw_material_revisions WHERE base_unit_code = OLD.code)
                            OR EXISTS (SELECT 1 FROM public.raw_material_revision_units WHERE unit_code = OLD.code)
                            OR EXISTS (SELECT 1 FROM public.raw_material_catalog_import_rows WHERE base_unit_code = OLD.code)
                            OR EXISTS (SELECT 1 FROM public.supplier_product_revisions WHERE content_unit_code = OLD.code)
                            OR EXISTS (SELECT 1 FROM public.raw_material_reference_price_events WHERE pricing_input_unit_code = OLD.code)
                        ) THEN
                        RAISE EXCEPTION 'A unit definition referenced by historical data cannot change its meaning'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.code = NEW.base_unit_code AND NEW.to_base_factor <> 1 THEN
                    RAISE EXCEPTION 'A dimension base unit must have a factor of one'
                        USING ERRCODE = '23514';
                END IF;
                IF NEW.code <> NEW.base_unit_code AND NOT EXISTS (
                    SELECT 1 FROM public.raw_material_units bases
                    WHERE bases.code = NEW.base_unit_code AND bases.dimension = NEW.dimension
                        AND bases.code = bases.base_unit_code AND bases.to_base_factor = 1
                        AND bases.archived_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'A unit must use an active canonical base unit of the same dimension'
                        USING ERRCODE = '23514';
                END IF;
                IF NEW.archived_at IS NOT NULL AND NEW.code = NEW.base_unit_code THEN
                    RAISE EXCEPTION 'A canonical base unit cannot be archived'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $function$;
            CREATE TRIGGER raw_material_units_definition_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_units
                FOR EACH ROW EXECUTE FUNCTION public.raw_material_unit_definition_guard();

            CREATE OR REPLACE FUNCTION public.raw_materials_child_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            DECLARE revision_key uuid;
            DECLARE material_key uuid;
            DECLARE organization_key uuid;
            DECLARE base_unit text;
            DECLARE base_dimension text;
            DECLARE unit_dimension text;
            DECLARE unit_factor numeric;
            DECLARE base_factor numeric;
            DECLARE unit_archived_at timestamptz;
            BEGIN
                IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Raw material revision data is immutable' USING ERRCODE = '23514';
                END IF;
                revision_key := NEW.revision_id;
                organization_key := NEW.organization_id;
                IF TG_TABLE_NAME = 'raw_material_aliases' THEN
                    material_key := NEW.raw_material_id;
                ELSE
                    SELECT raw_material_id, base_unit_code, base_unit_dimension
                        INTO material_key, base_unit, base_dimension
                        FROM public.raw_material_revisions WHERE id = revision_key;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Raw material revision is invalid' USING ERRCODE = '23514';
                    END IF;
                    SELECT dimension, to_base_factor, archived_at
                        INTO unit_dimension, unit_factor, unit_archived_at
                        FROM public.raw_material_units WHERE code = NEW.unit_code;
                    IF NOT FOUND OR unit_dimension <> NEW.dimension THEN
                        RAISE EXCEPTION 'Raw material unit is invalid' USING ERRCODE = '23514';
                    END IF;
                    IF unit_archived_at IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM public.raw_material_revisions current_revision
                        JOIN public.raw_material_revisions previous_revision
                            ON previous_revision.raw_material_id = current_revision.raw_material_id
                            AND previous_revision.revision_no = current_revision.revision_no - 1
                        WHERE current_revision.id = revision_key
                            AND (previous_revision.base_unit_code = NEW.unit_code
                                OR EXISTS (
                                    SELECT 1 FROM public.raw_material_revision_units previous_unit
                                    WHERE previous_unit.revision_id = previous_revision.id
                                        AND previous_unit.unit_code = NEW.unit_code
                                ))
                    ) THEN
                        RAISE EXCEPTION 'Archived units cannot be assigned to new raw material revisions'
                            USING ERRCODE = '23514';
                    END IF;
                    IF (NEW.conversion_kind = 'BASE' AND
                            (NEW.unit_code <> base_unit OR NEW.dimension <> base_dimension OR NEW.to_base_factor <> 1))
                        OR (NEW.conversion_kind = 'METRIC' AND NEW.dimension <> base_dimension)
                        OR (NEW.conversion_kind = 'MATERIAL_SPECIFIC' AND
                            (NEW.dimension = base_dimension OR NEW.basis IS NULL OR btrim(NEW.basis) = '')) THEN
                        RAISE EXCEPTION 'Raw material conversion is invalid' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.conversion_kind = 'METRIC' THEN
                        SELECT to_base_factor INTO base_factor
                            FROM public.raw_material_units WHERE code = base_unit;
                        IF NEW.to_base_factor <> round(unit_factor / base_factor, 12) THEN
                            RAISE EXCEPTION 'Metric conversion factor is invalid' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.raw_materials
                    WHERE id = material_key AND organization_id = organization_key
                        AND working_revision_id = revision_key
                ) THEN
                    RAISE EXCEPTION 'Revision is closed for changes' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $function$;
            SQL);

        DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore([
            'table_name' => 'raw_material_units',
        ]);
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                'table_name' => 'raw_material_units',
                'operation' => $operation,
                'scope_prefix' => 'raw-material.mutate',
            ]);
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER raw_material_units_authorization_ticket
                BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_units
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
            GRANT SELECT, INSERT, UPDATE ON public.raw_material_units TO veltra_app;
            REVOKE ALL ON FUNCTION public.raw_material_unit_definition_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.raw_materials_child_immutable() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 051 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
