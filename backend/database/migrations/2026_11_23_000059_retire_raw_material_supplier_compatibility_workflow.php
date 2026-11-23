<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                DROP VIEW public.raw_material_supplier_compatibility_status;
                DROP VIEW public.raw_material_approved_compatibility_document_evidence;

                REVOKE INSERT, UPDATE, DELETE ON TABLE
                    public.raw_material_supplier_compatibility_assessments
                    FROM PUBLIC, veltra_app;
                GRANT SELECT ON TABLE
                    public.raw_material_supplier_compatibility_assessments
                    TO veltra_app;

                ALTER TABLE public.organization_permission_grants
                    ADD CONSTRAINT organization_permission_grants_supplier_compatibility_retired_check
                    CHECK (permission <> 'RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE') NOT VALID;

                ALTER TABLE public.raw_material_document_version_links
                    ADD CONSTRAINT raw_material_document_links_informational_only_check
                    CHECK (
                        link_purpose = 'RAW_MATERIAL_SUPPORTING_DOCUMENT'
                        AND supplier_product_revision_id IS NULL
                    ) NOT VALID;
                SQL);

            DB::table('veltra_private.runtime_table_allowlist')
                ->where('table_name', 'raw_material_supplier_compatibility_assessments')
                ->delete();
            DB::table('veltra_private.authorization_mutation_scopes')
                ->where('table_name', 'raw_material_supplier_compatibility_assessments')
                ->where('operation', 'INSERT')
                ->where('scope_prefix', 'raw-material.commercial')
                ->delete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 059 jest wyłącznie do przodu; przywrócenie wymaga osobnej, przetestowanej migracji.');
    }
};
