<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                CREATE TABLE public.raw_material_document_uploads (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    location_id uuid NOT NULL,
                    actor_user_id uuid NOT NULL,
                    idempotency_key varchar(120) NOT NULL,
                    original_filename varchar(255) NOT NULL,
                    media_type varchar(160) NOT NULL,
                    size_bytes bigint NOT NULL CHECK (size_bytes BETWEEN 1 AND 20971520),
                    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                    storage_key varchar(160) NOT NULL UNIQUE CHECK (
                        storage_key ~ '^raw-material-uploads/[0-9a-f]{2}/[0-9a-f-]{36}$'
                    ),
                    scan_state varchar(16) NOT NULL CHECK (scan_state IN ('CLEAN', 'INFECTED', 'ERROR')),
                    state varchar(16) NOT NULL DEFAULT 'STAGED' CHECK (state IN ('STAGED', 'CLAIMED', 'EXPIRED')),
                    claimed_version_id uuid,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    expires_at timestamptz NOT NULL,
                    UNIQUE (organization_id, location_id, actor_user_id, idempotency_key),
                    FOREIGN KEY (location_id, organization_id) REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (actor_user_id, organization_id) REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (claimed_version_id, organization_id)
                        REFERENCES public.raw_material_document_versions(id, organization_id) ON DELETE RESTRICT,
                    CHECK ((state = 'CLAIMED') = (claimed_version_id IS NOT NULL))
                );
                CREATE INDEX raw_material_document_uploads_expiry_idx
                    ON public.raw_material_document_uploads(expires_at) WHERE state = 'STAGED';

                ALTER TABLE public.raw_material_document_versions
                    DROP CONSTRAINT raw_material_document_versions_storage_key_check;
                ALTER TABLE public.raw_material_document_versions
                    ADD CONSTRAINT raw_material_document_versions_storage_key_check CHECK (
                        storage_key ~ '^raw-material-(documents|uploads)/[0-9a-f]{2}/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                    );

                CREATE TABLE public.raw_material_revision_document_sets (
                    revision_id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    document_version_ids jsonb NOT NULL CHECK (jsonb_typeof(document_version_ids) = 'array'),
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    FOREIGN KEY (revision_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, organization_id) ON DELETE RESTRICT
                );

                CREATE FUNCTION public.raw_material_document_upload_guard()
                RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $function$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.state <> 'STAGED' OR NEW.expires_at <= clock_timestamp()
                            OR NEW.actor_user_id::text IS DISTINCT FROM
                                (current_setting('veltra.authorization_payload', true)::jsonb->>'actor_id') THEN
                            RAISE EXCEPTION 'Nieprawidlowy kontekst uploadu' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF TG_OP = 'DELETE' OR OLD.state <> 'STAGED' THEN
                        RAISE EXCEPTION 'Zakonczony upload jest niezmienny' USING ERRCODE = '23514';
                    END IF;
                    IF (to_jsonb(OLD) - ARRAY['state', 'scan_state', 'claimed_version_id'])
                        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['state', 'scan_state', 'claimed_version_id']) THEN
                        RAISE EXCEPTION 'Metadane uploadu sa niezmienne' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.state = 'EXPIRED' THEN
                        IF OLD.expires_at > clock_timestamp() OR EXISTS (
                            SELECT 1 FROM public.raw_material_document_versions v WHERE v.storage_key = OLD.storage_key
                        ) THEN
                            RAISE EXCEPTION 'Nie mozna sprzatac chronionego pliku' USING ERRCODE = '23514';
                        END IF;
                    ELSE
                        IF OLD.expires_at <= clock_timestamp()
                            OR OLD.actor_user_id::text IS DISTINCT FROM
                                (current_setting('veltra.authorization_payload', true)::jsonb->>'actor_id') THEN
                            RAISE EXCEPTION 'Upload wygasl lub nalezy do innego aktora' USING ERRCODE = '23514';
                        END IF;
                        IF NEW.state = 'CLAIMED' AND (OLD.scan_state <> 'CLEAN' OR NOT EXISTS (
                            SELECT 1 FROM public.raw_material_document_versions v
                            WHERE v.id = NEW.claimed_version_id AND v.organization_id = OLD.organization_id
                                AND v.sha256 = OLD.sha256 AND v.storage_key = OLD.storage_key
                        )) THEN
                            RAISE EXCEPTION 'Nieprawidlowe przypisanie pliku' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_document_uploads_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_document_uploads
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_upload_guard();

                CREATE FUNCTION public.raw_material_document_set_validate()
                RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $function$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Zestawy dokumentow rewizji sa niezmienne' USING ERRCODE = '23514';
                    END IF;
                    IF EXISTS (
                        SELECT 1 FROM jsonb_array_elements_text(NEW.document_version_ids) ids(id)
                        WHERE NOT EXISTS (
                            SELECT 1 FROM public.raw_material_document_versions v
                            JOIN public.raw_material_document_version_links l ON l.document_version_id = v.id
                            JOIN public.raw_material_revisions origin ON origin.id = l.raw_material_revision_id
                            JOIN public.raw_material_revisions target ON target.id = NEW.revision_id
                            WHERE v.id::text = ids.id AND v.organization_id = NEW.organization_id
                                AND origin.organization_id = NEW.organization_id
                                AND target.organization_id = NEW.organization_id
                                AND origin.raw_material_id = target.raw_material_id
                                AND l.supplier_product_revision_id IS NULL
                        )
                    ) OR jsonb_array_length(NEW.document_version_ids) <> (
                        SELECT count(DISTINCT id) FROM jsonb_array_elements_text(NEW.document_version_ids) ids(id)
                    ) THEN
                        RAISE EXCEPTION 'Nieprawidlowy zestaw dokumentow rewizji' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_revision_document_sets_validate
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_document_sets
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_set_validate();
                REVOKE ALL ON FUNCTION public.raw_material_document_upload_guard(),
                    public.raw_material_document_set_validate() FROM PUBLIC;
                REVOKE ALL ON public.raw_material_document_uploads, public.raw_material_revision_document_sets FROM PUBLIC, veltra_app;
                GRANT SELECT, INSERT, UPDATE ON public.raw_material_document_uploads TO veltra_app;
                GRANT SELECT, INSERT ON public.raw_material_revision_document_sets TO veltra_app;
                SQL);
            foreach (['raw_material_document_uploads' => ['INSERT', 'UPDATE'], 'raw_material_revision_document_sets' => ['INSERT']] as $table => $operations) {
                DB::table('veltra_private.runtime_table_allowlist')->insert(['table_name' => $table]);
                foreach ($operations as $operation) {
                    DB::table('veltra_private.authorization_mutation_scopes')->insert([
                        'table_name' => $table, 'operation' => $operation, 'scope_prefix' => 'raw-material.mutate',
                    ]);
                }
                DB::unprepared("CREATE TRIGGER {$table}_authorization_ticket BEFORE INSERT OR UPDATE OR DELETE ON public.{$table}
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation()");
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 044 jest wylacznie do przodu.');
    }
};
