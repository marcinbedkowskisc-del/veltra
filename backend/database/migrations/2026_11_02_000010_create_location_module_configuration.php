<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE security_events
                ADD COLUMN operation_id uuid,
                ADD COLUMN event_kind varchar(24) NOT NULL DEFAULT 'security'
                    CHECK (event_kind IN ('security', 'module_attempt', 'module_transition')),
                ADD COLUMN module_key varchar(80)
                    CHECK (module_key IS NULL OR module_key ~ '^[A-Z][A-Z0-9_]{0,79}$'),
                ADD COLUMN previous_enabled boolean,
                ADD COLUMN requested_enabled boolean,
                ADD COLUMN effective_enabled boolean,
                ADD COLUMN detail_code varchar(80),
                ADD CONSTRAINT security_events_module_shape CHECK (
                    (event_kind = 'security' AND operation_id IS NULL AND module_key IS NULL
                        AND previous_enabled IS NULL AND requested_enabled IS NULL AND effective_enabled IS NULL
                        AND detail_code IS NULL)
                    OR (event_kind = 'module_attempt' AND operation_id IS NOT NULL AND module_key IS NOT NULL
                        AND requested_enabled IS NOT NULL AND detail_code IS NOT NULL)
                    OR (event_kind = 'module_transition' AND operation_id IS NOT NULL AND module_key IS NOT NULL
                        AND previous_enabled IS NOT NULL AND requested_enabled IS NOT NULL
                        AND effective_enabled IS NOT NULL
                        AND effective_enabled IS NOT DISTINCT FROM requested_enabled
                        AND previous_enabled IS DISTINCT FROM effective_enabled
                        AND outcome = 'accepted' AND detail_code IS NOT NULL)
                );
            CREATE INDEX security_events_module_operation_idx
                ON security_events(organization_id, operation_id, event_kind);

            CREATE TABLE location_module_states (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                location_id uuid NOT NULL,
                module_key varchar(80) NOT NULL CHECK (module_key ~ '^[A-Z][A-Z0-9_]{0,79}$'),
                enabled boolean NOT NULL,
                created_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                updated_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (location_id, organization_id)
                    REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                UNIQUE (location_id, module_key)
            );
            CREATE INDEX location_module_states_organization_idx
                ON location_module_states(organization_id, location_id);

            CREATE FUNCTION serialize_location_module_state() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE organization_key uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT organization_id INTO organization_key FROM locations WHERE id = OLD.location_id;
                ELSE
                    SELECT organization_id INTO organization_key FROM locations WHERE id = NEW.location_id;
                END IF;
                IF organization_key IS NOT NULL THEN
                    PERFORM id FROM organizations WHERE id = organization_key FOR UPDATE;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER location_module_states_serialize
                BEFORE INSERT OR UPDATE OR DELETE ON location_module_states
                FOR EACH ROW EXECUTE FUNCTION serialize_location_module_state();
            CREATE FUNCTION preserve_location_module_state_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.location_id IS DISTINCT FROM OLD.location_id
                    OR NEW.module_key IS DISTINCT FROM OLD.module_key
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Module state identity is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER location_module_states_identity
                BEFORE UPDATE ON location_module_states
                FOR EACH ROW EXECUTE FUNCTION preserve_location_module_state_identity();
            SQL);
    }

    /**
     * This rollback deletes module state and module-specific audit details.
     * Require a verified backup/export or an approved forward-recovery plan.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER location_module_states_identity ON location_module_states;
            DROP TRIGGER location_module_states_serialize ON location_module_states;
            DROP FUNCTION preserve_location_module_state_identity();
            DROP FUNCTION serialize_location_module_state();
            DROP TABLE location_module_states;
            DROP INDEX security_events_module_operation_idx;
            ALTER TABLE security_events DROP CONSTRAINT security_events_module_shape;
            ALTER TABLE security_events
                DROP COLUMN operation_id,
                DROP COLUMN event_kind,
                DROP COLUMN module_key,
                DROP COLUMN previous_enabled,
                DROP COLUMN requested_enabled,
                DROP COLUMN effective_enabled,
                DROP COLUMN detail_code;
            SQL);
    }
};
