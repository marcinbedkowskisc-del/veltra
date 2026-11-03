<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organization_permission_grant_events
                ALTER COLUMN actor_user_id DROP NOT NULL;

            CREATE FUNCTION raw_materials_revoke_grants_on_suspension() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                organization_key uuid;
                user_key uuid;
                suspension_reason varchar(80);
                suspension_type varchar(40);
                operation_key uuid := gen_random_uuid();
            BEGIN
                IF NOT OLD.is_active OR NEW.is_active THEN
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'users' THEN
                    organization_key := NEW.organization_id;
                    user_key := NEW.id;
                    suspension_reason := 'USER_DEACTIVATED';
                    suspension_type := 'USER';
                ELSE
                    organization_key := NEW.organization_id;
                    user_key := NEW.user_id;
                    suspension_reason := 'MEMBERSHIP_DEACTIVATED';
                    suspension_type := 'MEMBERSHIP';
                END IF;

                PERFORM id FROM organizations WHERE id = organization_key FOR UPDATE;

                WITH RECURSIVE affected_grants(id) AS (
                    SELECT grants.id
                    FROM organization_permission_grants grants
                    WHERE grants.organization_id = organization_key
                        AND grants.user_id = user_key
                    UNION
                    SELECT child.id
                    FROM organization_permission_grants child
                    JOIN affected_grants parent ON child.delegation_source_id = parent.id
                    WHERE child.organization_id = organization_key
                ),
                revoked_grants AS (
                    INSERT INTO organization_permission_grant_events (
                        id, organization_id, grant_id, event_type, actor_user_id, occurred_at, reason_code
                    )
                    SELECT gen_random_uuid(), organization_key, affected_grants.id, 'REVOKED',
                        NULL, clock_timestamp(), suspension_reason
                    FROM affected_grants
                    ON CONFLICT (grant_id, event_type) DO NOTHING
                    RETURNING grant_id
                )
                INSERT INTO raw_material_audit_events (
                    id, organization_id, operation_id, actor_user_id, subject_type, subject_id,
                    event_code, outcome, reason_code, metadata
                )
                SELECT gen_random_uuid(), organization_key, operation_key, NULL,
                    'ORGANIZATION_PERMISSION_GRANT', revoked_grants.grant_id,
                    'PERMISSION_REVOKED', 'ACCEPTED', suspension_reason,
                    jsonb_build_object(
                        'source_type', suspension_type,
                        'source_id', NEW.id,
                        'affected_user_id', user_key
                    )
                FROM revoked_grants;

                RETURN NEW;
            END $$;

            CREATE TRIGGER raw_materials_user_grants_revoke_on_deactivation
                BEFORE UPDATE OF is_active ON users
                FOR EACH ROW EXECUTE FUNCTION raw_materials_revoke_grants_on_suspension();

            CREATE TRIGGER raw_materials_membership_grants_revoke_on_deactivation
                BEFORE UPDATE OF is_active ON location_memberships
                FOR EACH ROW EXECUTE FUNCTION raw_materials_revoke_grants_on_suspension();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('organization_permission_grant_events')->whereNull('actor_user_id')->exists()) {
            throw new RuntimeException('Cannot restore NOT NULL actor_user_id while system revocation history exists');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER raw_materials_membership_grants_revoke_on_deactivation ON location_memberships;
            DROP TRIGGER raw_materials_user_grants_revoke_on_deactivation ON users;
            DROP FUNCTION raw_materials_revoke_grants_on_suspension();

            ALTER TABLE organization_permission_grant_events
                ALTER COLUMN actor_user_id SET NOT NULL;
            SQL);
    }
};
