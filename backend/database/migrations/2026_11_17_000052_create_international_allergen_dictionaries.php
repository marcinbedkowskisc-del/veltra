<?php

use Database\Seeders\AllergenDictionariesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                CREATE TABLE public.international_allergen_sources (
                    source_code varchar(16) PRIMARY KEY
                        CHECK (source_code ~ '^[A-Z][A-Z0-9_]{0,15}$'),
                    jurisdiction varchar(16) NOT NULL,
                    name_pl varchar(120) NOT NULL CHECK (btrim(name_pl) <> ''),
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
                );

                CREATE TABLE public.international_allergen_dictionary_versions (
                    id uuid PRIMARY KEY,
                    source_code varchar(16) NOT NULL
                        REFERENCES public.international_allergen_sources(source_code) ON DELETE RESTRICT,
                    code varchar(64) NOT NULL UNIQUE CHECK (code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
                    authority varchar(180) NOT NULL CHECK (btrim(authority) <> ''),
                    status varchar(24) NOT NULL
                        CHECK (status IN ('ACTIVE', 'ACTIVE_STANDARD', 'FUTURE', 'HISTORICAL')),
                    official_url text,
                    effective_from date,
                    effective_until date,
                    last_verified_on date,
                    last_manual_edit_at timestamptz,
                    last_manual_edit_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                    seed_imported_at timestamptz NOT NULL,
                    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    CHECK ((last_manual_edit_at IS NULL) = (last_manual_edit_by IS NULL))
                );
                CREATE INDEX international_allergen_dictionary_versions_source_idx
                    ON public.international_allergen_dictionary_versions(source_code, effective_from);

                CREATE TABLE public.international_allergen_canonical_definitions (
                    id varchar(40) PRIMARY KEY CHECK (id ~ '^[A-Z][A-Z0-9_]{0,39}$'),
                    parent_id varchar(40)
                        REFERENCES public.international_allergen_canonical_definitions(id) ON DELETE RESTRICT,
                    canonical_pl varchar(160) NOT NULL CHECK (btrim(canonical_pl) <> ''),
                    synonyms_pl jsonb NOT NULL DEFAULT '[]'::jsonb
                        CHECK (jsonb_typeof(synonyms_pl) = 'array'),
                    original_names jsonb NOT NULL DEFAULT '{}'::jsonb
                        CHECK (jsonb_typeof(original_names) = 'object'),
                    record_status varchar(12) NOT NULL DEFAULT 'ACTIVE'
                        CHECK (record_status IN ('ACTIVE', 'ARCHIVED')),
                    archived_at timestamptz,
                    archived_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                    last_manual_edit_at timestamptz,
                    last_manual_edit_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    CHECK ((record_status = 'ACTIVE' AND archived_at IS NULL AND archived_by IS NULL)
                        OR (record_status = 'ARCHIVED' AND archived_at IS NOT NULL AND archived_by IS NOT NULL)),
                    CHECK ((last_manual_edit_at IS NULL) = (last_manual_edit_by IS NULL))
                );
                CREATE INDEX international_allergen_canonical_name_idx
                    ON public.international_allergen_canonical_definitions(canonical_pl);

                CREATE TABLE public.international_allergen_source_entries (
                    id uuid PRIMARY KEY,
                    dictionary_version_id uuid NOT NULL
                        REFERENCES public.international_allergen_dictionary_versions(id) ON DELETE RESTRICT,
                    allergen_id varchar(40) NOT NULL
                        REFERENCES public.international_allergen_canonical_definitions(id) ON DELETE RESTRICT,
                    source_name varchar(180) NOT NULL CHECK (btrim(source_name) <> ''),
                    status varchar(24) NOT NULL CHECK (status IN (
                        'MANDATORY', 'RECOMMENDED', 'VOLUNTARY', 'NATIONAL_OPTION',
                        'WARNING_ONLY', 'FUTURE_MANDATORY', 'HISTORICAL'
                    )),
                    listing_rule varchar(64) NOT NULL CHECK (btrim(listing_rule) <> ''),
                    legal_effective_from date,
                    transition_end date,
                    source_scope varchar(40) NOT NULL CHECK (btrim(source_scope) <> ''),
                    threshold jsonb CHECK (threshold IS NULL OR jsonb_typeof(threshold) = 'object'),
                    exceptions jsonb NOT NULL DEFAULT '[]'::jsonb
                        CHECK (jsonb_typeof(exceptions) = 'array'),
                    note text,
                    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
                    archived_at timestamptz,
                    archived_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (dictionary_version_id, allergen_id),
                    CHECK ((archived_at IS NULL) = (archived_by IS NULL))
                );
                CREATE INDEX international_allergen_source_entries_dictionary_idx
                    ON public.international_allergen_source_entries(dictionary_version_id, status, allergen_id);

                CREATE TABLE public.raw_material_allergen_pool_versions (
                    id uuid PRIMARY KEY,
                    catalog_version_id uuid NOT NULL UNIQUE
                        REFERENCES public.raw_material_allergen_catalog_versions(id) ON DELETE RESTRICT,
                    version_number integer NOT NULL UNIQUE CHECK (version_number > 0),
                    seed_document_id varchar(120) UNIQUE,
                    created_by uuid REFERENCES public.users(id) ON DELETE RESTRICT,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
                );

                ALTER TABLE public.raw_material_allergen_definitions
                    ADD COLUMN canonical_allergen_id varchar(40)
                        REFERENCES public.international_allergen_canonical_definitions(id) ON DELETE RESTRICT,
                    ADD COLUMN source_codes jsonb NOT NULL DEFAULT '[]'::jsonb
                        CHECK (jsonb_typeof(source_codes) = 'array');

                CREATE OR REPLACE FUNCTION public.international_allergen_dictionary_version_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Wersji słownika alergenów nie można usuwać'
                            USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.source_code IS DISTINCT FROM OLD.source_code
                        OR NEW.code IS DISTINCT FROM OLD.code
                        OR NEW.authority IS DISTINCT FROM OLD.authority
                        OR NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.official_url IS DISTINCT FROM OLD.official_url
                        OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                        OR NEW.effective_until IS DISTINCT FROM OLD.effective_until
                        OR NEW.last_verified_on IS DISTINCT FROM OLD.last_verified_on
                        OR NEW.seed_imported_at IS DISTINCT FROM OLD.seed_imported_at
                        OR NEW.metadata IS DISTINCT FROM OLD.metadata
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Metadane wersji słownika są niezmienne poza ręczną edycją'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER international_allergen_dictionary_version_guard
                    BEFORE UPDATE OR DELETE ON public.international_allergen_dictionary_versions
                    FOR EACH ROW EXECUTE FUNCTION public.international_allergen_dictionary_version_guard();

                CREATE OR REPLACE FUNCTION public.international_allergen_canonical_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Definicji kanonicznych alergenów nie można usuwać'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'UPDATE' AND (
                        NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR (OLD.record_status = 'ARCHIVED' AND NEW.record_status <> 'ARCHIVED')
                    ) THEN
                        RAISE EXCEPTION 'Tożsamość i archiwizacja alergenu kanonicznego są nieodwracalne'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER international_allergen_canonical_guard
                    BEFORE UPDATE OR DELETE ON public.international_allergen_canonical_definitions
                    FOR EACH ROW EXECUTE FUNCTION public.international_allergen_canonical_guard();

                CREATE OR REPLACE FUNCTION public.international_allergen_source_entry_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Powiązań źródłowych alergenów nie można usuwać'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'UPDATE' AND (
                        NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.dictionary_version_id IS DISTINCT FROM OLD.dictionary_version_id
                        OR NEW.allergen_id IS DISTINCT FROM OLD.allergen_id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR (OLD.archived_at IS NOT NULL AND NEW.archived_at IS NULL)
                    ) THEN
                        RAISE EXCEPTION 'Tożsamość i archiwizacja powiązania źródłowego są nieodwracalne'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER international_allergen_source_entry_guard
                    BEFORE UPDATE OR DELETE ON public.international_allergen_source_entries
                    FOR EACH ROW EXECUTE FUNCTION public.international_allergen_source_entry_guard();

                CREATE OR REPLACE FUNCTION public.raw_material_allergen_pool_version_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Wersji wspólnej puli alergenów nie można zmieniać ani usuwać'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_allergen_pool_version_guard
                    BEFORE UPDATE OR DELETE ON public.raw_material_allergen_pool_versions
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_allergen_pool_version_guard();

                REVOKE ALL ON FUNCTION public.international_allergen_dictionary_version_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.international_allergen_canonical_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.international_allergen_source_entry_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_allergen_pool_version_guard() FROM PUBLIC;
                SQL);

            $tables = [
                'international_allergen_dictionary_versions' => ['UPDATE'],
                'international_allergen_canonical_definitions' => ['INSERT', 'UPDATE'],
                'international_allergen_source_entries' => ['INSERT', 'UPDATE'],
                'raw_material_allergen_catalog_versions' => ['INSERT', 'UPDATE'],
                'raw_material_allergen_definitions' => ['INSERT'],
                'raw_material_allergen_pool_versions' => ['INSERT'],
                'raw_material_audit_events' => ['INSERT'],
            ];
            foreach ($tables as $table => $operations) {
                DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore([
                    'table_name' => $table,
                ]);
                foreach ($operations as $operation) {
                    DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                        'table_name' => $table,
                        'operation' => $operation,
                        'scope_prefix' => 'administration.allergen.manage',
                    ]);
                }
                if ($table !== 'raw_material_audit_events') {
                    DB::unprepared(sprintf(
                        'CREATE TRIGGER %s_authorization_ticket BEFORE %s ON public.%s FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation()',
                        $table,
                        implode(' OR ', $operations),
                        $table,
                    ));
                }
            }

            DB::unprepared(<<<'SQL'
                GRANT SELECT ON public.international_allergen_sources,
                    public.international_allergen_dictionary_versions,
                    public.international_allergen_canonical_definitions,
                    public.international_allergen_source_entries,
                    public.raw_material_allergen_pool_versions TO veltra_app;
                GRANT INSERT, UPDATE ON public.international_allergen_dictionary_versions,
                    public.international_allergen_canonical_definitions,
                    public.international_allergen_source_entries TO veltra_app;
                GRANT INSERT ON public.raw_material_allergen_pool_versions TO veltra_app;
                GRANT INSERT, UPDATE ON public.raw_material_allergen_catalog_versions TO veltra_app;
                GRANT INSERT ON public.raw_material_allergen_definitions TO veltra_app;
                GRANT INSERT ON public.raw_material_audit_events TO veltra_app;
                GRANT SELECT, INSERT ON public.raw_material_allergen_catalog_versions,
                    public.raw_material_allergen_definitions TO veltra_app;
                REVOKE ALL ON FUNCTION public.international_allergen_dictionary_version_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.international_allergen_canonical_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.international_allergen_source_entry_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_allergen_pool_version_guard() FROM PUBLIC;
                SQL);

            app(AllergenDictionariesSeeder::class)->run();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 052 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
