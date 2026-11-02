<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE report_shares (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                location_id uuid NOT NULL,
                report_id uuid NOT NULL,
                granted_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                granted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                revoked_by uuid REFERENCES users(id) ON DELETE RESTRICT,
                revoked_at timestamptz CHECK (revoked_at IS NULL OR revoked_at >= granted_at),
                FOREIGN KEY (user_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (location_id,organization_id) REFERENCES locations(id,organization_id) ON DELETE RESTRICT
            );
            CREATE TRIGGER report_shares_serialize BEFORE INSERT OR UPDATE OR DELETE ON report_shares
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE TRIGGER report_shares_history BEFORE UPDATE OR DELETE ON report_shares
                FOR EACH ROW EXECUTE FUNCTION preserve_role_history();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE report_shares');
    }
};
