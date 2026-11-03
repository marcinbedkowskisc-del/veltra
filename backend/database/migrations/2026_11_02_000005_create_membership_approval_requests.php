<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE membership_requests (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                location_id uuid NOT NULL,
                requested_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                requested_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                decision varchar(20) CHECK (decision IN ('approved','rejected')),
                decided_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                decided_at timestamptz,
                FOREIGN KEY (user_id, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (location_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                CHECK ((decision IS NULL AND decided_by IS NULL AND decided_at IS NULL)
                    OR (decision IS NOT NULL AND decided_by IS NOT NULL AND decided_at >= requested_at))
            );
            CREATE UNIQUE INDEX membership_requests_pending_unique ON membership_requests(user_id,location_id) WHERE decision IS NULL;
            CREATE TRIGGER membership_requests_serialize BEFORE INSERT OR UPDATE OR DELETE ON membership_requests
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE OR REPLACE FUNCTION preserve_membership_request() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Membership requests cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.decision IS NOT NULL OR NEW.decision IS NULL
                    OR (to_jsonb(NEW) - ARRAY['decision','decided_by','decided_at']) IS DISTINCT FROM
                       (to_jsonb(OLD) - ARRAY['decision','decided_by','decided_at']) THEN
                    RAISE EXCEPTION 'Only a pending membership decision is allowed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER membership_requests_history BEFORE UPDATE OR DELETE ON membership_requests
                FOR EACH ROW EXECUTE FUNCTION preserve_membership_request();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE membership_requests; DROP FUNCTION preserve_membership_request();');
    }
};
