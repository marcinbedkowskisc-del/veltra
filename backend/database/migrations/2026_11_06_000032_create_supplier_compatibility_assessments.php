<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'public.supplier_product_revisions',
                'public.raw_material_revision_allergen_attestations',
                'public.raw_material_approved_compatibility_document_evidence',
                'veltra_private.authorization_mutation_scopes',
            ] as $relation) {
                if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$relation])) {
                    throw new RuntimeException('Migracja ocen zgodności wymaga istniejących rewizji, atestów, bramki skanowania i ochrony mutacji.');
                }
            }

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.organization_permission_grants
                    DROP CONSTRAINT organization_permission_grants_permission_check;
                ALTER TABLE public.organization_permission_grants
                    ADD CONSTRAINT organization_permission_grants_permission_check CHECK (permission IN (
                        'RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE', 'RAW_MATERIALS_EDIT',
                        'RAW_MATERIALS_SUBMIT', 'RAW_MATERIALS_REVIEW', 'RAW_MATERIALS_APPROVE',
                        'RAW_MATERIALS_BLOCK', 'RAW_MATERIALS_UNBLOCK', 'RAW_MATERIALS_ARCHIVE',
                        'RAW_MATERIALS_RESTORE', 'RAW_MATERIALS_CATEGORY_MANAGE',
                        'RAW_MATERIALS_SUPPLIER_VIEW', 'RAW_MATERIALS_SUPPLIER_MANAGE',
                        'RAW_MATERIALS_PURCHASING_COSTS_VIEW', 'RAW_MATERIALS_PURCHASING_COSTS_MANAGE',
                        'RAW_MATERIALS_REFERENCE_PRICE_VIEW', 'RAW_MATERIALS_REFERENCE_PRICE_MANAGE',
                        'RAW_MATERIALS_EXCHANGE_RATE_VIEW', 'RAW_MATERIALS_EXCHANGE_RATE_MANAGE',
                        'RAW_MATERIALS_ALLERGEN_MANAGE', 'RAW_MATERIALS_STORAGE_MANAGE',
                        'RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE',
                        'RAW_MATERIALS_AUDIT_VIEW', 'RAW_MATERIALS_PERMISSION_DELEGATE'
                    ));

                CREATE TABLE public.raw_material_supplier_compatibility_assessments (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    supplier_product_id uuid NOT NULL,
                    supplier_product_revision_id uuid NOT NULL,
                    raw_material_id uuid NOT NULL,
                    raw_material_revision_id uuid NOT NULL,
                    allergen_catalog_version_id uuid NOT NULL,
                    document_version_id uuid NOT NULL,
                    document_sha256 char(64) NOT NULL CHECK (document_sha256 ~ '^[0-9a-f]{64}$'),
                    assessment_number integer NOT NULL CHECK (assessment_number > 0),
                    outcome varchar(16) NOT NULL CHECK (outcome IN ('COMPATIBLE', 'INCOMPATIBLE')),
                    decision_reason text NOT NULL CHECK (char_length(btrim(decision_reason)) BETWEEN 20 AND 2000),
                    assessed_by uuid NOT NULL,
                    assessed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (id, organization_id),
                    UNIQUE (supplier_product_id, assessment_number),
                    FOREIGN KEY (supplier_product_id, organization_id, raw_material_id)
                        REFERENCES public.supplier_products(id, organization_id, raw_material_id) ON DELETE RESTRICT,
                    FOREIGN KEY (supplier_product_revision_id, supplier_product_id, organization_id)
                        REFERENCES public.supplier_product_revisions(id, supplier_product_id, organization_id)
                        ON DELETE RESTRICT,
                    FOREIGN KEY (raw_material_revision_id, raw_material_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, raw_material_id, organization_id)
                        ON DELETE RESTRICT,
                    FOREIGN KEY (raw_material_revision_id, organization_id, allergen_catalog_version_id)
                        REFERENCES public.raw_material_revision_allergen_attestations
                            (revision_id, organization_id, catalog_version_id) ON DELETE RESTRICT,
                    FOREIGN KEY (document_version_id, organization_id, document_sha256)
                        REFERENCES public.raw_material_document_versions(id, organization_id, sha256)
                        ON DELETE RESTRICT,
                    FOREIGN KEY (assessed_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );
                CREATE INDEX raw_material_supplier_compatibility_history_idx
                    ON public.raw_material_supplier_compatibility_assessments
                        (organization_id, supplier_product_id, assessment_number DESC);

                CREATE OR REPLACE FUNCTION public.raw_material_supplier_compatibility_assessment_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    expected_number integer;
                    ticket_actor text;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Oceny zgodności są niezmienne i wyłącznie dopisywane'
                            USING ERRCODE = '23514';
                    END IF;

                    ticket_actor := nullif(
                        current_setting('veltra.authorization_payload', true),
                        ''
                    )::jsonb->>'actor_id';
                    IF ticket_actor IS DISTINCT FROM NEW.assessed_by::text THEN
                        RAISE EXCEPTION 'Aktor oceny musi odpowiadać podpisanemu kontekstowi mutacji'
                            USING ERRCODE = '42501';
                    END IF;

                    SELECT COALESCE(max(assessment_number), 0) + 1
                        INTO expected_number
                    FROM public.raw_material_supplier_compatibility_assessments
                    WHERE organization_id = NEW.organization_id
                        AND supplier_product_id = NEW.supplier_product_id;
                    IF NEW.assessment_number <> expected_number THEN
                        RAISE EXCEPTION 'Numer oceny zgodności jest nieprawidłowy'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM public.supplier_products products
                        JOIN public.organization_suppliers suppliers
                            ON suppliers.id = products.supplier_id
                            AND suppliers.organization_id = products.organization_id
                        JOIN public.supplier_product_revisions product_revisions
                            ON product_revisions.id = NEW.supplier_product_revision_id
                            AND product_revisions.supplier_product_id = products.id
                            AND product_revisions.organization_id = products.organization_id
                        JOIN public.raw_materials materials
                            ON materials.id = NEW.raw_material_id
                            AND materials.organization_id = products.organization_id
                        JOIN public.raw_material_revisions material_revisions
                            ON material_revisions.id = NEW.raw_material_revision_id
                            AND material_revisions.raw_material_id = materials.id
                            AND material_revisions.organization_id = materials.organization_id
                        JOIN public.raw_material_revision_allergen_attestations attestations
                            ON attestations.revision_id = material_revisions.id
                            AND attestations.organization_id = material_revisions.organization_id
                            AND attestations.catalog_version_id = NEW.allergen_catalog_version_id
                            AND attestations.attestation_status IN ('DECLARED', 'NO_KNOWN_ALLERGENS')
                        JOIN public.raw_material_allergen_catalog_versions catalog_versions
                            ON catalog_versions.id = attestations.catalog_version_id
                            AND catalog_versions.status = 'PUBLISHED'
                        JOIN public.raw_material_approved_compatibility_document_evidence evidence
                            ON evidence.document_version_id = NEW.document_version_id
                            AND evidence.organization_id = NEW.organization_id
                            AND evidence.sha256 = NEW.document_sha256
                            AND evidence.supplier_product_revision_id = product_revisions.id
                            AND evidence.raw_material_revision_id = material_revisions.id
                        WHERE products.id = NEW.supplier_product_id
                            AND products.organization_id = NEW.organization_id
                            AND products.raw_material_id = NEW.raw_material_id
                            AND products.lifecycle_state = 'ACTIVE'
                            AND suppliers.lifecycle_state = 'ACTIVE'
                            AND product_revisions.raw_material_id = materials.id
                            AND materials.lifecycle_state = 'ACTIVE'
                            AND materials.approved_revision_id = material_revisions.id
                            AND materials.pending_revision_id IS NULL
                            AND materials.working_revision_id IS NULL
                            AND NOT EXISTS (
                                SELECT 1
                                FROM public.supplier_product_revisions newer_product_revisions
                                WHERE newer_product_revisions.organization_id = product_revisions.organization_id
                                    AND newer_product_revisions.supplier_product_id = product_revisions.supplier_product_id
                                    AND newer_product_revisions.revision_no > product_revisions.revision_no
                            )
                            AND NOT EXISTS (
                                SELECT 1
                                FROM public.raw_material_revisions newer_material_revisions
                                WHERE newer_material_revisions.organization_id = material_revisions.organization_id
                                    AND newer_material_revisions.raw_material_id = material_revisions.raw_material_id
                                    AND newer_material_revisions.revision_no > material_revisions.revision_no
                            )
                    ) THEN
                        RAISE EXCEPTION 'Ocena wymaga bieżących rewizji, opublikowanego atestu i dowodu ze skanem CLEAN'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_supplier_compatibility_assessment_guard
                    BEFORE INSERT OR UPDATE OR DELETE
                    ON public.raw_material_supplier_compatibility_assessments
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_supplier_compatibility_assessment_guard();

                CREATE VIEW public.raw_material_supplier_compatibility_status
                    WITH (security_invoker = true)
                    AS
                    SELECT
                        products.id AS supplier_product_id,
                        products.organization_id,
                        latest_product_revision.id AS supplier_product_revision_id,
                        COALESCE(current_assessment.outcome, 'PENDING') AS status,
                        current_assessment.id AS current_assessment_id,
                        current_assessment.assessment_number,
                        current_assessment.assessed_at
                    FROM public.supplier_products products
                    LEFT JOIN LATERAL (
                        SELECT revisions.id, revisions.raw_material_id, revisions.raw_material_revision_id
                        FROM public.supplier_product_revisions revisions
                        WHERE revisions.supplier_product_id = products.id
                            AND revisions.organization_id = products.organization_id
                        ORDER BY revisions.revision_no DESC
                        LIMIT 1
                    ) latest_product_revision ON true
                    LEFT JOIN LATERAL (
                        SELECT assessments.id, assessments.assessment_number,
                            assessments.outcome, assessments.assessed_at
                        FROM public.raw_material_supplier_compatibility_assessments assessments
                        JOIN public.raw_materials materials
                            ON materials.id = assessments.raw_material_id
                            AND materials.organization_id = assessments.organization_id
                        JOIN public.raw_material_revision_allergen_attestations attestations
                            ON attestations.revision_id = assessments.raw_material_revision_id
                            AND attestations.organization_id = assessments.organization_id
                            AND attestations.catalog_version_id = assessments.allergen_catalog_version_id
                            AND attestations.attestation_status IN ('DECLARED', 'NO_KNOWN_ALLERGENS')
                        JOIN public.raw_material_allergen_catalog_versions catalog_versions
                            ON catalog_versions.id = attestations.catalog_version_id
                            AND catalog_versions.status = 'PUBLISHED'
                        JOIN public.raw_material_approved_compatibility_document_evidence evidence
                            ON evidence.document_version_id = assessments.document_version_id
                            AND evidence.organization_id = assessments.organization_id
                            AND evidence.sha256 = assessments.document_sha256
                            AND evidence.supplier_product_revision_id = assessments.supplier_product_revision_id
                            AND evidence.raw_material_revision_id = assessments.raw_material_revision_id
                        WHERE assessments.organization_id = products.organization_id
                            AND assessments.supplier_product_id = products.id
                            AND assessments.supplier_product_revision_id = latest_product_revision.id
                            AND assessments.raw_material_revision_id = materials.approved_revision_id
                            AND materials.pending_revision_id IS NULL
                            AND materials.working_revision_id IS NULL
                            AND NOT EXISTS (
                                SELECT 1
                                FROM public.raw_material_revisions newer_material_revisions
                                WHERE newer_material_revisions.organization_id = materials.organization_id
                                    AND newer_material_revisions.raw_material_id = materials.id
                                    AND newer_material_revisions.revision_no > (
                                        SELECT current_revision.revision_no
                                        FROM public.raw_material_revisions current_revision
                                        WHERE current_revision.id = assessments.raw_material_revision_id
                                    )
                            )
                        ORDER BY assessments.assessment_number DESC
                        LIMIT 1
                    ) current_assessment ON true;

                REVOKE ALL ON FUNCTION public.raw_material_supplier_compatibility_assessment_guard() FROM PUBLIC;
                REVOKE ALL ON TABLE public.raw_material_supplier_compatibility_assessments,
                    public.raw_material_supplier_compatibility_status FROM PUBLIC;
                GRANT SELECT, INSERT ON public.raw_material_supplier_compatibility_assessments TO veltra_app;
                GRANT SELECT ON public.raw_material_supplier_compatibility_status TO veltra_app;
                SQL);

            DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore([
                'table_name' => 'raw_material_supplier_compatibility_assessments',
            ]);
            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                'table_name' => 'raw_material_supplier_compatibility_assessments',
                'operation' => 'INSERT',
                'scope_prefix' => 'raw-material.commercial',
            ]);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER raw_material_supplier_compatibility_assessments_authorization_ticket
                    BEFORE INSERT ON public.raw_material_supplier_compatibility_assessments
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 032 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
