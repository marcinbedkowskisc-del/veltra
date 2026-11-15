<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                LOCK TABLE public.organizations, public.users, public.role_assignments,
                    public.location_memberships, public.locations IN ACCESS EXCLUSIVE MODE;

                DO $block$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM public.organizations organization
                        WHERE organization.authorization_initialized
                            AND NOT EXISTS (
                                SELECT 1 FROM public.role_assignments assignment
                                JOIN public.users account ON account.id = assignment.user_id
                                    AND account.organization_id = assignment.organization_id
                                JOIN public.location_memberships membership ON membership.user_id = account.id
                                    AND membership.organization_id = account.organization_id
                                    AND membership.is_active
                                JOIN public.locations location ON location.id = membership.location_id
                                    AND location.organization_id = membership.organization_id
                                    AND location.is_active
                                WHERE assignment.organization_id = organization.id
                                    AND assignment.role = 'ADMINISTRATOR'
                                    AND assignment.revoked_at IS NULL
                                    AND account.is_active AND account.email_verified_at IS NOT NULL
                            )
                    ) THEN
                        RAISE EXCEPTION 'An initialized organization lacks a verified administrator with an active membership'
                            USING ERRCODE = '23514';
                    END IF;
                END
                $block$;

                DROP TRIGGER zz_veltra_super_admin_guard ON public.users;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.role_assignments;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.location_memberships;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.permission_grants;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.organization_permission_grants;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.organization_permission_grant_events;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.raw_material_permission_upgrade_operations;
                DROP TRIGGER zz_veltra_super_admin_guard ON public.membership_requests;
                DROP FUNCTION veltra_private.guard_super_admin_mutations();
                DROP FUNCTION veltra_private.super_admin_actor(uuid);

                CREATE OR REPLACE FUNCTION public.require_active_administrator()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public
                AS $function$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM public.organizations organization
                        WHERE organization.authorization_initialized
                            AND NOT EXISTS (
                                SELECT 1 FROM public.role_assignments assignment
                                JOIN public.users account ON account.id = assignment.user_id
                                    AND account.organization_id = assignment.organization_id
                                JOIN public.location_memberships membership ON membership.user_id = account.id
                                    AND membership.organization_id = account.organization_id
                                    AND membership.is_active
                                JOIN public.locations location ON location.id = membership.location_id
                                    AND location.organization_id = membership.organization_id
                                    AND location.is_active
                                WHERE assignment.organization_id = organization.id
                                    AND assignment.role = 'ADMINISTRATOR'
                                    AND assignment.revoked_at IS NULL
                                    AND account.is_active AND account.email_verified_at IS NOT NULL
                            )
                    ) THEN
                        RAISE EXCEPTION 'An active verified administrator with an operating membership must remain'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$;

                CREATE FUNCTION veltra_private.guard_authorization_actors()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                    actor_id uuid;
                    ticket_scope text;
                    effective_actor uuid;
                BEGIN
                    IF session_user = (
                        SELECT migration_role FROM veltra_private.mutation_signing_keys WHERE singleton
                    ) THEN
                        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                        RETURN NEW;
                    END IF;

                    ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                    actor_id := nullif(ticket->>'actor_id', '')::uuid;
                    ticket_scope := ticket->>'scope';

                    IF TG_TABLE_NAME = 'role_assignments' AND TG_OP = 'INSERT' THEN
                        IF ticket_scope = 'administrator.bootstrap'
                            AND actor_id = NEW.user_id AND NEW.granted_by IS NULL
                            AND NEW.role = 'ADMINISTRATOR'
                            AND EXISTS (
                                SELECT 1 FROM public.users account
                                JOIN public.organizations organization ON organization.id = account.organization_id
                                WHERE account.id = NEW.user_id AND account.organization_id = NEW.organization_id
                                    AND account.is_initial_identity AND NOT organization.authorization_initialized
                            ) THEN
                            RETURN NEW;
                        END IF;
                        IF NEW.granted_by IS DISTINCT FROM actor_id OR actor_id IS NULL THEN
                            RAISE EXCEPTION 'Role grant actor does not match the authorization ticket'
                                USING ERRCODE = '42501';
                        END IF;
                        IF NEW.user_id = actor_id THEN
                            RAISE EXCEPTION 'A user cannot grant a role to themselves' USING ERRCODE = '23514';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'permission_grants' THEN
                        effective_actor := CASE
                            WHEN TG_OP = 'INSERT' THEN NEW.granted_by
                            WHEN TG_OP = 'UPDATE' THEN NEW.revoked_by
                            ELSE actor_id
                        END;
                        IF effective_actor IS DISTINCT FROM actor_id THEN
                            RAISE EXCEPTION 'Permission mutation actor does not match the authorization ticket'
                                USING ERRCODE = '42501';
                        END IF;
                        IF TG_OP = 'INSERT' AND NEW.user_id = NEW.granted_by THEN
                            RAISE EXCEPTION 'A user cannot grant a permission to themselves' USING ERRCODE = '23514';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'organization_permission_grants' THEN
                        effective_actor := CASE WHEN TG_OP = 'DELETE' THEN actor_id ELSE NEW.granted_by END;
                        IF effective_actor IS DISTINCT FROM actor_id THEN
                            RAISE EXCEPTION 'Permission mutation actor does not match the authorization ticket'
                                USING ERRCODE = '42501';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'organization_permission_grant_events' THEN
                        effective_actor := CASE
                            WHEN TG_OP = 'DELETE' THEN actor_id
                            WHEN actor_id IS NOT NULL AND ticket_scope = 'account.deactivate'
                                AND NEW.event_type = 'REVOKED' AND NEW.reason_code = 'USER_DEACTIVATED'
                                AND NEW.actor_user_id IS NULL THEN actor_id
                            ELSE NEW.actor_user_id
                        END;
                        IF effective_actor IS DISTINCT FROM actor_id THEN
                            RAISE EXCEPTION 'Permission event actor does not match the authorization ticket'
                                USING ERRCODE = '42501';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'raw_material_permission_upgrade_operations' THEN
                        effective_actor := CASE WHEN TG_OP = 'DELETE' THEN actor_id ELSE NEW.actor_user_id END;
                        IF effective_actor IS DISTINCT FROM actor_id THEN
                            RAISE EXCEPTION 'Permission upgrade actor does not match the authorization ticket'
                                USING ERRCODE = '42501';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'membership_requests' THEN
                        effective_actor := CASE WHEN TG_OP = 'INSERT' THEN NEW.requested_by ELSE NEW.decided_by END;
                        IF effective_actor IS DISTINCT FROM actor_id THEN
                            RAISE EXCEPTION 'Membership mutation actor does not match the authorization ticket'
                                USING ERRCODE = '42501';
                        END IF;
                    END IF;

                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER zz_veltra_authorization_actor_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.role_assignments FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_authorization_actors();
                CREATE TRIGGER zz_veltra_authorization_actor_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.permission_grants FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_authorization_actors();
                CREATE TRIGGER zz_veltra_authorization_actor_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.organization_permission_grants FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_authorization_actors();
                CREATE TRIGGER zz_veltra_authorization_actor_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.organization_permission_grant_events FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_authorization_actors();
                CREATE TRIGGER zz_veltra_authorization_actor_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.raw_material_permission_upgrade_operations FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_authorization_actors();
                CREATE TRIGGER zz_veltra_authorization_actor_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.membership_requests FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_authorization_actors();

                REVOKE ALL ON FUNCTION veltra_private.guard_authorization_actors() FROM PUBLIC;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 046 jest forward-only.');
    }
};
