<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                ALTER TABLE public.raw_material_revisions
                    ADD COLUMN content_hash_version smallint NOT NULL DEFAULT 1
                        CHECK (content_hash_version IN (1, 2))
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TABLE public.raw_material_allergen_catalog_versions (
                    id uuid PRIMARY KEY,
                    version_number integer NOT NULL UNIQUE CHECK (version_number > 0),
                    status varchar(12) NOT NULL DEFAULT 'DRAFT'
                        CHECK (status IN ('DRAFT', 'PUBLISHED')),
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    published_at timestamptz,
                    UNIQUE (id, version_number),
                    CHECK ((status = 'DRAFT' AND published_at IS NULL)
                        OR (status = 'PUBLISHED' AND published_at IS NOT NULL))
                );

                CREATE TABLE public.raw_material_allergen_definitions (
                    catalog_version_id uuid NOT NULL
                        REFERENCES public.raw_material_allergen_catalog_versions(id) ON DELETE RESTRICT,
                    allergen_code varchar(40) NOT NULL CHECK (allergen_code ~ '^[A-Z][A-Z0-9_-]{0,39}$'),
                    name_pl varchar(160) NOT NULL CHECK (btrim(name_pl) <> ''),
                    display_order integer NOT NULL CHECK (display_order > 0),
                    is_active boolean NOT NULL DEFAULT true,
                    PRIMARY KEY (catalog_version_id, allergen_code),
                    UNIQUE (catalog_version_id, display_order)
                );

                CREATE TABLE public.raw_material_revision_allergen_attestations (
                    revision_id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    catalog_version_id uuid NOT NULL
                        REFERENCES public.raw_material_allergen_catalog_versions(id) ON DELETE RESTRICT,
                    attestation_status varchar(24) NOT NULL
                        CHECK (attestation_status IN ('DECLARED', 'NO_KNOWN_ALLERGENS')),
                    attested_by uuid NOT NULL,
                    attested_at timestamptz NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (revision_id, organization_id, catalog_version_id),
                    FOREIGN KEY (revision_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (attested_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_revision_allergen_declarations (
                    revision_id uuid NOT NULL,
                    organization_id uuid NOT NULL,
                    catalog_version_id uuid NOT NULL,
                    allergen_code varchar(40) NOT NULL,
                    exposure_kind varchar(32) NOT NULL
                        CHECK (exposure_kind IN ('DIRECT', 'CROSS_CONTACT_POTENTIAL')),
                    PRIMARY KEY (revision_id, allergen_code),
                    FOREIGN KEY (revision_id, organization_id, catalog_version_id)
                        REFERENCES public.raw_material_revision_allergen_attestations
                            (revision_id, organization_id, catalog_version_id) ON DELETE RESTRICT,
                    FOREIGN KEY (catalog_version_id, allergen_code)
                        REFERENCES public.raw_material_allergen_definitions
                            (catalog_version_id, allergen_code) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_revision_storage_variants (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    revision_id uuid NOT NULL,
                    variant_code varchar(40) NOT NULL CHECK (variant_code ~ '^[A-Z0-9_-]{1,40}$'),
                    name_pl varchar(160) NOT NULL CHECK (btrim(name_pl) <> ''),
                    storage_type varchar(32) NOT NULL CHECK (btrim(storage_type) <> ''),
                    temperature_min_c numeric(7, 2) NOT NULL,
                    temperature_max_c numeric(7, 2) NOT NULL,
                    humidity_min_percent numeric(5, 2),
                    humidity_max_percent numeric(5, 2),
                    conditions text,
                    is_default boolean NOT NULL DEFAULT false,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (revision_id, variant_code),
                    FOREIGN KEY (revision_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, organization_id) ON DELETE RESTRICT,
                    CHECK (temperature_min_c >= -273.15 AND temperature_max_c <= 1000),
                    CHECK (temperature_min_c <= temperature_max_c),
                    CHECK ((humidity_min_percent IS NULL) = (humidity_max_percent IS NULL)),
                    CHECK (humidity_min_percent IS NULL OR humidity_min_percent BETWEEN 0 AND 100),
                    CHECK (humidity_max_percent IS NULL OR humidity_max_percent BETWEEN 0 AND 100),
                    CHECK (humidity_min_percent IS NULL OR humidity_min_percent <= humidity_max_percent)
                );

                CREATE UNIQUE INDEX raw_material_revision_storage_one_default_idx
                    ON public.raw_material_revision_storage_variants(revision_id)
                    WHERE is_default;

                CREATE OR REPLACE FUNCTION public.raw_material_allergen_catalog_version_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.status <> 'DRAFT' OR NEW.published_at IS NOT NULL THEN
                            RAISE EXCEPTION 'Słownik alergenów musi powstać jako wersja robocza'
                                USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Wersji słownika alergenów nie można usuwać'
                            USING ERRCODE = '23514';
                    END IF;

                    IF OLD.status <> 'DRAFT' OR NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.version_number IS DISTINCT FROM OLD.version_number
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.status <> 'PUBLISHED' OR NEW.published_at IS NULL THEN
                        RAISE EXCEPTION 'Opublikowana wersja słownika jest niezmienna'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_allergen_catalog_version_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_allergen_catalog_versions
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_allergen_catalog_version_guard();

                CREATE OR REPLACE FUNCTION public.raw_material_allergen_definition_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Definicje słownika alergenów są niezmienne'
                            USING ERRCODE = '23514';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.raw_material_allergen_catalog_versions versions
                        WHERE versions.id = NEW.catalog_version_id AND versions.status = 'DRAFT'
                    ) THEN
                        RAISE EXCEPTION 'Definicję można dodać wyłącznie do wersji roboczej słownika'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_allergen_definition_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_allergen_definitions
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_allergen_definition_guard();

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

                    IF current_revision_version IS DISTINCT FROM 2 THEN
                        RAISE EXCEPTION 'Dane Stage 2D można dodać wyłącznie do bieżącej rewizji z hashem w wersji 2'
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

                CREATE TRIGGER raw_material_revision_allergen_attestations_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_allergen_attestations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_stage2d_data_guard();
                CREATE TRIGGER raw_material_revision_allergen_declarations_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_allergen_declarations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_stage2d_data_guard();
                CREATE TRIGGER raw_material_revision_storage_variants_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_storage_variants
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_stage2d_data_guard();

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
                    IF NOT FOUND OR revision_version <> 2 THEN
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

                CREATE CONSTRAINT TRIGGER raw_material_revisions_stage2d_consistency
                    AFTER INSERT ON public.raw_material_revisions
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_stage2d_consistency_validate();
                CREATE CONSTRAINT TRIGGER raw_material_allergen_attestations_stage2d_consistency
                    AFTER INSERT ON public.raw_material_revision_allergen_attestations
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_stage2d_consistency_validate();
                CREATE CONSTRAINT TRIGGER raw_material_allergen_declarations_stage2d_consistency
                    AFTER INSERT ON public.raw_material_revision_allergen_declarations
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_stage2d_consistency_validate();

                REVOKE ALL ON FUNCTION public.raw_material_allergen_catalog_version_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_allergen_definition_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_revision_stage2d_data_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_revision_stage2d_consistency_validate() FROM PUBLIC;
                SQL);

            DB::statement(<<<'SQL'
                GRANT SELECT ON public.raw_material_allergen_catalog_versions,
                    public.raw_material_allergen_definitions TO veltra_app
                SQL);
            DB::statement(<<<'SQL'
                GRANT SELECT, INSERT ON public.raw_material_revision_allergen_attestations,
                    public.raw_material_revision_allergen_declarations,
                    public.raw_material_revision_storage_variants TO veltra_app
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 028 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
