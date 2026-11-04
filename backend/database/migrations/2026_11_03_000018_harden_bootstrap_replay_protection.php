<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raw_materials_assert_no_active_bootstrap_replays()
            RETURNS void
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM organization_permission_grants grants
                    WHERE grants.bootstrap_operation_id IS NOT NULL
                    GROUP BY grants.organization_id, grants.bootstrap_operation_id, grants.permission
                    HAVING COUNT(*) > 1
                        AND COUNT(*) FILTER (
                            WHERE NOT EXISTS (
                                SELECT 1
                                FROM organization_permission_grant_events events
                                WHERE events.grant_id = grants.id
                                    AND events.event_type = 'REVOKED'
                            )
                        ) > 0
                ) THEN
                    RAISE EXCEPTION
                        'Active historical duplicate permission grants require manual review; no grants were changed'
                        USING ERRCODE = '23514';
                END IF;
            END
            $$;

            SELECT raw_materials_assert_no_active_bootstrap_replays();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można wycofać dodatkowej ochrony przed replayem bootstrapu uprawnień.');
    }
};
