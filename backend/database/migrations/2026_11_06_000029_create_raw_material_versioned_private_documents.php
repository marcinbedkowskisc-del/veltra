<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'raw_material_documents',
        'raw_material_document_versions',
        'raw_material_document_version_links',
        'raw_material_document_lifecycle_events',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")
                || ! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")) {
                throw new RuntimeException('Migracja 029 wymaga ochrony podpisanych mutacji PostgreSQL.');
            }

            DB::unprepared(<<<'SQL'
                CREATE TABLE public.raw_material_documents (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    document_type varchar(32) NOT NULL CHECK (document_type IN (
                        'ALLERGEN_DECLARATION', 'SUPPLIER_CERTIFICATE',
                        'SPECIFICATION', 'SAFETY_DATA_SHEET', 'OTHER'
                    )),
                    created_by uuid NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (id, organization_id),
                    FOREIGN KEY (created_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_document_versions (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    document_id uuid NOT NULL,
                    version_number integer NOT NULL CHECK (version_number > 0),
                    original_filename varchar(255) NOT NULL CHECK (btrim(original_filename) <> ''),
                    media_type varchar(160) NOT NULL CHECK (media_type ~ '^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$'),
                    size_bytes bigint NOT NULL CHECK (size_bytes BETWEEN 1 AND 20971520),
                    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                    storage_key varchar(160) NOT NULL UNIQUE CHECK (
                        storage_key ~ '^raw-material-documents/[0-9a-f]{2}/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                    ),
                    scan_state varchar(24) NOT NULL DEFAULT 'PENDING_SCAN'
                        CHECK (scan_state = 'PENDING_SCAN'),
                    created_by uuid NOT NULL,
                    stored_at timestamptz NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (document_id, version_number),
                    UNIQUE (id, organization_id),
                    FOREIGN KEY (document_id, organization_id)
                        REFERENCES public.raw_material_documents(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (created_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );
                CREATE INDEX raw_material_document_versions_history_idx
                    ON public.raw_material_document_versions(organization_id, document_id, version_number DESC);

                CREATE TABLE public.raw_material_document_version_links (
                    document_version_id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    raw_material_revision_id uuid NOT NULL,
                    supplier_product_revision_id uuid,
                    link_purpose varchar(40) NOT NULL CHECK (link_purpose IN (
                        'RAW_MATERIAL_SUPPORTING_DOCUMENT',
                        'ALLERGEN_EVIDENCE',
                        'SUPPLIER_COMPATIBILITY_EVIDENCE'
                    )),
                    linked_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    FOREIGN KEY (document_version_id, organization_id)
                        REFERENCES public.raw_material_document_versions(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (raw_material_revision_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (supplier_product_revision_id, organization_id)
                        REFERENCES public.supplier_product_revisions(id, organization_id) ON DELETE RESTRICT,
                    CHECK (
                        (link_purpose = 'SUPPLIER_COMPATIBILITY_EVIDENCE'
                            AND supplier_product_revision_id IS NOT NULL)
                        OR (link_purpose <> 'SUPPLIER_COMPATIBILITY_EVIDENCE'
                            AND supplier_product_revision_id IS NULL)
                    )
                );
                CREATE INDEX raw_material_document_revision_links_idx
                    ON public.raw_material_document_version_links(organization_id, raw_material_revision_id);

                CREATE TABLE public.raw_material_document_lifecycle_events (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    document_id uuid NOT NULL,
                    document_version_id uuid,
                    event_code varchar(32) NOT NULL CHECK (event_code IN (
                        'DOCUMENT_CREATED', 'VERSION_STORED', 'VERSION_LINKED', 'SCAN_REQUIRED'
                    )),
                    actor_user_id uuid NOT NULL,
                    occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
                    FOREIGN KEY (document_id, organization_id)
                        REFERENCES public.raw_material_documents(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (document_version_id, organization_id)
                        REFERENCES public.raw_material_document_versions(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (actor_user_id, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    CHECK ((event_code = 'DOCUMENT_CREATED' AND document_version_id IS NULL)
                        OR (event_code <> 'DOCUMENT_CREATED' AND document_version_id IS NOT NULL))
                );
                CREATE INDEX raw_material_document_lifecycle_history_idx
                    ON public.raw_material_document_lifecycle_events(organization_id, document_id, occurred_at);

                CREATE OR REPLACE FUNCTION public.raw_material_document_version_link_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Powiązania wersji dokumentu są niezmienne'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NEW.supplier_product_revision_id IS NOT NULL
                        AND NOT EXISTS (
                            SELECT 1
                            FROM public.supplier_product_revisions supplier_revisions
                            WHERE supplier_revisions.id = NEW.supplier_product_revision_id
                                AND supplier_revisions.organization_id = NEW.organization_id
                                AND supplier_revisions.raw_material_revision_id = NEW.raw_material_revision_id
                        ) THEN
                        RAISE EXCEPTION 'Dowód zgodności musi wskazywać rewizję produktu i surowca z tej samej organizacji'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_document_version_link_validate
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_document_version_links
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_version_link_validate();

                CREATE OR REPLACE FUNCTION public.raw_material_document_rows_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    RAISE EXCEPTION 'Historia dokumentów i ich wersji jest niezmienna'
                        USING ERRCODE = '23514';
                END
                $function$;

                CREATE TRIGGER raw_material_documents_immutable
                    BEFORE UPDATE OR DELETE ON public.raw_material_documents
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_rows_immutable();
                CREATE TRIGGER raw_material_document_versions_immutable
                    BEFORE UPDATE OR DELETE ON public.raw_material_document_versions
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_rows_immutable();
                CREATE TRIGGER raw_material_document_version_links_immutable
                    BEFORE UPDATE OR DELETE ON public.raw_material_document_version_links
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_rows_immutable();
                CREATE TRIGGER raw_material_document_lifecycle_events_immutable
                    BEFORE UPDATE OR DELETE ON public.raw_material_document_lifecycle_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_rows_immutable();

                REVOKE ALL ON FUNCTION public.raw_material_document_version_link_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_document_rows_immutable() FROM PUBLIC;
                SQL);

            foreach (self::TABLES as $table) {
                DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore(['table_name' => $table]);
                DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                    'table_name' => $table,
                    'operation' => 'INSERT',
                    'scope_prefix' => 'raw-material.mutate',
                ]);
                DB::unprepared(sprintf(
                    'CREATE TRIGGER %s_authorization_ticket BEFORE INSERT OR UPDATE OR DELETE ON public.%s FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation()',
                    $table,
                    $table,
                ));
            }

            DB::unprepared(<<<'SQL'
                REVOKE ALL ON TABLE
                    public.raw_material_documents,
                    public.raw_material_document_versions,
                    public.raw_material_document_version_links,
                    public.raw_material_document_lifecycle_events
                FROM PUBLIC, veltra_app;
                GRANT SELECT, INSERT ON TABLE
                    public.raw_material_documents,
                    public.raw_material_document_versions,
                    public.raw_material_document_version_links,
                    public.raw_material_document_lifecycle_events
                TO veltra_app;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 029 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
