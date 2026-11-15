<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.raw_material_revisions
                    ADD COLUMN usable_quantity_explicit boolean NOT NULL DEFAULT false,
                    ADD COLUMN pricing_input_basis varchar(20),
                    ADD COLUMN pricing_input_amount numeric(31,14),
                    ADD COLUMN pricing_input_unit_code varchar(24),
                    ADD CONSTRAINT raw_revision_pricing_input_check CHECK (
                        (pricing_input_basis IS NULL AND pricing_input_amount IS NULL
                            AND pricing_input_unit_code IS NULL)
                        OR (pricing_input_basis = 'PACKAGE' AND pricing_input_amount IS NOT NULL
                            AND pricing_input_amount >= 0
                            AND pricing_input_unit_code IS NULL)
                        OR (pricing_input_basis = 'NOMINAL_UNIT' AND pricing_input_amount IS NOT NULL
                            AND pricing_input_amount >= 0 AND pricing_input_unit_code IS NOT NULL
                            AND pricing_input_unit_code IN ('kg', 'l', 'pc'))
                        OR (pricing_input_basis = 'LEGACY_BASE_UNIT' AND pricing_input_amount IS NOT NULL
                            AND pricing_input_amount >= 0 AND pricing_input_unit_code IS NOT NULL
                            AND pricing_input_unit_code IN ('mg', 'g', 'kg', 'ml', 'l', 'pc'))
                    ),
                    ADD CONSTRAINT raw_revision_explicit_usable_check CHECK (
                        NOT usable_quantity_explicit OR
                        (nominal_quantity IS NOT NULL AND usable_quantity IS NOT NULL)
                    ),
                    ADD CONSTRAINT raw_revision_usable_not_above_nominal_check CHECK (
                        nominal_quantity IS NULL OR usable_quantity <= nominal_quantity
                    ) NOT VALID;
                ALTER TABLE public.raw_material_revisions
                    DISABLE TRIGGER raw_material_revisions_immutable;
                UPDATE public.raw_material_revisions
                SET usable_quantity_explicit = true
                WHERE nominal_quantity IS NOT NULL
                    AND usable_quantity IS NOT NULL
                    AND usable_quantity <> nominal_quantity;
                ALTER TABLE public.raw_material_revisions
                    ENABLE TRIGGER raw_material_revisions_immutable;

                ALTER TABLE public.raw_material_revisions
                    DROP CONSTRAINT raw_material_revisions_content_hash_version_check,
                    ADD CONSTRAINT raw_material_revisions_content_hash_version_check
                        CHECK (content_hash_version IN (1, 2, 3));

                ALTER TABLE public.raw_material_catalog_import_rows
                    ADD COLUMN nominal_quantity numeric(24,6),
                    ADD COLUMN usable_quantity numeric(24,6),
                    ADD COLUMN usable_quantity_explicit boolean NOT NULL DEFAULT false,
                    ADD CONSTRAINT raw_catalog_import_row_quantities_check CHECK (
                        (nominal_quantity IS NULL AND usable_quantity IS NULL
                            AND NOT usable_quantity_explicit)
                        OR (nominal_quantity IS NOT NULL AND usable_quantity IS NOT NULL
                            AND nominal_quantity > 0 AND usable_quantity > 0
                            AND usable_quantity <= nominal_quantity
                            AND (base_unit_code <> 'pc' OR
                                (nominal_quantity = trunc(nominal_quantity)
                                    AND usable_quantity = trunc(usable_quantity))))
                    );

                ALTER TABLE public.raw_material_reference_price_events
                    ADD COLUMN pricing_input_basis varchar(20),
                    ADD COLUMN pricing_input_amount numeric(31,14),
                    ADD COLUMN pricing_input_unit_code varchar(24),
                    ADD COLUMN pricing_nominal_quantity numeric(24,6),
                    ADD COLUMN pricing_usable_quantity numeric(24,6),
                    ADD COLUMN pricing_revision_id uuid,
                    ADD CONSTRAINT raw_reference_price_revision_fk
                        FOREIGN KEY (pricing_revision_id, raw_material_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, raw_material_id, organization_id)
                        ON DELETE RESTRICT,
                    ADD CONSTRAINT raw_reference_price_input_check CHECK (
                        (pricing_input_basis IS NULL AND pricing_input_amount IS NULL
                            AND pricing_input_unit_code IS NULL AND pricing_nominal_quantity IS NULL
                            AND pricing_usable_quantity IS NULL AND pricing_revision_id IS NULL)
                        OR (event_type = 'PRICE_SET'
                            AND pricing_input_basis = 'PACKAGE'
                            AND pricing_input_amount IS NOT NULL
                            AND pricing_input_amount >= 0
                            AND pricing_input_unit_code IS NULL
                            AND pricing_nominal_quantity IS NOT NULL AND pricing_nominal_quantity > 0
                            AND pricing_usable_quantity IS NOT NULL AND pricing_usable_quantity > 0
                            AND pricing_revision_id IS NOT NULL)
                        OR (event_type = 'PRICE_SET'
                            AND pricing_input_basis = 'NOMINAL_UNIT'
                            AND pricing_input_amount IS NOT NULL AND pricing_input_amount >= 0
                            AND pricing_input_unit_code IS NOT NULL
                            AND pricing_input_unit_code IN ('kg', 'l', 'pc')
                            AND pricing_nominal_quantity IS NOT NULL AND pricing_nominal_quantity > 0
                            AND pricing_usable_quantity IS NOT NULL AND pricing_usable_quantity > 0
                            AND pricing_revision_id IS NOT NULL)
                        OR (event_type = 'PRICE_SET'
                            AND pricing_input_basis = 'LEGACY_BASE_UNIT'
                            AND pricing_input_amount IS NOT NULL AND pricing_input_amount >= 0
                            AND pricing_input_unit_code IS NOT NULL
                            AND pricing_input_unit_code IN ('mg', 'g', 'kg', 'ml', 'l', 'pc')
                            AND pricing_nominal_quantity IS NOT NULL AND pricing_nominal_quantity > 0
                            AND pricing_usable_quantity IS NOT NULL AND pricing_usable_quantity > 0
                            AND pricing_revision_id IS NOT NULL)
                    );

                CREATE FUNCTION public.raw_reference_price_input_validate() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    revision_row public.raw_material_revisions%ROWTYPE;
                    unit_factor numeric;
                    expected_base numeric;
                    expected_package numeric;
                BEGIN
                    IF NEW.pricing_input_basis IS NULL THEN
                        RETURN NEW;
                    END IF;
                    SELECT * INTO revision_row
                    FROM public.raw_material_revisions
                    WHERE id = NEW.pricing_revision_id
                        AND raw_material_id = NEW.raw_material_id
                        AND organization_id = NEW.organization_id;
                    IF revision_row.id IS NULL
                        OR revision_row.nominal_quantity IS NULL
                        OR revision_row.usable_quantity IS NULL
                        OR revision_row.nominal_quantity <> NEW.pricing_nominal_quantity
                        OR revision_row.usable_quantity <> NEW.pricing_usable_quantity THEN
                        RAISE EXCEPTION 'Reference price quantity snapshot does not match its revision'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NEW.pricing_input_basis = 'PACKAGE' THEN
                        expected_base := raw_materials_round_half_even_v1(
                            NEW.pricing_input_amount / revision_row.nominal_quantity, 14);
                        expected_package := raw_materials_round_half_even_v1(NEW.pricing_input_amount, 14);
                    ELSIF NEW.pricing_input_basis = 'LEGACY_BASE_UNIT' THEN
                        IF NEW.pricing_input_unit_code <> revision_row.base_unit_code THEN
                            RAISE EXCEPTION 'Legacy price unit does not match the revision base unit'
                                USING ERRCODE = '23514';
                        END IF;
                        expected_base := raw_materials_round_half_even_v1(NEW.pricing_input_amount, 14);
                        expected_package := raw_materials_round_half_even_v1(
                            NEW.pricing_input_amount * revision_row.nominal_quantity, 14);
                    ELSE
                        SELECT units.to_base_factor INTO unit_factor
                        FROM public.raw_material_revision_units units
                        WHERE units.revision_id = revision_row.id
                            AND units.organization_id = revision_row.organization_id
                            AND units.unit_code = NEW.pricing_input_unit_code
                            AND units.conversion_kind IN ('BASE', 'METRIC')
                            AND units.dimension = revision_row.base_unit_dimension;
                        IF unit_factor IS NULL THEN
                            RAISE EXCEPTION 'Nominal price unit is not approved for the revision'
                                USING ERRCODE = '23514';
                        END IF;
                        expected_base := raw_materials_round_half_even_v1(
                            NEW.pricing_input_amount / unit_factor, 14);
                        expected_package := raw_materials_round_half_even_v1(
                            revision_row.nominal_quantity / unit_factor * NEW.pricing_input_amount, 14);
                    END IF;

                    IF NEW.net_pln_per_base_unit <> expected_base
                        OR NEW.net_pln_per_package <> expected_package THEN
                        RAISE EXCEPTION 'Reference price does not match exact normalized calculation'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_reference_price_input_validate
                    BEFORE INSERT ON public.raw_material_reference_price_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_reference_price_input_validate();
                REVOKE ALL ON FUNCTION public.raw_reference_price_input_validate() FROM PUBLIC;

                CREATE TABLE public.raw_material_supplier_associations (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    raw_material_id uuid NOT NULL,
                    supplier_id uuid NOT NULL,
                    created_by uuid NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (organization_id, raw_material_id, supplier_id),
                    FOREIGN KEY (raw_material_id, organization_id)
                        REFERENCES public.raw_materials(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (supplier_id, organization_id)
                        REFERENCES public.organization_suppliers(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (created_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );
                CREATE INDEX raw_material_supplier_associations_material_idx
                    ON public.raw_material_supplier_associations(organization_id, raw_material_id);
                CREATE FUNCTION public.raw_material_supplier_association_validate() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM public.organization_suppliers
                        WHERE id = NEW.supplier_id AND organization_id = NEW.organization_id
                            AND lifecycle_state = 'ACTIVE'
                    ) THEN
                        RAISE EXCEPTION 'Supplier associations require an active supplier'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_supplier_association_validate
                    BEFORE INSERT ON public.raw_material_supplier_associations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_supplier_association_validate();
                REVOKE ALL ON FUNCTION public.raw_material_supplier_association_validate() FROM PUBLIC;
                INSERT INTO veltra_private.runtime_table_allowlist(table_name)
                    VALUES ('raw_material_supplier_associations');
                INSERT INTO veltra_private.authorization_mutation_scopes(table_name, operation, scope_prefix)
                    VALUES ('raw_material_supplier_associations', 'INSERT', 'raw-material.commercial'),
                           ('raw_material_supplier_associations', 'DELETE', 'raw-material.commercial');
                CREATE TRIGGER raw_material_supplier_associations_authorization_ticket
                    BEFORE INSERT OR DELETE ON public.raw_material_supplier_associations
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                GRANT SELECT, INSERT, DELETE ON public.raw_material_supplier_associations TO veltra_app;

                CREATE OR REPLACE FUNCTION public.raw_material_revision_stage2d_data_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    revision_key uuid;
                    organization_key uuid;
                    current_revision_version smallint;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Dane rewizji surowca są niezmienne'
                            USING ERRCODE = '23514';
                    END IF;

                    revision_key := NEW.revision_id;
                    organization_key := NEW.organization_id;
                    SELECT revisions.content_hash_version INTO current_revision_version
                    FROM public.raw_material_revisions revisions
                    JOIN public.raw_materials materials
                        ON materials.id = revisions.raw_material_id
                        AND materials.organization_id = revisions.organization_id
                    WHERE revisions.id = revision_key
                        AND revisions.organization_id = organization_key
                        AND materials.working_revision_id = revisions.id;

                    IF current_revision_version IS NULL OR current_revision_version NOT IN (2, 3) THEN
                        RAISE EXCEPTION 'Dane alergii i przechowywania wymagają bieżącej rewizji z hashem w wersji 2 lub 3'
                            USING ERRCODE = '23514';
                    END IF;

                    IF TG_TABLE_NAME = 'raw_material_revision_allergen_attestations' THEN
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.raw_material_revisions revisions
                            WHERE revisions.id = NEW.revision_id
                                AND revisions.organization_id = NEW.organization_id
                                AND revisions.allergen_state = 'DECLARED'
                        ) OR NOT EXISTS (
                            SELECT 1
                            FROM public.raw_material_allergen_catalog_versions versions
                            WHERE versions.id = NEW.catalog_version_id
                                AND versions.status = 'PUBLISHED'
                        ) THEN
                            RAISE EXCEPTION 'Atest wymaga deklarowanej rewizji i opublikowanej wersji słownika'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'raw_material_revision_allergen_declarations' THEN
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.raw_material_revision_allergen_attestations attestations
                            JOIN public.raw_material_allergen_definitions definitions
                                ON definitions.catalog_version_id = attestations.catalog_version_id
                                AND definitions.allergen_code = NEW.allergen_code
                            WHERE attestations.revision_id = NEW.revision_id
                                AND attestations.organization_id = NEW.organization_id
                                AND attestations.catalog_version_id = NEW.catalog_version_id
                                AND attestations.attestation_status = 'DECLARED'
                                AND definitions.is_active
                        ) THEN
                            RAISE EXCEPTION 'Deklaracja wymaga aktywnego alergenu w wersji słownika wskazanej przez atest'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;

                    RETURN NEW;
                END
                $function$;

                CREATE OR REPLACE FUNCTION public.raw_material_revision_stage2d_consistency_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    revision_key uuid;
                    revision_version smallint;
                    allergen_state_value varchar(16);
                    attestation_status_value varchar(24);
                    declaration_count bigint;
                BEGIN
                    IF TG_TABLE_NAME = 'raw_material_revisions' THEN
                        revision_key := NEW.id;
                    ELSE
                        revision_key := NEW.revision_id;
                    END IF;

                    SELECT revisions.content_hash_version, revisions.allergen_state
                        INTO revision_version, allergen_state_value
                    FROM public.raw_material_revisions revisions
                    WHERE revisions.id = revision_key;
                    IF NOT FOUND OR revision_version NOT IN (2, 3) THEN
                        RETURN NULL;
                    END IF;

                    SELECT attestations.attestation_status
                        INTO attestation_status_value
                    FROM public.raw_material_revision_allergen_attestations attestations
                    WHERE attestations.revision_id = revision_key;

                    SELECT count(*) INTO declaration_count
                    FROM public.raw_material_revision_allergen_declarations declarations
                    WHERE declarations.revision_id = revision_key;

                    IF allergen_state_value = 'UNKNOWN' THEN
                        IF attestation_status_value IS NOT NULL OR declaration_count <> 0 THEN
                            RAISE EXCEPTION 'UNKNOWN nie może zawierać atestu ani deklaracji alergenów'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSIF attestation_status_value = 'DECLARED' AND declaration_count = 0 THEN
                        RAISE EXCEPTION 'Atest deklaracji wymaga co najmniej jednego alergenu'
                            USING ERRCODE = '23514';
                    ELSIF attestation_status_value = 'NO_KNOWN_ALLERGENS' AND declaration_count <> 0 THEN
                        RAISE EXCEPTION 'Atest braku znanych alergenów nie może zawierać deklaracji alergenów'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;
                REVOKE ALL ON FUNCTION public.raw_material_revision_stage2d_data_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_revision_stage2d_consistency_validate() FROM PUBLIC;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 049 jest forward-only; nie wolno usuwać historii cen ani informacji o ilościach.');
    }
};
