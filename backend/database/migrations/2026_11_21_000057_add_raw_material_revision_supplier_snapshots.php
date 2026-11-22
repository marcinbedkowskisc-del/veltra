<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")
            || ! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")) {
            throw new RuntimeException('Migracja 057 wymaga istniejącej granicy podpisanych mutacji.');
        }

        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                CREATE TABLE public.raw_material_revision_supplier_proposals (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    raw_material_id uuid NOT NULL,
                    revision_id uuid NOT NULL,
                    state varchar(20) NOT NULL
                        CHECK (state IN ('NOT_PROVIDED', 'PROPOSED_EMPTY', 'PROPOSED_NONEMPTY')),
                    created_by uuid NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (organization_id, revision_id),
                    FOREIGN KEY (revision_id, raw_material_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, raw_material_id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (created_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_revision_supplier_proposal_items (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    revision_id uuid NOT NULL,
                    supplier_id uuid NOT NULL,
                    UNIQUE (organization_id, revision_id, supplier_id),
                    UNIQUE (id, organization_id, revision_id),
                    FOREIGN KEY (organization_id, revision_id)
                        REFERENCES public.raw_material_revision_supplier_proposals(organization_id, revision_id) ON DELETE RESTRICT,
                    FOREIGN KEY (supplier_id, organization_id)
                        REFERENCES public.organization_suppliers(id, organization_id) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_approved_supplier_snapshots (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    raw_material_id uuid NOT NULL,
                    revision_id uuid NOT NULL,
                    approval_event_id uuid NOT NULL
                        REFERENCES public.raw_material_revision_approval_events(id) ON DELETE RESTRICT,
                    approval_operation_id uuid NOT NULL,
                    state varchar(20) NOT NULL
                        CHECK (state IN ('APPROVED_EMPTY', 'APPROVED_NONEMPTY')),
                    created_by uuid NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    UNIQUE (organization_id, revision_id),
                    UNIQUE (id, organization_id, revision_id),
                    FOREIGN KEY (revision_id, raw_material_id, organization_id)
                        REFERENCES public.raw_material_revisions(id, raw_material_id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (created_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT
                );

                CREATE TABLE public.raw_material_approved_supplier_snapshot_items (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL,
                    revision_id uuid NOT NULL,
                    snapshot_id uuid NOT NULL,
                    supplier_id uuid NOT NULL,
                    UNIQUE (organization_id, revision_id, supplier_id),
                    FOREIGN KEY (snapshot_id, organization_id, revision_id)
                        REFERENCES public.raw_material_approved_supplier_snapshots(id, organization_id, revision_id) ON DELETE RESTRICT,
                    FOREIGN KEY (supplier_id, organization_id)
                        REFERENCES public.organization_suppliers(id, organization_id) ON DELETE RESTRICT
                );
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.raw_material_revision_supplier_proposal_guard()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    target_org uuid;
                    target_revision uuid;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        target_org := OLD.organization_id;
                        target_revision := OLD.revision_id;
                    ELSE
                        target_org := NEW.organization_id;
                        target_revision := NEW.revision_id;
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.id IS DISTINCT FROM OLD.id
                            OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                            OR NEW.raw_material_id IS DISTINCT FROM OLD.raw_material_id
                            OR NEW.revision_id IS DISTINCT FROM OLD.revision_id
                            OR NEW.created_by IS DISTINCT FROM OLD.created_by
                            OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                            RAISE EXCEPTION 'Supplier proposal identity and ownership are immutable'
                                USING ERRCODE = '23514';
                        END IF;
                        target_org := OLD.organization_id;
                        target_revision := OLD.revision_id;
                    END IF;
                    PERFORM id FROM public.organizations
                    WHERE id = target_org
                    FOR UPDATE;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.raw_material_revisions revisions
                        JOIN public.raw_materials materials
                          ON materials.id = revisions.raw_material_id
                         AND materials.organization_id = revisions.organization_id
                        WHERE revisions.id = target_revision
                          AND revisions.organization_id = target_org
                          AND revisions.id IS NOT DISTINCT FROM materials.working_revision_id
                          AND materials.deleted_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Supplier proposals may only change the current working revision'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    NEW.updated_at := clock_timestamp();
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_revision_supplier_proposal_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_supplier_proposals
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_supplier_proposal_guard();

                CREATE OR REPLACE FUNCTION public.raw_material_revision_supplier_proposal_item_guard()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    target_revision uuid;
                    target_org uuid;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        target_revision := OLD.revision_id;
                        target_org := OLD.organization_id;
                        PERFORM id FROM public.organizations
                        WHERE id = OLD.organization_id
                        FOR UPDATE;
                    ELSE
                        target_revision := NEW.revision_id;
                        target_org := NEW.organization_id;
                        PERFORM id FROM public.organizations
                        WHERE id = NEW.organization_id
                        FOR UPDATE;
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.id IS DISTINCT FROM OLD.id
                            OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                            OR NEW.revision_id IS DISTINCT FROM OLD.revision_id
                            OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id THEN
                            RAISE EXCEPTION 'Supplier proposal item membership identity is immutable'
                                USING ERRCODE = '23514';
                        END IF;
                        target_revision := OLD.revision_id;
                        target_org := OLD.organization_id;
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.raw_material_revisions revisions
                        JOIN public.raw_materials materials
                          ON materials.id = revisions.raw_material_id
                         AND materials.organization_id = revisions.organization_id
                        WHERE revisions.id = target_revision
                          AND revisions.organization_id = target_org
                          AND revisions.id IS NOT DISTINCT FROM materials.working_revision_id
                          AND materials.deleted_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Supplier proposal items may only change the current working revision'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.raw_material_revision_supplier_proposals proposals
                        WHERE proposals.organization_id = NEW.organization_id
                          AND proposals.revision_id = NEW.revision_id
                          AND proposals.state = 'PROPOSED_NONEMPTY'
                    ) THEN
                        RAISE EXCEPTION 'Supplier proposal item requires an explicit non-empty proposal'
                            USING ERRCODE = '23514';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.organization_suppliers suppliers
                        WHERE suppliers.id = NEW.supplier_id
                          AND suppliers.organization_id = NEW.organization_id
                          AND suppliers.lifecycle_state = 'ACTIVE'
                    ) THEN
                        RAISE EXCEPTION 'Supplier proposal item requires an active organization supplier'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_revision_supplier_proposal_item_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_revision_supplier_proposal_items
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_supplier_proposal_item_guard();

                CREATE OR REPLACE FUNCTION public.raw_material_revision_supplier_proposal_item_consistency_validate()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    target_revision uuid;
                    target_org uuid;
                    proposal_state varchar(20);
                    item_count bigint;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        target_revision := OLD.revision_id;
                        target_org := OLD.organization_id;
                    ELSE
                        target_revision := NEW.revision_id;
                        target_org := NEW.organization_id;
                    END IF;
                    SELECT proposals.state INTO proposal_state
                    FROM public.raw_material_revision_supplier_proposals proposals
                    WHERE proposals.organization_id = target_org
                      AND proposals.revision_id = target_revision;
                    SELECT count(*) INTO item_count
                    FROM public.raw_material_revision_supplier_proposal_items items
                    WHERE items.organization_id = target_org
                      AND items.revision_id = target_revision;
                    IF (proposal_state IN ('NOT_PROVIDED', 'PROPOSED_EMPTY') AND item_count <> 0)
                        OR (proposal_state = 'PROPOSED_NONEMPTY' AND item_count = 0) THEN
                        RAISE EXCEPTION 'Supplier proposal item set does not match proposal state'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;

                CREATE CONSTRAINT TRIGGER raw_material_revision_supplier_proposal_item_consistency
                    AFTER INSERT OR DELETE ON public.raw_material_revision_supplier_proposal_items
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_supplier_proposal_item_consistency_validate();

                CREATE OR REPLACE FUNCTION public.raw_material_revision_supplier_proposal_consistency_validate()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    item_count bigint;
                BEGIN
                    SELECT count(*) INTO item_count
                    FROM public.raw_material_revision_supplier_proposal_items items
                    WHERE items.organization_id = NEW.organization_id
                      AND items.revision_id = NEW.revision_id;
                    IF (NEW.state IN ('NOT_PROVIDED', 'PROPOSED_EMPTY') AND item_count <> 0)
                        OR (NEW.state = 'PROPOSED_NONEMPTY' AND item_count = 0) THEN
                        RAISE EXCEPTION 'Supplier proposal state does not match items'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;

                CREATE CONSTRAINT TRIGGER raw_material_revision_supplier_proposal_consistency
                    AFTER INSERT OR UPDATE ON public.raw_material_revision_supplier_proposals
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_revision_supplier_proposal_consistency_validate();

                CREATE OR REPLACE FUNCTION public.raw_material_approved_supplier_snapshot_guard()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    approval public.raw_material_revision_approval_events%ROWTYPE;
                    revision public.raw_material_revisions%ROWTYPE;
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM id FROM public.organizations
                        WHERE id = NEW.organization_id
                        FOR UPDATE;
                    ELSE
                        PERFORM id FROM public.organizations
                        WHERE id = OLD.organization_id
                        FOR UPDATE;
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Approved supplier snapshots are immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION 'Approved supplier snapshots are immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO approval
                    FROM public.raw_material_revision_approval_events
                    WHERE id = NEW.approval_event_id;
                    IF approval.id IS NULL
                        OR approval.organization_id IS DISTINCT FROM NEW.organization_id
                        OR approval.revision_id IS DISTINCT FROM NEW.revision_id
                        OR approval.operation_id IS DISTINCT FROM NEW.approval_operation_id
                        OR approval.outcome IS DISTINCT FROM 'APPROVED' THEN
                        RAISE EXCEPTION 'Approved supplier snapshot does not match approval event'
                            USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO revision
                    FROM public.raw_material_revisions
                    WHERE id = NEW.revision_id
                      AND raw_material_id = NEW.raw_material_id
                      AND organization_id = NEW.organization_id;
                    IF revision.id IS NULL
                        OR approval.content_hash IS DISTINCT FROM revision.content_hash THEN
                        RAISE EXCEPTION 'Approved supplier snapshot does not match approved revision'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_approved_supplier_snapshot_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_approved_supplier_snapshots
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_approved_supplier_snapshot_guard();

                CREATE OR REPLACE FUNCTION public.raw_material_approved_supplier_snapshot_item_guard()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION 'Approved supplier snapshot items are immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    PERFORM id FROM public.organizations
                    WHERE id = NEW.organization_id
                    FOR UPDATE;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.raw_material_approved_supplier_snapshots snapshots
                        WHERE snapshots.id = NEW.snapshot_id
                          AND snapshots.organization_id = NEW.organization_id
                          AND snapshots.revision_id = NEW.revision_id
                          AND snapshots.state = 'APPROVED_NONEMPTY'
                    ) THEN
                        RAISE EXCEPTION 'Approved supplier snapshot item requires a non-empty snapshot'
                            USING ERRCODE = '23514';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.organization_suppliers suppliers
                        WHERE suppliers.id = NEW.supplier_id
                          AND suppliers.organization_id = NEW.organization_id
                          AND suppliers.lifecycle_state = 'ACTIVE'
                    ) THEN
                        RAISE EXCEPTION 'Approved supplier snapshot requires an active supplier'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER raw_material_approved_supplier_snapshot_item_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_approved_supplier_snapshot_items
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_approved_supplier_snapshot_item_guard();

                CREATE OR REPLACE FUNCTION public.raw_material_approved_supplier_snapshot_consistency_validate()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    item_count bigint;
                BEGIN
                    SELECT count(*) INTO item_count
                    FROM public.raw_material_approved_supplier_snapshot_items items
                    WHERE items.snapshot_id = NEW.id
                      AND items.organization_id = NEW.organization_id
                      AND items.revision_id = NEW.revision_id;
                    IF (NEW.state = 'APPROVED_EMPTY' AND item_count <> 0)
                        OR (NEW.state = 'APPROVED_NONEMPTY' AND item_count = 0) THEN
                        RAISE EXCEPTION 'Approved supplier snapshot state does not match items'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;

                CREATE CONSTRAINT TRIGGER raw_material_approved_supplier_snapshot_consistency
                    AFTER INSERT OR UPDATE ON public.raw_material_approved_supplier_snapshots
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.raw_material_approved_supplier_snapshot_consistency_validate();
                SQL);

            DB::unprepared(<<<'SQL'
                REVOKE ALL ON FUNCTION public.raw_material_revision_supplier_proposal_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_revision_supplier_proposal_item_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_revision_supplier_proposal_item_consistency_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_revision_supplier_proposal_consistency_validate() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_approved_supplier_snapshot_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_approved_supplier_snapshot_item_guard() FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_material_approved_supplier_snapshot_consistency_validate() FROM PUBLIC;
                SQL);

            $runtimeRole = (string) env('APP_DB_USERNAME', 'veltra_app');
            $runtimeRoleQuoted = DB::selectOne('SELECT quote_ident(?) AS role_name', [$runtimeRole])->role_name;

            foreach ([
                'raw_material_revision_supplier_proposals',
                'raw_material_revision_supplier_proposal_items',
                'raw_material_approved_supplier_snapshots',
                'raw_material_approved_supplier_snapshot_items',
            ] as $table) {
                DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore(['table_name' => $table]);
            }

            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                ['table_name' => 'raw_material_revision_supplier_proposals', 'operation' => 'INSERT', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_revision_supplier_proposals', 'operation' => 'UPDATE', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_revision_supplier_proposals', 'operation' => 'DELETE', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_revision_supplier_proposal_items', 'operation' => 'INSERT', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_revision_supplier_proposal_items', 'operation' => 'DELETE', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_revision_supplier_proposal_items', 'operation' => 'UPDATE', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_revision_supplier_proposals', 'operation' => 'DELETE', 'scope_prefix' => 'raw-material.draft.delete'],
                ['table_name' => 'raw_material_revision_supplier_proposal_items', 'operation' => 'DELETE', 'scope_prefix' => 'raw-material.draft.delete'],
                ['table_name' => 'raw_material_approved_supplier_snapshots', 'operation' => 'INSERT', 'scope_prefix' => 'raw-material.mutate'],
                ['table_name' => 'raw_material_approved_supplier_snapshot_items', 'operation' => 'INSERT', 'scope_prefix' => 'raw-material.mutate'],
            ]);

            foreach ([
                'raw_material_revision_supplier_proposals',
                'raw_material_revision_supplier_proposal_items',
                'raw_material_approved_supplier_snapshots',
                'raw_material_approved_supplier_snapshot_items',
            ] as $table) {
                DB::unprepared(sprintf(
                    'CREATE TRIGGER %s_authorization_ticket BEFORE INSERT OR UPDATE OR DELETE ON public.%s FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation()',
                    $table,
                    $table,
                ));
            }

            DB::unprepared(sprintf(
                'REVOKE UPDATE, DELETE ON TABLE public.raw_material_approved_supplier_snapshots, public.raw_material_approved_supplier_snapshot_items FROM %s',
                $runtimeRoleQuoted,
            ));
            DB::unprepared(sprintf(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.raw_material_revision_supplier_proposals, public.raw_material_revision_supplier_proposal_items TO %s',
                $runtimeRoleQuoted,
            ));
            DB::unprepared(sprintf(
                'GRANT SELECT, INSERT ON TABLE public.raw_material_approved_supplier_snapshots, public.raw_material_approved_supplier_snapshot_items TO %s',
                $runtimeRoleQuoted,
            ));
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 057 jest nieodwracalna; powrót wymaga osobnej forward-only migracji.');
    }
};
