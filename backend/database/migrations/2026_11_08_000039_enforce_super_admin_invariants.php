<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO veltra_private.authorization_mutation_scopes (table_name, operation, scope_prefix)
            VALUES ('raw_material_audit_events', 'INSERT', 'account.deactivate')
            ON CONFLICT (table_name, operation, scope_prefix) DO NOTHING;

            CREATE OR REPLACE FUNCTION veltra_private.super_admin_actor(actor_key uuid)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public, veltra_private
            AS $function$
                SELECT actor_key IS NOT NULL AND EXISTS (
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
                        AND administrator.revoked_at IS NULL
                    JOIN public.location_memberships primary_membership
                        ON primary_membership.organization_id = actor.organization_id
                        AND primary_membership.user_id = actor.id
                        AND primary_membership.is_active
                    JOIN public.locations primary_kitchen
                        ON primary_kitchen.id = primary_membership.location_id
                        AND primary_kitchen.organization_id = actor.organization_id
                        AND primary_kitchen.type = 'CENTRAL_KITCHEN'
                        AND primary_kitchen.is_primary_central_kitchen
                        AND primary_kitchen.is_active
                    WHERE actor.id = actor_key
                        AND actor.is_initial_identity
                        AND actor.is_active
                )
            $function$;

            CREATE OR REPLACE FUNCTION veltra_private.guard_super_admin_mutations()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, veltra_private, pg_temp
            AS $function$
            DECLARE
                ticket jsonb;
                ticket_text text;
                actor_id uuid;
                ticket_scope text;
                target_id uuid;
                target_is_initial boolean := false;
                target_is_admin boolean := false;
                actor_is_super boolean := false;
                bootstrap_membership boolean := false;
                bootstrap_administrator boolean := false;
                effective_actor uuid;
                location_id uuid;
            BEGIN
                IF session_user = (
                    SELECT migration_role FROM veltra_private.mutation_signing_keys WHERE singleton
                ) THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;

                ticket_text := nullif(current_setting('veltra.authorization_payload', true), '');
                IF ticket_text IS NOT NULL THEN
                    ticket := ticket_text::jsonb;
                    actor_id := nullif(ticket->>'actor_id', '')::uuid;
                    ticket_scope := ticket->>'scope';
                END IF;

                IF TG_TABLE_NAME = 'users' THEN
                    IF TG_OP = 'UPDATE' THEN
                        target_id := OLD.id;
                        target_is_initial := OLD.is_initial_identity;
                        target_is_admin := EXISTS (
                            SELECT 1 FROM public.role_assignments assignment
                            WHERE assignment.user_id = target_id
                                AND assignment.organization_id = OLD.organization_id
                                AND assignment.role = 'ADMINISTRATOR'
                                AND assignment.revoked_at IS NULL
                        );
                        actor_is_super := veltra_private.super_admin_actor(actor_id);

                        IF target_is_initial AND NEW.is_active IS DISTINCT FROM OLD.is_active
                            AND (NOT NEW.is_active OR actor_id IS DISTINCT FROM target_id OR NOT actor_is_super) THEN
                            RAISE EXCEPTION 'The initial administrator identity cannot be deactivated or reassigned'
                                USING ERRCODE = '23514';
                        END IF;

                        IF NEW.first_name IS DISTINCT FROM OLD.first_name
                            OR NEW.last_name IS DISTINCT FROM OLD.last_name
                            OR NEW.email IS DISTINCT FROM OLD.email THEN
                            IF target_is_initial THEN
                                IF actor_id IS DISTINCT FROM target_id OR NOT actor_is_super
                                    OR ticket_scope IS DISTINCT FROM 'account.profile_update' THEN
                                    RAISE EXCEPTION 'Only the initial administrator may edit their own profile'
                                        USING ERRCODE = '23514';
                                END IF;
                            ELSIF target_is_admin AND actor_id IS DISTINCT FROM target_id AND NOT actor_is_super THEN
                                RAISE EXCEPTION 'Only SUPER ADMIN may edit another administrator account'
                                    USING ERRCODE = '23514';
                            END IF;
                        END IF;

                        IF target_is_admin AND NEW.is_active IS DISTINCT FROM OLD.is_active
                            AND actor_id IS DISTINCT FROM target_id AND NOT actor_is_super THEN
                            RAISE EXCEPTION 'Only SUPER ADMIN may change another administrator status'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'role_assignments' THEN
                    target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
                    target_is_initial := EXISTS (
                        SELECT 1 FROM public.users WHERE id = target_id AND is_initial_identity
                    );
                    target_is_admin := EXISTS (
                        SELECT 1 FROM public.role_assignments assignment
                        WHERE assignment.user_id = target_id
                            AND assignment.revoked_at IS NULL
                            AND assignment.role = 'ADMINISTRATOR'
                    );
                    actor_is_super := veltra_private.super_admin_actor(actor_id);
                    bootstrap_administrator := TG_OP = 'INSERT'
                        AND NEW.role = 'ADMINISTRATOR'
                        AND target_is_initial
                        AND actor_id = target_id
                        AND ticket_scope = 'administrator.bootstrap'
                        AND EXISTS (
                            SELECT 1 FROM public.organizations organization
                            JOIN public.users initial ON initial.organization_id = organization.id
                            WHERE initial.id = target_id
                                AND NOT organization.authorization_initialized
                        );

                    IF bootstrap_administrator THEN
                        RETURN NEW;
                    END IF;
                    IF TG_OP IN ('UPDATE', 'DELETE') AND target_is_initial AND OLD.role = 'ADMINISTRATOR' THEN
                        RAISE EXCEPTION 'The initial administrator role is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP IN ('UPDATE', 'DELETE') AND OLD.role = 'ADMINISTRATOR' AND NOT actor_is_super THEN
                        RAISE EXCEPTION 'Only SUPER ADMIN may revoke the administrator role'
                            USING ERRCODE = '23514';
                    END IF;
                    IF target_is_initial AND (actor_id IS DISTINCT FROM target_id OR NOT actor_is_super) THEN
                        RAISE EXCEPTION 'Only the initial SUPER ADMIN may manage their own roles'
                            USING ERRCODE = '23514';
                    END IF;
                    IF target_is_admin AND actor_id IS DISTINCT FROM target_id AND NOT actor_is_super THEN
                        RAISE EXCEPTION 'Only SUPER ADMIN may manage another administrator roles'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'INSERT' AND NEW.role = 'ADMINISTRATOR' AND NOT actor_is_super THEN
                        RAISE EXCEPTION 'Only SUPER ADMIN may grant the administrator role'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'location_memberships' THEN
                    target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
                    location_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.location_id ELSE NEW.location_id END;
                    target_is_initial := EXISTS (
                        SELECT 1 FROM public.users WHERE id = target_id AND is_initial_identity
                    );
                    target_is_admin := EXISTS (
                        SELECT 1 FROM public.role_assignments assignment
                        WHERE assignment.user_id = target_id
                            AND assignment.revoked_at IS NULL
                            AND assignment.role = 'ADMINISTRATOR'
                    );
                    actor_is_super := veltra_private.super_admin_actor(actor_id);
                    bootstrap_membership := TG_OP = 'INSERT'
                        AND target_is_initial
                        AND ticket_scope = 'membership.assign'
                        AND (actor_id IS NULL OR actor_id = target_id)
                        AND EXISTS (
                            SELECT 1
                            FROM public.organizations organization
                            JOIN public.locations kitchen
                                ON kitchen.organization_id = organization.id
                                AND kitchen.id = location_id
                                AND kitchen.is_primary_central_kitchen
                            WHERE organization.id = (
                                SELECT organization_id FROM public.users WHERE id = target_id
                            )
                                AND NOT organization.authorization_initialized
                        );

                    IF bootstrap_membership THEN
                        RETURN NEW;
                    END IF;
                    IF target_is_initial AND TG_OP = 'UPDATE' AND OLD.is_active AND NOT NEW.is_active
                        AND EXISTS (
                            SELECT 1 FROM public.locations kitchen
                            WHERE kitchen.id = OLD.location_id AND kitchen.is_primary_central_kitchen
                        ) THEN
                        RAISE EXCEPTION 'The primary administrator membership is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    IF target_is_initial AND (actor_id IS DISTINCT FROM target_id OR NOT actor_is_super) THEN
                        RAISE EXCEPTION 'Only the initial SUPER ADMIN may manage their own memberships'
                            USING ERRCODE = '23514';
                    END IF;
                    IF target_is_admin AND actor_id IS DISTINCT FROM target_id AND NOT actor_is_super THEN
                        RAISE EXCEPTION 'Only SUPER ADMIN may manage another administrator memberships'
                            USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'permission_grants' THEN
                    target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
                    effective_actor := CASE
                        WHEN TG_OP = 'INSERT' THEN NEW.granted_by
                        WHEN TG_OP = 'UPDATE' THEN NEW.revoked_by
                        ELSE actor_id
                    END;
                    IF effective_actor IS DISTINCT FROM actor_id THEN
                        RAISE EXCEPTION 'Permission mutation actor does not match the authorization ticket'
                            USING ERRCODE = '42501';
                    END IF;
                ELSIF TG_TABLE_NAME = 'organization_permission_grants' THEN
                    target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
                    effective_actor := CASE WHEN TG_OP = 'DELETE' THEN actor_id ELSE NEW.granted_by END;
                    IF effective_actor IS DISTINCT FROM actor_id THEN
                        RAISE EXCEPTION 'Permission mutation actor does not match the authorization ticket'
                            USING ERRCODE = '42501';
                    END IF;
                ELSIF TG_TABLE_NAME = 'organization_permission_grant_events' THEN
                    target_id := (
                        SELECT grant_record.user_id FROM public.organization_permission_grants grant_record
                        WHERE grant_record.id = CASE WHEN TG_OP = 'DELETE' THEN OLD.grant_id ELSE NEW.grant_id END
                    );
                    effective_actor := CASE
                        WHEN TG_OP = 'DELETE' THEN actor_id
                        WHEN actor_id IS NOT NULL
                            AND ticket_scope = 'account.deactivate'
                            AND NEW.event_type = 'REVOKED'
                            AND NEW.reason_code = 'USER_DEACTIVATED'
                            AND NEW.actor_user_id IS NULL
                            THEN actor_id
                        ELSE NEW.actor_user_id
                    END;
                    IF effective_actor IS DISTINCT FROM actor_id THEN
                        RAISE EXCEPTION 'Permission event actor does not match the authorization ticket'
                            USING ERRCODE = '42501';
                    END IF;
                ELSIF TG_TABLE_NAME = 'raw_material_permission_upgrade_operations' THEN
                    target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.recipient_user_id ELSE NEW.recipient_user_id END;
                    effective_actor := CASE WHEN TG_OP = 'DELETE' THEN actor_id ELSE NEW.actor_user_id END;
                    IF effective_actor IS DISTINCT FROM actor_id THEN
                        RAISE EXCEPTION 'Permission upgrade actor does not match the authorization ticket'
                            USING ERRCODE = '42501';
                    END IF;
                ELSIF TG_TABLE_NAME = 'membership_requests' THEN
                    target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
                    effective_actor := CASE WHEN TG_OP = 'INSERT' THEN NEW.requested_by ELSE NEW.decided_by END;
                    IF effective_actor IS DISTINCT FROM actor_id THEN
                        RAISE EXCEPTION 'Membership mutation actor does not match the authorization ticket'
                            USING ERRCODE = '42501';
                    END IF;
                ELSE
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;

                target_is_initial := EXISTS (
                    SELECT 1 FROM public.users WHERE id = target_id AND is_initial_identity
                );
                target_is_admin := EXISTS (
                    SELECT 1 FROM public.role_assignments assignment
                    WHERE assignment.user_id = target_id
                        AND assignment.revoked_at IS NULL
                        AND assignment.role = 'ADMINISTRATOR'
                );
                actor_is_super := veltra_private.super_admin_actor(actor_id);

                IF target_is_initial AND (actor_id IS DISTINCT FROM target_id OR NOT actor_is_super) THEN
                    RAISE EXCEPTION 'Only the initial SUPER ADMIN may manage their own permissions'
                        USING ERRCODE = '23514';
                END IF;
                IF target_is_admin AND actor_id IS DISTINCT FROM target_id AND NOT actor_is_super THEN
                    RAISE EXCEPTION 'Only SUPER ADMIN may manage another administrator permissions'
                        USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END
            $function$;

            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.users
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.role_assignments
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.location_memberships
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.permission_grants
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.organization_permission_grants
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.organization_permission_grant_events
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.raw_material_permission_upgrade_operations
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();
            CREATE TRIGGER zz_veltra_super_admin_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.membership_requests
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_super_admin_mutations();

            CREATE OR REPLACE FUNCTION require_active_administrator()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM public.organizations organization
                    WHERE organization.authorization_initialized
                        AND NOT EXISTS (
                            SELECT 1
                            FROM public.role_assignments assignment
                            JOIN public.users account ON account.id = assignment.user_id
                            JOIN public.location_memberships membership
                                ON membership.user_id = account.id AND membership.is_active
                            JOIN public.locations location
                                ON location.id = membership.location_id AND location.is_active
                            WHERE assignment.organization_id = organization.id
                                AND assignment.role = 'ADMINISTRATOR'
                                AND assignment.revoked_at IS NULL
                                AND account.is_active
                                AND (
                                    account.email_verified_at IS NOT NULL
                                    OR (
                                        account.is_initial_identity
                                        AND NOT organization.primary_central_kitchen_unresolved
                                        AND location.is_primary_central_kitchen
                                    )
                                )
                        )
                ) THEN
                    RAISE EXCEPTION 'An active administrator with an operating membership must remain'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END
            $function$;
            SQL);

        DB::unprepared(<<<'SQL'
            DO $block$
            DECLARE
                configured_runtime_role name;
            BEGIN
                SELECT runtime_role INTO configured_runtime_role
                FROM veltra_private.mutation_signing_keys
                WHERE singleton;
                EXECUTE format(
                    'REVOKE ALL ON FUNCTION veltra_private.super_admin_actor(uuid) FROM PUBLIC, %I',
                    configured_runtime_role
                );
                EXECUTE format(
                    'REVOKE ALL ON FUNCTION veltra_private.guard_super_admin_mutations() FROM PUBLIC, %I',
                    configured_runtime_role
                );
            END
            $block$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Ochrona SUPER ADMIN jest nieodwracalna; zmiany wymagają osobnej migracji do przodu.');
    }
};
