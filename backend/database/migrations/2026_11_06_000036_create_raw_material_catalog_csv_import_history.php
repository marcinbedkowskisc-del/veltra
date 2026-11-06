<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::scalar("SELECT to_regclass('public.raw_materials') IS NOT NULL")
            || ! DB::scalar("SELECT to_regclass('public.organization_sku_assignments') IS NOT NULL")
            || ! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")) {
            throw new RuntimeException('Historia importu katalogu wymaga modelu RAW, alokatora SKU i podpisanej granicy mutacji.');
        }

        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                CREATE TABLE public.raw_material_catalog_import_batches (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    actor_user_id uuid NOT NULL,
                    actor_location_id uuid NOT NULL,
                    preview_id uuid NOT NULL,
                    idempotency_key varchar(120) NOT NULL,
                    request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                    source_sha256 char(64) NOT NULL CHECK (source_sha256 ~ '^[0-9a-f]{64}$'),
                    batch_status varchar(16) NOT NULL CHECK (batch_status = 'COMMITTED'),
                    row_count integer NOT NULL CHECK (row_count > 0),
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (id, organization_id),
                    UNIQUE (organization_id, actor_user_id, preview_id),
                    UNIQUE (organization_id, actor_user_id, idempotency_key),
                    FOREIGN KEY (actor_user_id, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (actor_location_id, organization_id)
                        REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_catalog_import_rows (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    batch_id uuid NOT NULL,
                    row_number integer NOT NULL CHECK (row_number > 0),
                    raw_material_id uuid NOT NULL,
                    sku varchar(16) NOT NULL CHECK (sku ~ '^RAW-[0-9]{6,9}$'),
                    entity_type varchar(3) NOT NULL CHECK (entity_type = 'RAW'),
                    name varchar(200) NOT NULL CHECK (btrim(name) <> ''),
                    description text,
                    base_unit_code varchar(24) NOT NULL
                        CHECK (base_unit_code IN ('mg', 'g', 'kg', 'ml', 'l', 'pc')),
                    category_code varchar(50) NOT NULL CHECK (category_code ~ '^[A-Z0-9_-]+$'),
                    result_code varchar(16) NOT NULL CHECK (result_code IN ('CREATED', 'NO_CHANGE')),
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (batch_id, organization_id, row_number),
                    UNIQUE (batch_id, organization_id, sku),
                    FOREIGN KEY (batch_id, organization_id)
                        REFERENCES public.raw_material_catalog_import_batches(id, organization_id) ON DELETE RESTRICT
                        DEFERRABLE INITIALLY DEFERRED,
                    FOREIGN KEY (raw_material_id, organization_id)
                        REFERENCES public.raw_materials(id, organization_id) ON DELETE RESTRICT
                );

                CREATE INDEX raw_material_catalog_import_batches_actor_idx
                    ON public.raw_material_catalog_import_batches(organization_id, actor_user_id, created_at DESC);
                CREATE INDEX raw_material_catalog_import_rows_batch_idx
                    ON public.raw_material_catalog_import_rows(organization_id, batch_id, row_number);

                CREATE FUNCTION public.raw_material_catalog_import_serialize()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    PERFORM id FROM public.organizations WHERE id = NEW.organization_id FOR UPDATE;
                    NEW.created_at := clock_timestamp();
                    RETURN NEW;
                END
                $function$;

                CREATE FUNCTION public.raw_material_catalog_import_batch_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    actual_rows integer;
                BEGIN
                    SELECT count(*)::integer INTO actual_rows
                    FROM public.raw_material_catalog_import_rows
                    WHERE batch_id = NEW.id AND organization_id = NEW.organization_id;
                    IF actual_rows <> NEW.row_count THEN
                        RAISE EXCEPTION 'Import katalogu CSV ma niespójną liczbę wierszy'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;

                CREATE FUNCTION veltra_private.enforce_raw_material_catalog_import_subject()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    configured_migration_role name;
                    ticket_text text;
                    ticket jsonb;
                    expected_actor uuid;
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
                        RAISE EXCEPTION 'Wymagany jest podpisany kontekst importu katalogu'
                            USING ERRCODE = '42501';
                    END IF;
                    BEGIN
                        ticket := ticket_text::jsonb;
                    EXCEPTION WHEN invalid_text_representation THEN
                        RAISE EXCEPTION 'Nieprawidłowy podpisany kontekst importu katalogu'
                            USING ERRCODE = '42501';
                    END;

                    IF ticket->>'scope' IS DISTINCT FROM 'raw-material.csv-import'
                        OR ticket->>'subject_id' IS DISTINCT FROM NEW.organization_id::text THEN
                        RAISE EXCEPTION 'Kontekst importu katalogu nie odpowiada organizacji'
                            USING ERRCODE = '42501';
                    END IF;

                    IF TG_TABLE_NAME = 'raw_material_catalog_import_batches' THEN
                        expected_actor := NEW.actor_user_id;
                    ELSE
                        SELECT actor_user_id INTO expected_actor
                        FROM public.raw_material_catalog_import_batches
                        WHERE id = NEW.batch_id AND organization_id = NEW.organization_id;
                    END IF;

                    IF expected_actor IS NULL OR ticket->>'actor_id' IS DISTINCT FROM expected_actor::text THEN
                        RAISE EXCEPTION 'Aktor importu katalogu nie odpowiada batchowi'
                            USING ERRCODE = '42501';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM public.users users
                        JOIN public.organizations organizations
                            ON organizations.id = users.organization_id
                        WHERE users.id = expected_actor
                            AND users.organization_id = NEW.organization_id
                            AND users.is_active
                            AND users.email_verified_at IS NOT NULL
                            AND organizations.is_active
                    ) THEN
                        RAISE EXCEPTION 'Aktor importu katalogu nie jest aktywny w organizacji'
                            USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_catalog_import_batches_serialize
                    BEFORE INSERT ON public.raw_material_catalog_import_batches
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_catalog_import_serialize();
                CREATE TRIGGER raw_material_catalog_import_rows_serialize
                    BEFORE INSERT ON public.raw_material_catalog_import_rows
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_catalog_import_serialize();

                CREATE TRIGGER raw_material_catalog_import_batches_authorization
                    BEFORE INSERT ON public.raw_material_catalog_import_batches
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_material_catalog_import_rows_authorization
                    BEFORE INSERT ON public.raw_material_catalog_import_rows
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_material_catalog_import_batches_subject
                    BEFORE INSERT ON public.raw_material_catalog_import_batches
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_raw_material_catalog_import_subject();
                CREATE TRIGGER raw_material_catalog_import_rows_subject
                    BEFORE INSERT ON public.raw_material_catalog_import_rows
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_raw_material_catalog_import_subject();

                CREATE TRIGGER raw_material_catalog_import_batches_append_only
                    BEFORE UPDATE OR DELETE ON public.raw_material_catalog_import_batches
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_commercial_append_only();
                CREATE TRIGGER raw_material_catalog_import_rows_append_only
                    BEFORE UPDATE OR DELETE ON public.raw_material_catalog_import_rows
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_commercial_append_only();

                CREATE CONSTRAINT TRIGGER raw_material_catalog_import_batches_validate
                    AFTER INSERT ON public.raw_material_catalog_import_batches
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_catalog_import_batch_validate();

                REVOKE ALL ON FUNCTION public.raw_material_catalog_import_serialize() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_catalog_import_batch_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION veltra_private.enforce_raw_material_catalog_import_subject() FROM PUBLIC, veltra_app;
                GRANT EXECUTE ON FUNCTION veltra_private.enforce_raw_material_catalog_import_subject() TO veltra_migrator;

                INSERT INTO veltra_private.authorization_mutation_scopes(table_name, operation, scope_prefix)
                VALUES
                    ('raw_material_catalog_import_batches', 'INSERT', 'raw-material.csv-import'),
                    ('raw_material_catalog_import_rows', 'INSERT', 'raw-material.csv-import');

                REVOKE ALL ON TABLE public.raw_material_catalog_import_batches,
                    public.raw_material_catalog_import_rows FROM PUBLIC, veltra_app;
                GRANT SELECT, INSERT ON TABLE public.raw_material_catalog_import_batches,
                    public.raw_material_catalog_import_rows TO veltra_app;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja historii importu katalogu jest forward-only; cofnięcie wymaga osobnej, przetestowanej migracji.');
    }
};
