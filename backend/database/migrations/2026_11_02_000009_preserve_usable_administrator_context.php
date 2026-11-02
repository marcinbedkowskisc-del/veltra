<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION require_active_administrator() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM organizations o WHERE o.authorization_initialized AND NOT EXISTS (
                    SELECT 1 FROM role_assignments r JOIN users u ON u.id = r.user_id
                    JOIN location_memberships m ON m.user_id = u.id AND m.is_active
                    JOIN locations l ON l.id = m.location_id AND l.is_active
                    WHERE r.organization_id = o.id AND r.role = 'ADMINISTRATOR' AND r.revoked_at IS NULL
                    AND u.is_active AND u.email_verified_at IS NOT NULL
                )) THEN
                    RAISE EXCEPTION 'An active verified administrator with an operating membership must remain' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER memberships_require_administrator AFTER INSERT OR UPDATE OR DELETE ON location_memberships
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_active_administrator();
            CREATE CONSTRAINT TRIGGER locations_require_administrator AFTER INSERT OR UPDATE OR DELETE ON locations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_active_administrator();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER memberships_require_administrator ON location_memberships;
            DROP TRIGGER locations_require_administrator ON locations;
            CREATE OR REPLACE FUNCTION require_active_administrator() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM organizations o WHERE o.authorization_initialized AND NOT EXISTS (
                    SELECT 1 FROM role_assignments r JOIN users u ON u.id = r.user_id
                    WHERE r.organization_id = o.id AND r.role = 'ADMINISTRATOR' AND r.revoked_at IS NULL
                    AND u.is_active AND u.email_verified_at IS NOT NULL
                )) THEN
                    RAISE EXCEPTION 'An active verified administrator must remain' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END $$;
            SQL);
    }
};
