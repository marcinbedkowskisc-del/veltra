<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD COLUMN email_verified_at timestamptz;
            ALTER TABLE location_memberships ADD CONSTRAINT memberships_scope_identity_unique
                UNIQUE (id, user_id, location_id, organization_id);
            CREATE TABLE role_assignments (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                role varchar(40) NOT NULL CHECK (role IN (
                    'ADMINISTRATOR', 'CENTRAL_KITCHEN_CHEF', 'BOSS',
                    'RESTAURANT_MANAGER', 'RESTAURANT_CHEF', 'EMPLOYEE')),
                location_id uuid,
                membership_id uuid,
                granted_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                granted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                revoked_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                revoked_at timestamptz,
                FOREIGN KEY (user_id, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (location_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (membership_id, user_id, location_id, organization_id)
                    REFERENCES location_memberships(id, user_id, location_id, organization_id) ON DELETE RESTRICT,
                CHECK ((role IN ('ADMINISTRATOR', 'BOSS') AND location_id IS NULL AND membership_id IS NULL)
                    OR (role NOT IN ('ADMINISTRATOR', 'BOSS') AND location_id IS NOT NULL AND membership_id IS NOT NULL)),
                CHECK (isfinite(granted_at) AND (revoked_at IS NULL OR (isfinite(revoked_at) AND revoked_at >= granted_at)))
            );
            CREATE UNIQUE INDEX roles_active_scope_unique ON role_assignments
                (user_id, role, coalesce(location_id, '00000000-0000-0000-0000-000000000000'::uuid))
                WHERE revoked_at IS NULL;
            CREATE INDEX roles_organization_idx ON role_assignments(organization_id);
            CREATE OR REPLACE FUNCTION validate_role_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.location_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM locations l JOIN location_memberships m ON m.location_id = l.id
                        WHERE l.id = NEW.location_id AND m.id = NEW.membership_id AND m.is_active AND l.is_active
                        AND l.type = CASE WHEN NEW.role = 'CENTRAL_KITCHEN_CHEF' THEN 'CENTRAL_KITCHEN' ELSE 'RESTAURANT' END
                    ) THEN
                        RAISE EXCEPTION 'Role requires an active membership at an eligible location' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER roles_validate BEFORE INSERT ON role_assignments
                FOR EACH ROW EXECUTE FUNCTION validate_role_assignment();
            CREATE TRIGGER roles_serialize BEFORE INSERT OR UPDATE OR DELETE ON role_assignments
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE OR REPLACE FUNCTION preserve_role_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Role history cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
                    OR (to_jsonb(NEW) - ARRAY['revoked_at','revoked_by']) IS DISTINCT FROM
                       (to_jsonb(OLD) - ARRAY['revoked_at','revoked_by']) THEN
                    RAISE EXCEPTION 'Only role revocation is allowed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER roles_history BEFORE UPDATE OR DELETE ON role_assignments
                FOR EACH ROW EXECUTE FUNCTION preserve_role_history();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE role_assignments;
            DROP FUNCTION preserve_role_history();
            DROP FUNCTION validate_role_assignment();
            ALTER TABLE location_memberships DROP CONSTRAINT memberships_scope_identity_unique;
            ALTER TABLE users DROP COLUMN email_verified_at;
            SQL);
    }
};
