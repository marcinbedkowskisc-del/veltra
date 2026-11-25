<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE public.users, public.role_assignments, public.location_memberships, public.locations IN ACCESS EXCLUSIVE MODE;
            CREATE FUNCTION public.business_role_count(p_user uuid) RETURNS bigint LANGUAGE sql STABLE AS $$
                SELECT count(DISTINCT r.role)
                FROM public.role_assignments r
                LEFT JOIN public.location_memberships m ON m.id = r.membership_id
                LEFT JOIN public.locations l ON l.id = m.location_id
                WHERE r.user_id = p_user AND r.revoked_at IS NULL
                    AND (r.membership_id IS NULL OR (m.is_active AND l.is_active))
            $$;
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM public.users u
                    WHERE u.is_active AND u.email_verified_at IS NOT NULL
                        AND public.business_role_count(u.id) <> 1
                ) THEN
                    RAISE EXCEPTION 'Active verified users without exactly one effective business role exist; resolve them explicitly before migration'
                        USING ERRCODE = '23514';
                END IF;
            END $$;
            CREATE FUNCTION public.check_effective_business_role() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                affected_user uuid;
            BEGIN
                IF TG_TABLE_NAME = 'locations' THEN
                    IF EXISTS (
                        SELECT 1 FROM public.users u
                        WHERE u.is_active AND u.email_verified_at IS NOT NULL
                            AND EXISTS (SELECT 1 FROM public.role_assignments r
                                WHERE r.user_id = u.id AND r.location_id = COALESCE(NEW.id, OLD.id))
                            AND public.business_role_count(u.id) <> 1
                    ) THEN
                        RAISE EXCEPTION 'Active verified user must have exactly one effective business role'
                            USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF TG_TABLE_NAME = 'users' THEN
                        affected_user := COALESCE(NEW.id, OLD.id);
                    ELSE
                        affected_user := COALESCE(NEW.user_id, OLD.user_id);
                    END IF;
                    IF EXISTS (
                        SELECT 1 FROM public.users u WHERE u.id = affected_user
                            AND u.is_active AND u.email_verified_at IS NOT NULL
                            AND public.business_role_count(u.id) <> 1
                    ) THEN
                        RAISE EXCEPTION 'Active verified user must have exactly one effective business role'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER users_effective_business_role
                AFTER INSERT OR UPDATE OR DELETE ON public.users
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_effective_business_role();
            CREATE CONSTRAINT TRIGGER roles_effective_business_role
                AFTER INSERT OR UPDATE OR DELETE ON public.role_assignments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_effective_business_role();
            CREATE CONSTRAINT TRIGGER memberships_effective_business_role
                AFTER INSERT OR UPDATE OR DELETE ON public.location_memberships
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_effective_business_role();
            CREATE CONSTRAINT TRIGGER locations_effective_business_role
                AFTER INSERT OR UPDATE OR DELETE ON public.locations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_effective_business_role();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja jest tylko do przodu; wycofanie wymaga osobnej migracji.');
    }
};
