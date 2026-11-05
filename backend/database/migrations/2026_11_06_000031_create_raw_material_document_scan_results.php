<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! DB::scalar("SELECT to_regclass('public.raw_material_document_versions') IS NOT NULL")
                || ! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")
                || ! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")) {
                throw new RuntimeException('Migracja skanowania wymaga struktur dokumentów i ochrony podpisanych mutacji.');
            }

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.raw_material_document_versions
                    ADD CONSTRAINT raw_material_document_versions_scan_checksum_unique
                    UNIQUE (id, organization_id, sha256);

                CREATE TABLE public.raw_material_document_scan_results (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    document_version_id uuid NOT NULL,
                    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                    scan_number integer NOT NULL CHECK (scan_number > 0),
                    result_state varchar(12) NOT NULL CHECK (
                        result_state IN ('PENDING', 'CLEAN', 'INFECTED', 'ERROR')
                    ),
                    scanner_service varchar(32),
                    scanner_version varchar(64),
                    signature_version varchar(128),
                    detection_name varchar(255),
                    error_code varchar(64),
                    recorded_by uuid,
                    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (document_version_id, organization_id, scan_number),
                    FOREIGN KEY (document_version_id, organization_id, sha256)
                        REFERENCES public.raw_material_document_versions(id, organization_id, sha256)
                        ON DELETE RESTRICT,
                    FOREIGN KEY (recorded_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    CHECK (
                        (result_state = 'PENDING'
                            AND scanner_service IS NULL
                            AND scanner_version IS NULL
                            AND signature_version IS NULL
                            AND detection_name IS NULL
                            AND error_code IS NULL
                            AND recorded_by IS NOT NULL)
                        OR (result_state = 'CLEAN'
                            AND scanner_service = 'CLAMAV'
                            AND scanner_version IS NOT NULL
                            AND signature_version IS NOT NULL
                            AND detection_name IS NULL
                            AND error_code IS NULL
                            AND recorded_by IS NULL)
                        OR (result_state = 'INFECTED'
                            AND scanner_service = 'CLAMAV'
                            AND scanner_version IS NOT NULL
                            AND signature_version IS NOT NULL
                            AND detection_name IS NOT NULL
                            AND error_code IS NULL
                            AND recorded_by IS NULL)
                        OR (result_state = 'ERROR'
                            AND scanner_service = 'CLAMAV'
                            AND detection_name IS NULL
                            AND error_code ~ '^[A-Z0-9_]{1,64}$'
                            AND recorded_by IS NULL)
                    )
                );
                CREATE UNIQUE INDEX raw_material_document_scan_pending_unique
                    ON public.raw_material_document_scan_results(document_version_id, organization_id)
                    WHERE result_state = 'PENDING';
                CREATE INDEX raw_material_document_scan_history_idx
                    ON public.raw_material_document_scan_results
                        (organization_id, document_version_id, scan_number DESC);

                CREATE OR REPLACE FUNCTION public.raw_material_document_scan_result_scope_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket_text text;
                    ticket_scope text;
                    required_scope text;
                BEGIN
                    ticket_text := current_setting('veltra.authorization_payload', true);
                    IF ticket_text IS NULL OR ticket_text = '' THEN
                        RAISE EXCEPTION 'A signed scanner authorization ticket is required'
                            USING ERRCODE = '42501';
                    END IF;

                    ticket_scope := ticket_text::jsonb->>'scope';
                    required_scope := CASE
                        WHEN NEW.result_state = 'PENDING' THEN 'raw-material.mutate'
                        ELSE 'raw-material.document.scan-result'
                    END;
                    IF ticket_scope IS DISTINCT FROM required_scope THEN
                        RAISE EXCEPTION 'Only the document scanner service may record authoritative scan results'
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_document_scan_results_scope_guard
                    BEFORE INSERT ON public.raw_material_document_scan_results
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_scan_result_scope_validate();

                CREATE TRIGGER raw_material_document_scan_results_immutable
                    BEFORE UPDATE OR DELETE ON public.raw_material_document_scan_results
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_document_rows_immutable();

                CREATE VIEW public.raw_material_approved_compatibility_document_evidence
                    WITH (security_invoker = true)
                    AS
                    SELECT
                        versions.id AS document_version_id,
                        versions.organization_id,
                        versions.document_id,
                        versions.sha256,
                        versions.media_type,
                        versions.size_bytes,
                        links.raw_material_revision_id,
                        links.supplier_product_revision_id,
                        latest.scan_number,
                        latest.scanner_version,
                        latest.signature_version,
                        latest.recorded_at AS scanned_at
                    FROM public.raw_material_document_versions versions
                    JOIN public.raw_material_document_version_links links
                        ON links.document_version_id = versions.id
                        AND links.organization_id = versions.organization_id
                        AND links.link_purpose = 'SUPPLIER_COMPATIBILITY_EVIDENCE'
                    JOIN LATERAL (
                        SELECT results.scan_number, results.sha256, results.result_state,
                            results.scanner_version, results.signature_version, results.recorded_at
                        FROM public.raw_material_document_scan_results results
                        WHERE results.document_version_id = versions.id
                            AND results.organization_id = versions.organization_id
                        ORDER BY results.scan_number DESC
                        LIMIT 1
                    ) latest ON latest.result_state = 'CLEAN'
                        AND latest.sha256 = versions.sha256;

                REVOKE ALL ON FUNCTION public.raw_material_document_scan_result_scope_validate() FROM PUBLIC;
                REVOKE ALL ON TABLE public.raw_material_document_scan_results,
                    public.raw_material_approved_compatibility_document_evidence FROM PUBLIC;
                REVOKE ALL ON TABLE public.raw_material_document_scan_results FROM veltra_app;
                GRANT SELECT, INSERT ON TABLE public.raw_material_document_scan_results TO veltra_app;
                GRANT SELECT ON TABLE public.raw_material_approved_compatibility_document_evidence TO veltra_app;
                SQL);

            DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore([
                'table_name' => 'raw_material_document_scan_results',
            ]);
            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                'table_name' => 'raw_material_document_scan_results',
                'operation' => 'INSERT',
                'scope_prefix' => 'raw-material.mutate',
            ]);
            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                'table_name' => 'raw_material_document_scan_results',
                'operation' => 'INSERT',
                'scope_prefix' => 'raw-material.document.scan-result',
            ]);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER raw_material_document_scan_results_authorization_ticket
                    BEFORE INSERT ON public.raw_material_document_scan_results
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 031 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
