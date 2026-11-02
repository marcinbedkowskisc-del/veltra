<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE permission_grants (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                location_id uuid NOT NULL,
                membership_id uuid NOT NULL,
                permission varchar(40) NOT NULL CHECK (permission IN (
                    'FOODCOST_VIEW','PURCHASE_PRICES_VIEW','REPORTS_VIEW','REPORTS_EXPORT',
                    'TEMPERATURE_REGISTER','CLEANING_ENTRY_CREATE','LOSS_REPORT_CREATE','INVENTORY_COUNT_ENTRY')),
                granted_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                granted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                valid_from timestamptz NOT NULL CHECK (isfinite(valid_from)),
                valid_until timestamptz CHECK (isfinite(valid_until) AND valid_until > valid_from),
                revoked_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                revoked_at timestamptz CHECK (revoked_at IS NULL OR (isfinite(revoked_at) AND revoked_at >= granted_at)),
                FOREIGN KEY (membership_id,user_id,location_id,organization_id)
                    REFERENCES location_memberships(id,user_id,location_id,organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX permission_grants_effective_idx ON permission_grants(user_id,location_id,permission) WHERE revoked_at IS NULL;
            CREATE TRIGGER permission_grants_serialize BEFORE INSERT OR UPDATE OR DELETE ON permission_grants
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE FUNCTION validate_permission_grant() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM locations l JOIN location_memberships m ON m.location_id = l.id
                    JOIN users u ON u.id = m.user_id WHERE m.id = NEW.membership_id AND m.is_active AND l.is_active
                    AND u.is_active AND l.type = 'RESTAURANT') THEN
                    RAISE EXCEPTION 'Permission requires an active restaurant membership' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER permission_grants_validate BEFORE INSERT ON permission_grants
                FOR EACH ROW EXECUTE FUNCTION validate_permission_grant();
            CREATE TRIGGER permission_grants_history BEFORE UPDATE OR DELETE ON permission_grants
                FOR EACH ROW EXECUTE FUNCTION preserve_role_history();
            CREATE FUNCTION revoke_membership_authorization() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE boundary timestamptz := clock_timestamp();
            BEGIN
                UPDATE role_assignments SET revoked_at = boundary WHERE membership_id = NEW.id AND revoked_at IS NULL;
                UPDATE permission_grants SET revoked_at = boundary WHERE membership_id = NEW.id AND revoked_at IS NULL;
                RETURN NEW;
            END $$;
            CREATE TRIGGER membership_authorization_revocation AFTER UPDATE ON location_memberships
                FOR EACH ROW WHEN (OLD.is_active AND NOT NEW.is_active) EXECUTE FUNCTION revoke_membership_authorization();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER membership_authorization_revocation ON location_memberships;
            DROP FUNCTION revoke_membership_authorization();
            DROP TABLE permission_grants;
            DROP FUNCTION validate_permission_grant();
            SQL);
    }
};
