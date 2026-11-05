<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE raw_materials_commercial_idempotency
                ADD CONSTRAINT raw_materials_commercial_idempotency_import_identity_uq
                    UNIQUE (id, organization_id, actor_user_id, operation_code, idempotency_key, request_hash);

            CREATE TABLE raw_material_import_batches (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL,
                actor_user_id uuid NOT NULL,
                idempotency_record_id uuid NOT NULL,
                operation_code varchar(100) NOT NULL DEFAULT 'raw-material.csv-import'
                    CHECK (operation_code = 'raw-material.csv-import'),
                idempotency_key varchar(120) NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                source_sha256 char(64) NOT NULL CHECK (source_sha256 ~ '^[0-9a-f]{64}$'),
                batch_status varchar(12) NOT NULL CHECK (batch_status IN ('COMMITTED', 'REJECTED')),
                row_count integer NOT NULL CHECK (row_count > 0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                completed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (id, organization_id, actor_user_id),
                UNIQUE (organization_id, actor_user_id, idempotency_key),
                FOREIGN KEY (
                    idempotency_record_id, organization_id, actor_user_id,
                    operation_code, idempotency_key, request_hash
                ) REFERENCES raw_materials_commercial_idempotency(
                    id, organization_id, actor_user_id, operation_code, idempotency_key, request_hash
                ) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_material_import_batches_history_idx
                ON raw_material_import_batches(organization_id, created_at DESC, id);

            CREATE TABLE raw_material_import_metadata_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL,
                raw_material_id uuid NOT NULL,
                batch_id uuid NOT NULL,
                source_row_number integer NOT NULL CHECK (source_row_number > 0),
                effective_from date NOT NULL,
                event_type varchar(24) NOT NULL
                    CHECK (event_type IN ('INITIAL_BASELINE', 'METADATA_CHANGE')),
                vat_rate numeric(5,2) NOT NULL CHECK (vat_rate BETWEEN 0 AND 100),
                input_unit varchar(4) NOT NULL CHECK (input_unit IN ('kg', 'l', 'szt.')),
                effective_reference_price_event_id uuid,
                recorded_by uuid NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id, raw_material_id),
                UNIQUE (organization_id, raw_material_id, effective_from),
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (batch_id, organization_id, recorded_by)
                    REFERENCES raw_material_import_batches(id, organization_id, actor_user_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (effective_reference_price_event_id, organization_id, raw_material_id)
                    REFERENCES raw_material_reference_price_events(id, organization_id, raw_material_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (recorded_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_material_import_metadata_as_of_idx
                ON raw_material_import_metadata_events(
                    organization_id, raw_material_id, effective_from DESC, recorded_at DESC, id
                );

            CREATE TABLE raw_material_import_rows (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL,
                batch_id uuid NOT NULL,
                row_number integer NOT NULL CHECK (row_number > 0),
                raw_material_id uuid,
                ingredient_code varchar(16) NOT NULL CHECK (ingredient_code ~ '^SUR-[0-9]{6,}$'),
                input_ingredient_name varchar(200) NOT NULL CHECK (btrim(input_ingredient_name) <> ''),
                current_ingredient_name varchar(200),
                source_price_net numeric(31,14) NOT NULL
                    CHECK (source_price_net >= 0 AND source_price_net < 100000000000000000
                        AND scale(source_price_net) <= 14),
                normalized_net_pln_per_base_unit numeric(31,14) NOT NULL
                    CHECK (normalized_net_pln_per_base_unit >= 0
                        AND normalized_net_pln_per_base_unit < 100000000000000000
                        AND scale(normalized_net_pln_per_base_unit) <= 14
                        AND normalized_net_pln_per_base_unit =
                            raw_materials_round_half_even_v1(normalized_net_pln_per_base_unit, 14)),
                vat_rate numeric(5,2) NOT NULL CHECK (vat_rate BETWEEN 0 AND 100),
                input_unit varchar(4) NOT NULL CHECK (input_unit IN ('kg', 'l', 'szt.')),
                effective_date date NOT NULL,
                verified_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                result_code varchar(40) NOT NULL CHECK (result_code IN (
                    'NO_CHANGE',
                    'INITIAL_METADATA_BASELINE',
                    'METADATA_CHANGED',
                    'PRICE_CHANGED',
                    'PRICE_AND_METADATA_CHANGED',
                    'DATE_CONFLICT',
                    'INVALID_ROW',
                    'BATCH_REJECTED'
                )),
                error_code varchar(80),
                error_detail varchar(500),
                effective_metadata_state_id uuid,
                effective_reference_price_event_id uuid,
                created_reference_price_event_id uuid,
                UNIQUE (id, organization_id, raw_material_id),
                UNIQUE (batch_id, organization_id, row_number),
                UNIQUE (batch_id, organization_id, row_number, raw_material_id),
                FOREIGN KEY (batch_id, organization_id)
                    REFERENCES raw_material_import_batches(id, organization_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (effective_metadata_state_id, organization_id, raw_material_id)
                    REFERENCES raw_material_import_metadata_events(id, organization_id, raw_material_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (effective_reference_price_event_id, organization_id, raw_material_id)
                    REFERENCES raw_material_reference_price_events(id, organization_id, raw_material_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (created_reference_price_event_id, organization_id, raw_material_id)
                    REFERENCES raw_material_reference_price_events(id, organization_id, raw_material_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
                CHECK ((result_code IN ('DATE_CONFLICT', 'INVALID_ROW', 'BATCH_REJECTED')
                            AND error_code IS NOT NULL)
                    OR (result_code NOT IN ('DATE_CONFLICT', 'INVALID_ROW', 'BATCH_REJECTED')
                            AND error_code IS NULL)),
                CHECK (raw_material_id IS NOT NULL OR result_code = 'INVALID_ROW')
            );
            ALTER TABLE raw_material_import_metadata_events
                ADD CONSTRAINT raw_material_import_metadata_source_row_fk
                    FOREIGN KEY (batch_id, organization_id, source_row_number, raw_material_id)
                    REFERENCES raw_material_import_rows(batch_id, organization_id, row_number, raw_material_id)
                    ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED;
            CREATE INDEX raw_material_import_rows_material_history_idx
                ON raw_material_import_rows(organization_id, raw_material_id, effective_date DESC, verified_at DESC);
            CREATE INDEX raw_material_import_rows_batch_result_idx
                ON raw_material_import_rows(organization_id, batch_id, result_code);

            CREATE OR REPLACE FUNCTION raw_material_import_history_serialize() RETURNS trigger
                LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF TG_TABLE_NAME = 'raw_material_import_batches' THEN
                    NEW.created_at := clock_timestamp();
                    NEW.completed_at := NEW.created_at;
                ELSIF TG_TABLE_NAME = 'raw_material_import_rows' THEN
                    NEW.verified_at := clock_timestamp();
                ELSE
                    NEW.recorded_at := clock_timestamp();
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER raw_material_import_batches_serialize
                BEFORE INSERT ON raw_material_import_batches
                FOR EACH ROW EXECUTE FUNCTION raw_material_import_history_serialize();
            CREATE TRIGGER raw_material_import_rows_serialize
                BEFORE INSERT ON raw_material_import_rows
                FOR EACH ROW EXECUTE FUNCTION raw_material_import_history_serialize();
            CREATE TRIGGER raw_material_import_metadata_serialize
                BEFORE INSERT ON raw_material_import_metadata_events
                FOR EACH ROW EXECUTE FUNCTION raw_material_import_history_serialize();

            CREATE OR REPLACE FUNCTION raw_material_import_batch_validate() RETURNS trigger
                LANGUAGE plpgsql AS $$
            DECLARE
                actual_rows integer;
                has_rejection boolean;
                has_duplicate boolean;
            BEGIN
                SELECT count(*)::integer,
                    COALESCE(bool_or(result_code IN ('DATE_CONFLICT', 'INVALID_ROW', 'BATCH_REJECTED')), false),
                    count(DISTINCT ingredient_code) <> count(*)
                INTO actual_rows, has_rejection, has_duplicate
                FROM raw_material_import_rows
                WHERE batch_id = NEW.id AND organization_id = NEW.organization_id;

                IF actual_rows <> NEW.row_count
                    OR (NEW.batch_status = 'COMMITTED' AND (has_rejection OR has_duplicate))
                    OR (NEW.batch_status = 'REJECTED' AND NOT has_rejection) THEN
                    RAISE EXCEPTION 'CSV import batch status or row count is inconsistent'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END
            $$;
            CREATE CONSTRAINT TRIGGER raw_material_import_batches_validate
                AFTER INSERT ON raw_material_import_batches
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION raw_material_import_batch_validate();

            CREATE TRIGGER raw_material_import_batches_append_only
                BEFORE UPDATE OR DELETE ON raw_material_import_batches
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER raw_material_import_rows_append_only
                BEFORE UPDATE OR DELETE ON raw_material_import_rows
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER raw_material_import_metadata_append_only
                BEFORE UPDATE OR DELETE ON raw_material_import_metadata_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();

            CREATE OR REPLACE FUNCTION veltra_private.enforce_raw_material_import_subject()
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
                    RAISE EXCEPTION 'A signed CSV import mutation context is required' USING ERRCODE = '42501';
                END IF;
                BEGIN
                    ticket := ticket_text::jsonb;
                EXCEPTION WHEN invalid_text_representation THEN
                    RAISE EXCEPTION 'The CSV import authorization ticket is invalid' USING ERRCODE = '42501';
                END;
                IF ticket->>'scope' IS DISTINCT FROM 'raw-material.csv-import'
                    OR ticket->>'subject_id' IS DISTINCT FROM NEW.organization_id::text
                    OR coalesce(ticket->>'actor_id', '') !~* '^[0-9a-f-]{36}$' THEN
                    RAISE EXCEPTION 'CSV import mutation subject does not match its organization'
                        USING ERRCODE = '42501';
                END IF;

                IF TG_TABLE_NAME = 'raw_material_import_batches' THEN
                    expected_actor := NEW.actor_user_id;
                ELSIF TG_TABLE_NAME = 'raw_material_import_metadata_events' THEN
                    SELECT actor_user_id INTO expected_actor FROM raw_material_import_batches
                        WHERE id = NEW.batch_id AND organization_id = NEW.organization_id;
                    IF expected_actor IS DISTINCT FROM NEW.recorded_by THEN
                        RAISE EXCEPTION 'CSV import metadata actor does not match its batch'
                            USING ERRCODE = '42501';
                    END IF;
                ELSE
                    SELECT actor_user_id INTO expected_actor FROM raw_material_import_batches
                        WHERE id = NEW.batch_id AND organization_id = NEW.organization_id;
                END IF;

                IF expected_actor IS NULL OR ticket->>'actor_id' IS DISTINCT FROM expected_actor::text THEN
                    RAISE EXCEPTION 'CSV import mutation actor does not match its batch'
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
                    RAISE EXCEPTION 'CSV import actor is not active in its organization'
                        USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $function$;

            REVOKE ALL ON FUNCTION veltra_private.enforce_raw_material_import_subject() FROM PUBLIC, veltra_app;
            GRANT EXECUTE ON FUNCTION veltra_private.enforce_raw_material_import_subject() TO veltra_migrator;

            CREATE TRIGGER raw_material_import_batches_subject
                BEFORE INSERT ON raw_material_import_batches
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_raw_material_import_subject();
            CREATE TRIGGER raw_material_import_rows_subject
                BEFORE INSERT ON raw_material_import_rows
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_raw_material_import_subject();
            CREATE TRIGGER raw_material_import_metadata_subject
                BEFORE INSERT ON raw_material_import_metadata_events
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_raw_material_import_subject();

            INSERT INTO veltra_private.authorization_mutation_scopes(table_name, operation, scope_prefix)
            VALUES
                ('raw_material_import_batches', 'INSERT', 'raw-material.csv-import'),
                ('raw_material_import_rows', 'INSERT', 'raw-material.csv-import'),
                ('raw_material_import_metadata_events', 'INSERT', 'raw-material.csv-import'),
                ('raw_material_reference_price_events', 'INSERT', 'raw-material.csv-import'),
                ('raw_materials_commercial_idempotency', 'INSERT', 'raw-material.csv-import'),
                ('raw_materials_commercial_audit_events', 'INSERT', 'raw-material.csv-import');

            REVOKE ALL ON TABLE public.raw_material_import_batches,
                public.raw_material_import_rows,
                public.raw_material_import_metadata_events FROM PUBLIC, veltra_app;
            GRANT SELECT, INSERT ON TABLE public.raw_material_import_batches,
                public.raw_material_import_rows,
                public.raw_material_import_metadata_events TO veltra_app;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 034 jest forward-only; cofnięcie wymaga osobnej, przetestowanej migracji.');
    }
};
