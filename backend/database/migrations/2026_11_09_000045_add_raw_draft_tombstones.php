<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")
                || ! DB::scalar("SELECT to_regclass('public.raw_materials') IS NOT NULL")
                || ! DB::scalar("SELECT to_regclass('public.raw_material_audit_events') IS NOT NULL")) {
                throw new RuntimeException('Tombstone RAW wymaga istniejącej podpisanej granicy mutacji i historii audytowej.');
            }

            DB::unprepared(<<<'SQL'
                ALTER TABLE public.raw_materials
                    ADD COLUMN deleted_at timestamptz,
                    ADD COLUMN deleted_by uuid,
                    ADD COLUMN deleted_operation_id uuid,
                    ADD CONSTRAINT raw_materials_deleted_by_fk
                        FOREIGN KEY (deleted_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    ADD CONSTRAINT raw_materials_deleted_fields_consistent CHECK (
                        (deleted_at IS NULL AND deleted_by IS NULL AND deleted_operation_id IS NULL)
                        OR (deleted_at IS NOT NULL AND deleted_by IS NOT NULL AND deleted_operation_id IS NOT NULL)
                    );
                CREATE INDEX raw_materials_deleted_catalog_idx
                    ON public.raw_materials(organization_id, lifecycle_state, code)
                    WHERE deleted_at IS NULL;

                ALTER TABLE public.organization_permission_grants
                    DROP CONSTRAINT organization_permission_grants_permission_check,
                    ADD CONSTRAINT organization_permission_grants_permission_check CHECK (permission IN (
                        'RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE', 'RAW_MATERIALS_EDIT',
                        'RAW_MATERIALS_SUBMIT', 'RAW_MATERIALS_REVIEW', 'RAW_MATERIALS_APPROVE',
                        'RAW_MATERIALS_BLOCK', 'RAW_MATERIALS_UNBLOCK', 'RAW_MATERIALS_ARCHIVE',
                        'RAW_MATERIALS_RESTORE', 'RAW_MATERIALS_DELETE_DRAFT',
                        'RAW_MATERIALS_CATEGORY_MANAGE',
                        'RAW_MATERIALS_SUPPLIER_VIEW', 'RAW_MATERIALS_SUPPLIER_MANAGE',
                        'RAW_MATERIALS_PURCHASING_COSTS_VIEW', 'RAW_MATERIALS_PURCHASING_COSTS_MANAGE',
                        'RAW_MATERIALS_REFERENCE_PRICE_VIEW', 'RAW_MATERIALS_REFERENCE_PRICE_MANAGE',
                        'RAW_MATERIALS_EXCHANGE_RATE_VIEW', 'RAW_MATERIALS_EXCHANGE_RATE_MANAGE',
                        'RAW_MATERIALS_ALLERGEN_MANAGE', 'RAW_MATERIALS_STORAGE_MANAGE',
                        'RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE', 'RAW_MATERIALS_AUDIT_VIEW',
                        'RAW_MATERIALS_PERMISSION_DELEGATE'
                    ));

                CREATE OR REPLACE FUNCTION public.raw_materials_draft_has_dependencies(
                    organization_key uuid,
                    material_key uuid
                )
                RETURNS boolean
                LANGUAGE sql
                STABLE
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                    SELECT
                        EXISTS (
                            SELECT 1
                            FROM public.raw_material_revision_approval_events approvals
                            JOIN public.raw_material_revisions revisions
                                ON revisions.id = approvals.revision_id
                                AND revisions.organization_id = approvals.organization_id
                            WHERE revisions.organization_id = organization_key
                                AND revisions.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.supplier_products products
                            WHERE products.organization_id = organization_key
                                AND products.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_reference_price_events prices
                            WHERE prices.organization_id = organization_key
                                AND prices.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_financial_restatements restatements
                            WHERE restatements.organization_id = organization_key
                                AND restatements.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_import_metadata_events imports
                            WHERE imports.organization_id = organization_key
                                AND imports.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_import_rows imports
                            WHERE imports.organization_id = organization_key
                                AND imports.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_catalog_import_rows imports
                            WHERE imports.organization_id = organization_key
                                AND imports.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_location_shares shares
                            WHERE shares.organization_id = organization_key
                                AND shares.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1 FROM public.raw_material_supplier_compatibility_assessments assessments
                            WHERE assessments.organization_id = organization_key
                                AND assessments.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM public.raw_materials_commercial_audit_events events
                            WHERE events.organization_id = organization_key
                                AND events.subject_type = 'RAW_MATERIAL'
                                AND events.subject_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM public.raw_material_revisions revisions
                            JOIN public.raw_material_document_version_links links
                                ON links.raw_material_revision_id = revisions.id
                                AND links.organization_id = revisions.organization_id
                            WHERE revisions.organization_id = organization_key
                                AND revisions.raw_material_id = material_key
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM public.raw_material_revisions revisions
                            JOIN public.raw_material_revision_document_sets sets
                                ON sets.revision_id = revisions.id
                                AND sets.organization_id = revisions.organization_id
                            WHERE revisions.organization_id = organization_key
                                AND revisions.raw_material_id = material_key
                                AND jsonb_array_length(sets.document_version_ids) > 0
                        )
                $function$;

                CREATE OR REPLACE FUNCTION public.raw_materials_draft_dependency_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    material_key uuid;
                    revision_key uuid;
                    product_key uuid;
                BEGIN
                    PERFORM id FROM public.organizations WHERE id = NEW.organization_id FOR UPDATE;

                    IF TG_ARGV[1] = 'material' THEN
                        material_key := (to_jsonb(NEW)->>TG_ARGV[0])::uuid;
                    ELSIF TG_ARGV[1] = 'revision' THEN
                        revision_key := (to_jsonb(NEW)->>TG_ARGV[0])::uuid;
                        SELECT revisions.raw_material_id INTO material_key
                        FROM public.raw_material_revisions revisions
                        WHERE revisions.id = revision_key
                            AND revisions.organization_id = NEW.organization_id;
                    ELSIF TG_ARGV[1] = 'product' THEN
                        product_key := (to_jsonb(NEW)->>TG_ARGV[0])::uuid;
                        SELECT products.raw_material_id INTO material_key
                        FROM public.supplier_products products
                        WHERE products.id = product_key
                            AND products.organization_id = NEW.organization_id;
                    ELSIF TG_ARGV[1] = 'commercial_audit' THEN
                        IF NEW.subject_type = 'RAW_MATERIAL' THEN
                            material_key := NEW.subject_id;
                        END IF;
                    END IF;

                    IF material_key IS NOT NULL THEN
                        PERFORM id
                        FROM public.raw_materials
                        WHERE id = material_key AND organization_id = NEW.organization_id
                        FOR UPDATE;

                        IF EXISTS (
                            SELECT 1 FROM public.raw_materials
                            WHERE id = material_key AND organization_id = NEW.organization_id
                                AND deleted_at IS NOT NULL
                        ) THEN
                            RAISE EXCEPTION 'Nie można tworzyć zależności dla usuniętego szkicu RAW'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;

                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER supplier_products_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.supplier_products
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_revisions_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_revisions
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_revision_units_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_revision_units
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('revision_id', 'revision');
                CREATE TRIGGER raw_material_aliases_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_aliases
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('revision_id', 'revision');
                CREATE TRIGGER raw_material_allergen_attestations_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_revision_allergen_attestations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('revision_id', 'revision');
                CREATE TRIGGER raw_material_allergen_declarations_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_revision_allergen_declarations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('revision_id', 'revision');
                CREATE TRIGGER raw_material_storage_variants_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_revision_storage_variants
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('revision_id', 'revision');
                CREATE TRIGGER raw_material_reference_prices_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_reference_price_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_restatements_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_financial_restatements
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_import_metadata_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_import_metadata_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_import_rows_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_import_rows
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_catalog_import_rows_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_catalog_import_rows
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_location_shares_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_location_shares
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_compatibility_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_supplier_compatibility_assessments
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_id', 'material');
                CREATE TRIGGER raw_material_document_links_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_document_version_links
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('raw_material_revision_id', 'revision');
                CREATE TRIGGER raw_material_document_sets_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_revision_document_sets
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('revision_id', 'revision');
                CREATE TRIGGER raw_material_commercial_audit_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_materials_commercial_audit_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('subject_id', 'commercial_audit');
                CREATE TRIGGER raw_material_sku_reservations_draft_dependency_guard
                    BEFORE INSERT OR UPDATE ON public.raw_material_sku_reservations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_dependency_guard('id', 'material');

                CREATE FUNCTION public.raw_materials_draft_delete_grant_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF NEW.permission = 'RAW_MATERIALS_DELETE_DRAFT'
                        AND NEW.delegation_source_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Uprawnienia do usunięcia szkicu RAW nie można delegować'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER organization_permission_grants_draft_delete_non_delegable
                    BEFORE INSERT OR UPDATE ON public.organization_permission_grants
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_delete_grant_guard();

                CREATE OR REPLACE FUNCTION public.raw_materials_preserve_master()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $$
                DECLARE
                    ticket jsonb;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Raw materials cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.code IS DISTINCT FROM OLD.code OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Raw material identity and code are immutable' USING ERRCODE = '23514';
                    END IF;

                    IF OLD.deleted_at IS NOT NULL THEN
                        RAISE EXCEPTION 'A deleted RAW draft is immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.deleted_at IS NULL AND (
                        NEW.deleted_by IS NOT NULL OR NEW.deleted_operation_id IS NOT NULL
                    ) THEN
                        RAISE EXCEPTION 'RAW draft tombstone fields must be set together' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN
                        ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                        IF NEW.deleted_at IS NULL
                            OR NEW.deleted_by IS NULL
                            OR NEW.deleted_operation_id IS NULL
                            OR NEW.deleted_by::text IS DISTINCT FROM ticket->>'actor_id'
                            OR ticket->>'scope' IS DISTINCT FROM 'raw-material.draft.delete'
                            OR ticket->>'subject_id' IS DISTINCT FROM NEW.id::text
                            OR NEW.lifecycle_state IS DISTINCT FROM 'DRAFT'
                            OR NEW.approved_revision_id IS NOT NULL
                            OR NEW.pending_revision_id IS NOT NULL
                            OR NEW.working_revision_id IS NULL
                            OR (to_jsonb(NEW) - ARRAY[
                                'deleted_at', 'deleted_by', 'deleted_operation_id', 'updated_at', 'edit_version'
                            ]::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY[
                                'deleted_at', 'deleted_by', 'deleted_operation_id', 'updated_at', 'edit_version'
                            ]::text[])
                            OR EXISTS (
                                SELECT 1
                                FROM public.raw_material_revision_approval_events approvals
                                JOIN public.raw_material_revisions revisions
                                    ON revisions.id = approvals.revision_id
                                    AND revisions.organization_id = approvals.organization_id
                                WHERE revisions.organization_id = NEW.organization_id
                                    AND revisions.raw_material_id = NEW.id
                                    AND approvals.outcome IN ('APPROVED', 'RETURNED')
                            )
                            OR public.raw_materials_draft_has_dependencies(NEW.organization_id, NEW.id)
                            OR NOT EXISTS (
                                SELECT 1
                                FROM public.raw_material_audit_events events
                                WHERE events.organization_id = NEW.organization_id
                                    AND events.subject_type = 'RAW_MATERIAL'
                                    AND events.subject_id = NEW.id
                                    AND events.event_code = 'DRAFT_DELETED'
                                    AND events.outcome = 'ACCEPTED'
                                    AND events.actor_user_id = NEW.deleted_by
                                    AND events.operation_id = NEW.deleted_operation_id
                                    AND events.transaction_id = txid_current()
                            ) THEN
                            RAISE EXCEPTION 'RAW draft tombstone is not authorized or eligible'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;

                    IF NEW.approved_revision_id IS DISTINCT FROM OLD.approved_revision_id AND (
                        NEW.approved_revision_id IS NULL
                        OR NEW.approved_revision_id IS DISTINCT FROM OLD.pending_revision_id
                        OR NOT EXISTS (
                            SELECT 1
                            FROM public.raw_material_revision_approval_events approvals
                            JOIN public.raw_material_revisions revisions
                                ON revisions.id = approvals.revision_id
                            WHERE approvals.organization_id = NEW.organization_id
                                AND approvals.revision_id = NEW.approved_revision_id
                                AND approvals.outcome = 'APPROVED'
                                AND approvals.content_hash = revisions.content_hash
                        )
                    ) THEN
                        RAISE EXCEPTION 'An approved revision requires a matching approval event' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.pending_revision_id IS NOT NULL
                        AND NEW.pending_revision_id IS DISTINCT FROM OLD.pending_revision_id
                        AND NEW.pending_revision_id IS DISTINCT FROM OLD.working_revision_id THEN
                        RAISE EXCEPTION 'A pending revision must be the current working revision' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.working_revision_id IS NOT NULL
                        AND NEW.working_revision_id IS DISTINCT FROM OLD.working_revision_id
                        AND NEW.working_revision_id IS DISTINCT FROM OLD.pending_revision_id
                        AND EXISTS (
                            SELECT 1
                            FROM public.raw_material_revisions
                            WHERE id = NEW.working_revision_id
                        ) THEN
                        RAISE EXCEPTION 'A working revision cannot reuse a historical revision' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state AND NOT (
                        (OLD.lifecycle_state = 'DRAFT' AND NEW.lifecycle_state = 'VERIFICATION')
                        OR (OLD.lifecycle_state = 'VERIFICATION' AND NEW.lifecycle_state IN ('DRAFT', 'ACTIVE'))
                        OR (OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state IN ('BLOCKED', 'ARCHIVED'))
                        OR (OLD.lifecycle_state = 'BLOCKED' AND NEW.lifecycle_state IN ('ACTIVE', 'ARCHIVED'))
                        OR (OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state = 'VERIFICATION')
                    ) THEN
                        RAISE EXCEPTION 'Raw material lifecycle transition is invalid' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $$;

                CREATE OR REPLACE FUNCTION public.raw_materials_draft_delete_audit_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                BEGIN
                    ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                    IF (NEW.event_code = 'DRAFT_DELETED'
                        OR ticket->>'scope' = 'raw-material.draft.delete')
                        AND (
                            ticket->>'scope' IS DISTINCT FROM 'raw-material.draft.delete'
                            OR
                            NEW.subject_type IS DISTINCT FROM 'RAW_MATERIAL'
                            OR NEW.subject_id::text IS DISTINCT FROM ticket->>'subject_id'
                            OR NEW.actor_user_id::text IS DISTINCT FROM ticket->>'actor_id'
                            OR NEW.event_code IS DISTINCT FROM 'DRAFT_DELETED'
                            OR NEW.outcome IS DISTINCT FROM 'ACCEPTED'
                            OR NEW.transaction_id IS DISTINCT FROM txid_current()
                        ) THEN
                        RAISE EXCEPTION 'RAW draft deletion audit does not match the signed operation'
                            USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_material_audit_events_draft_delete_validate
                    BEFORE INSERT ON public.raw_material_audit_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_draft_delete_audit_guard();
                CREATE UNIQUE INDEX raw_material_audit_draft_delete_once
                    ON public.raw_material_audit_events(organization_id, subject_id)
                    WHERE subject_type = 'RAW_MATERIAL' AND event_code = 'DRAFT_DELETED';

                INSERT INTO veltra_private.authorization_mutation_scopes(table_name, operation, scope_prefix)
                VALUES
                    ('raw_materials', 'UPDATE', 'raw-material.draft.delete'),
                    ('raw_material_audit_events', 'INSERT', 'raw-material.draft.delete');

                REVOKE ALL ON FUNCTION public.raw_materials_draft_has_dependencies(uuid, uuid) FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_materials_draft_dependency_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_materials_draft_delete_grant_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_materials_draft_delete_audit_guard() FROM PUBLIC;
                GRANT EXECUTE ON FUNCTION public.raw_materials_draft_has_dependencies(uuid, uuid) TO veltra_app;
                GRANT UPDATE (deleted_at, deleted_by, deleted_operation_id, updated_at)
                    ON public.raw_materials TO veltra_app;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 045 jest forward-only; tombstone i historia SKU nie mogą być cofnięte.');
    }
};
