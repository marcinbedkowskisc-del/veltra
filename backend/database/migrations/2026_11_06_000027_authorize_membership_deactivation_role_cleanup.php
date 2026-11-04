<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")) {
                throw new RuntimeException('Migracja 027 wymaga tabeli zakresów podpisanych mutacji autoryzacyjnych.');
            }
            if (! DB::scalar("SELECT to_regprocedure('veltra_private.validate_role_assignment_ticket()') IS NOT NULL")
                || ! DB::table('veltra_private.authorization_mutation_scopes')
                    ->where('table_name', 'role_assignments')
                    ->where('operation', 'UPDATE')
                    ->where('scope_prefix', 'membership.remove')
                    ->exists()) {
                throw new RuntimeException('Migracja 027 wymaga istniejącej walidacji cofania ról i zakresu membership.remove.');
            }

            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                'table_name' => 'role_assignments',
                'operation' => 'UPDATE',
                'scope_prefix' => 'membership.deactivate',
            ]);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION veltra_private.validate_role_assignment_ticket()
                RETURNS trigger
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                    expected_actor text;
                    expected_scope text;
                    configured_migration_role name;
                BEGIN
                    SELECT keys.migration_role INTO configured_migration_role
                    FROM veltra_private.mutation_signing_keys keys
                    WHERE keys.singleton;
                    IF session_user = configured_migration_role
                        OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = session_user AND rolsuper) THEN
                        RETURN NEW;
                    END IF;

                    ticket := current_setting('veltra.authorization_payload', true)::jsonb;
                    IF TG_OP = 'INSERT' THEN
                        IF ticket->>'scope' = 'administrator.bootstrap' THEN
                            expected_actor := NEW.user_id::text;
                            expected_scope := 'administrator.bootstrap';
                        ELSE
                            expected_actor := NEW.granted_by::text;
                            expected_scope := 'role.grant.' || NEW.role;
                        END IF;
                    ELSIF ticket->>'scope' = 'membership.deactivate' THEN
                        IF ticket->>'actor_id' IS NOT NULL
                            OR NEW.revoked_by IS DISTINCT FROM OLD.revoked_by
                            OR NEW.revoked_at IS NULL
                            OR OLD.revoked_at IS NOT NULL
                            OR (to_jsonb(NEW) - 'revoked_at') IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at')
                            OR NOT EXISTS (
                                SELECT 1
                                FROM public.location_memberships memberships
                                WHERE memberships.id = NEW.membership_id
                                    AND NOT memberships.is_active
                            ) THEN
                            RAISE EXCEPTION 'Membership deactivation may only revoke roles attached to its inactive membership'
                                USING ERRCODE = '42501';
                        END IF;
                        RETURN NEW;
                    ELSE
                        expected_actor := NEW.revoked_by::text;
                        expected_scope := CASE
                            WHEN ticket->>'scope' = 'membership.remove' THEN 'membership.remove'
                            ELSE 'role.revoke.' || OLD.role
                        END;
                    END IF;

                    IF ticket->>'scope' IS DISTINCT FROM expected_scope
                        OR ticket->>'actor_id' IS DISTINCT FROM expected_actor THEN
                        RAISE EXCEPTION 'Role assignment does not match the signed actor and operation'
                            USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END
                $function$
                SQL);

            if (DB::table('veltra_private.authorization_mutation_scopes')
                ->where('table_name', 'role_assignments')
                ->where('operation', 'UPDATE')
                ->where('scope_prefix', 'membership.deactivate')
                ->count() !== 1) {
                throw new RuntimeException('Nie udało się dodać wąskiego zakresu dezaktywacji przypisania członkostwa.');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 027 jest tylko do przodu; jej wycofanie ponownie blokowałoby legalną dezaktywację członkostwa.');
    }
};
