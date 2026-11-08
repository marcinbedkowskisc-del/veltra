<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.raw_material_location_shares (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                raw_material_id uuid NOT NULL,
                location_id uuid NOT NULL,
                created_by uuid NOT NULL,
                created_context_location_id uuid NOT NULL,
                created_operation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                revoked_by uuid,
                revoked_context_location_id uuid,
                revoked_operation_id uuid,
                revoked_at timestamptz,
                revocation_reason text,
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES public.raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (location_id, organization_id)
                    REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (created_context_location_id, organization_id)
                    REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (revoked_by, organization_id)
                    REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (revoked_context_location_id, organization_id)
                    REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT,
                CHECK ((revoked_at IS NULL AND revoked_by IS NULL
                        AND revoked_context_location_id IS NULL AND revoked_operation_id IS NULL
                        AND revocation_reason IS NULL)
                    OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL
                        AND revoked_context_location_id IS NOT NULL AND revoked_operation_id IS NOT NULL)),
                CHECK (revocation_reason IS NULL OR btrim(revocation_reason) <> ''),
                UNIQUE (created_operation_id)
            );

            CREATE UNIQUE INDEX raw_material_location_shares_one_active
                ON public.raw_material_location_shares (organization_id, raw_material_id, location_id)
                WHERE revoked_at IS NULL;
            CREATE UNIQUE INDEX raw_material_location_shares_revocation_operation_unique
                ON public.raw_material_location_shares (revoked_operation_id)
                WHERE revoked_operation_id IS NOT NULL;
            CREATE INDEX raw_material_location_shares_location_idx
                ON public.raw_material_location_shares (organization_id, location_id, raw_material_id)
                WHERE revoked_at IS NULL;
            CREATE INDEX raw_material_location_shares_history_idx
                ON public.raw_material_location_shares (organization_id, raw_material_id, location_id, created_at DESC);

            CREATE OR REPLACE FUNCTION public.raw_material_location_shares_validate()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            BEGIN
                PERFORM id FROM public.organizations WHERE id = NEW.organization_id FOR UPDATE;

                IF TG_OP = 'INSERT' AND (
                    NEW.revoked_at IS NOT NULL OR NEW.revoked_by IS NOT NULL
                    OR NEW.revoked_context_location_id IS NOT NULL
                    OR NEW.revoked_operation_id IS NOT NULL OR NEW.revocation_reason IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'A material share must begin active' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'INSERT' AND NOT EXISTS (
                    SELECT 1
                    FROM public.organizations organization
                    JOIN public.locations target_location
                        ON target_location.organization_id = organization.id
                        AND target_location.id = NEW.location_id
                        AND target_location.is_active
                    JOIN public.raw_materials material
                        ON material.organization_id = organization.id
                        AND material.id = NEW.raw_material_id
                        AND material.lifecycle_state = 'ACTIVE'
                        AND material.approved_revision_id IS NOT NULL
                    JOIN public.raw_material_revisions approved_revision
                        ON approved_revision.id = material.approved_revision_id
                        AND approved_revision.raw_material_id = material.id
                        AND approved_revision.organization_id = organization.id
                    WHERE organization.id = NEW.organization_id
                        AND organization.is_active
                        AND NOT organization.primary_central_kitchen_unresolved
                        AND EXISTS (
                            SELECT 1
                            FROM public.locations primary_kitchen
                            WHERE primary_kitchen.organization_id = organization.id
                                AND primary_kitchen.is_primary_central_kitchen
                                AND primary_kitchen.is_active
                        )
                        AND EXISTS (
                            SELECT 1
                            FROM public.raw_material_revision_approval_events approval
                            JOIN public.raw_material_revisions first_revision
                                ON first_revision.id = approval.revision_id
                                AND first_revision.organization_id = approval.organization_id
                            WHERE first_revision.raw_material_id = material.id
                                AND first_revision.revision_no = 1
                                AND approval.organization_id = organization.id
                                AND approval.outcome = 'APPROVED'
                                AND approval.content_hash = first_revision.content_hash
                        )
                        AND EXISTS (
                            SELECT 1
                            FROM public.raw_material_revision_approval_events approval
                            WHERE approval.organization_id = organization.id
                                AND approval.revision_id = approved_revision.id
                                AND approval.outcome = 'APPROVED'
                                AND approval.content_hash = approved_revision.content_hash
                        )
                ) THEN
                    RAISE EXCEPTION 'A material must have an approved first revision and be active before sharing'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $function$;

            CREATE OR REPLACE FUNCTION public.raw_material_location_shares_preserve_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Material share history cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.raw_material_id IS DISTINCT FROM OLD.raw_material_id
                    OR NEW.location_id IS DISTINCT FROM OLD.location_id
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_context_location_id IS DISTINCT FROM OLD.created_context_location_id
                    OR NEW.created_operation_id IS DISTINCT FROM OLD.created_operation_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR OLD.revoked_at IS NOT NULL
                    OR NEW.revoked_at IS NULL
                    OR NEW.revoked_by IS NULL
                    OR NEW.revoked_context_location_id IS NULL
                    OR NEW.revoked_operation_id IS NULL THEN
                    RAISE EXCEPTION 'Material share history is immutable except for one revocation'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $function$;

            CREATE OR REPLACE FUNCTION public.raw_material_location_shares_actor_validate()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            DECLARE
                ticket jsonb;
                actor_key uuid;
                context_key uuid;
            BEGIN
                ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                IF TG_OP = 'INSERT' THEN
                    actor_key := NEW.created_by;
                    context_key := NEW.created_context_location_id;
                ELSE
                    actor_key := NEW.revoked_by;
                    context_key := NEW.revoked_context_location_id;
                END IF;

                IF ticket->>'actor_id' IS DISTINCT FROM actor_key::text
                    OR NOT EXISTS (
                        SELECT 1
                        FROM public.users actor
                        JOIN public.organizations organization
                            ON organization.id = actor.organization_id
                            AND organization.is_active
                            AND organization.authorization_initialized
                            AND NOT organization.primary_central_kitchen_unresolved
                        JOIN public.role_assignments administrator
                            ON administrator.organization_id = actor.organization_id
                            AND administrator.user_id = actor.id
                            AND administrator.role = 'ADMINISTRATOR'
                            AND administrator.location_id IS NULL
                            AND administrator.revoked_at IS NULL
                        JOIN public.locations primary_kitchen
                            ON primary_kitchen.organization_id = organization.id
                            AND primary_kitchen.type = 'CENTRAL_KITCHEN'
                            AND primary_kitchen.is_primary_central_kitchen
                            AND primary_kitchen.is_active
                        JOIN public.locations context_location
                            ON context_location.organization_id = actor.organization_id
                            AND context_location.id = context_key
                            AND context_location.is_active
                        WHERE actor.id = actor_key
                            AND actor.is_active
                            AND actor.email_verified_at IS NOT NULL
                            AND EXISTS (
                                SELECT 1
                                FROM public.location_memberships membership
                                WHERE membership.organization_id = actor.organization_id
                                    AND membership.user_id = actor.id
                                    AND membership.location_id = context_key
                                    AND membership.is_active
                            )
                    ) THEN
                    RAISE EXCEPTION 'A material share requires an active administrator and location membership'
                        USING ERRCODE = '42501';
                END IF;

                RETURN NEW;
            END
            $function$;

            CREATE OR REPLACE FUNCTION public.raw_material_location_shares_audit_validate()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            DECLARE
                actor_key uuid;
                context_key uuid;
                operation_key uuid;
                expected_event text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    actor_key := NEW.created_by;
                    context_key := NEW.created_context_location_id;
                    operation_key := NEW.created_operation_id;
                    expected_event := 'LOCATION_SHARE_CREATED';
                ELSE
                    actor_key := NEW.revoked_by;
                    context_key := NEW.revoked_context_location_id;
                    operation_key := NEW.revoked_operation_id;
                    expected_event := 'LOCATION_SHARE_REVOKED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM public.raw_material_audit_events audit
                    WHERE audit.organization_id = NEW.organization_id
                        AND audit.operation_id = operation_key
                        AND audit.actor_user_id = actor_key
                        AND audit.actor_location_id = context_key
                        AND audit.subject_type = 'RAW_MATERIAL_SHARE'
                        AND audit.subject_id = NEW.id
                        AND audit.event_code = expected_event
                        AND audit.outcome = 'ACCEPTED'
                        AND audit.metadata->>'raw_material_id' = NEW.raw_material_id::text
                        AND audit.metadata->>'location_id' = NEW.location_id::text
                ) THEN
                    RAISE EXCEPTION 'A material share mutation requires matching audit history'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END
            $function$;

            CREATE TRIGGER raw_material_location_shares_authorization_ticket
                BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_location_shares
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
            CREATE TRIGGER raw_material_location_shares_validate
                BEFORE INSERT OR UPDATE ON public.raw_material_location_shares
                FOR EACH ROW EXECUTE FUNCTION public.raw_material_location_shares_validate();
            CREATE TRIGGER raw_material_location_shares_preserve_history
                BEFORE UPDATE OR DELETE ON public.raw_material_location_shares
                FOR EACH ROW EXECUTE FUNCTION public.raw_material_location_shares_preserve_history();
            CREATE TRIGGER raw_material_location_shares_z_actor_validate
                BEFORE INSERT OR UPDATE ON public.raw_material_location_shares
                FOR EACH ROW EXECUTE FUNCTION public.raw_material_location_shares_actor_validate();
            CREATE CONSTRAINT TRIGGER raw_material_location_shares_audit_required
                AFTER INSERT OR UPDATE ON public.raw_material_location_shares
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION public.raw_material_location_shares_audit_validate();
            SQL);

        DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore([
            'table_name' => 'raw_material_location_shares',
        ]);
        DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
            'table_name' => 'raw_material_location_shares',
            'operation' => 'INSERT',
            'scope_prefix' => 'raw-material.share',
        ]);
        DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
            'table_name' => 'raw_material_location_shares',
            'operation' => 'UPDATE',
            'scope_prefix' => 'raw-material.share',
        ]);
        DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
            'table_name' => 'raw_material_audit_events',
            'operation' => 'INSERT',
            'scope_prefix' => 'raw-material.share',
        ]);

        $roles = DB::selectOne(<<<'SQL'
            SELECT runtime_role
            FROM veltra_private.mutation_signing_keys
            WHERE singleton
            SQL);
        $role = DB::selectOne('SELECT quote_ident(?) AS role_name', [$roles->runtime_role])->role_name;
        DB::unprepared("REVOKE ALL ON TABLE public.raw_material_location_shares FROM PUBLIC, {$role}");
        DB::unprepared("GRANT SELECT, INSERT, UPDATE ON TABLE public.raw_material_location_shares TO {$role}");
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 041 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
