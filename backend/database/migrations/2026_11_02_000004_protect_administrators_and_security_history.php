<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations ADD COLUMN authorization_initialized boolean NOT NULL DEFAULT false;
            CREATE TABLE security_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                actor_id uuid REFERENCES users(id) ON DELETE RESTRICT,
                target_id uuid REFERENCES users(id) ON DELETE RESTRICT,
                location_id uuid REFERENCES locations(id) ON DELETE RESTRICT,
                action varchar(80) NOT NULL,
                outcome varchar(20) NOT NULL CHECK (outcome IN ('accepted','rejected')),
                subject_id uuid,
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE INDEX security_events_organization_time_idx ON security_events(organization_id, occurred_at);
            CREATE FUNCTION preserve_security_event() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Security history is append-only' USING ERRCODE = '23514';
            END $$;
            CREATE TRIGGER security_events_append_only BEFORE UPDATE OR DELETE ON security_events
                FOR EACH ROW EXECUTE FUNCTION preserve_security_event();
            CREATE TRIGGER users_serialize BEFORE INSERT OR UPDATE OR DELETE ON users
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE TRIGGER memberships_serialize BEFORE INSERT OR UPDATE OR DELETE ON location_memberships
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE FUNCTION protect_authorization_initialization() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.authorization_initialized AND NOT NEW.authorization_initialized THEN
                    RAISE EXCEPTION 'Authorization initialization cannot be reversed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER organizations_authorization_latch BEFORE UPDATE ON organizations
                FOR EACH ROW EXECUTE FUNCTION protect_authorization_initialization();
            CREATE FUNCTION require_active_administrator() RETURNS trigger LANGUAGE plpgsql AS $$
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
            CREATE CONSTRAINT TRIGGER roles_require_administrator AFTER INSERT OR UPDATE OR DELETE ON role_assignments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_active_administrator();
            CREATE CONSTRAINT TRIGGER users_require_administrator AFTER INSERT OR UPDATE OR DELETE ON users
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_active_administrator();
            CREATE CONSTRAINT TRIGGER organizations_require_administrator AFTER INSERT OR UPDATE ON organizations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_active_administrator();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER roles_require_administrator ON role_assignments;
            DROP TRIGGER users_require_administrator ON users;
            DROP TRIGGER organizations_require_administrator ON organizations;
            DROP TRIGGER organizations_authorization_latch ON organizations;
            DROP TRIGGER users_serialize ON users;
            DROP TRIGGER memberships_serialize ON location_memberships;
            DROP FUNCTION require_active_administrator();
            DROP FUNCTION protect_authorization_initialization();
            DROP TABLE security_events;
            DROP FUNCTION preserve_security_event();
            ALTER TABLE organizations DROP COLUMN authorization_initialized;
            SQL);
    }
};
