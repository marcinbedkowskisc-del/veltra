<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.revoke_membership_authorization()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                boundary timestamptz := clock_timestamp();
                actor uuid;
            BEGIN
                actor := nullif(
                    nullif(current_setting('veltra.authorization_payload', true), '')::jsonb->>'actor_id',
                    ''
                )::uuid;
                UPDATE role_assignments
                    SET revoked_at = boundary
                    WHERE membership_id = NEW.id AND revoked_at IS NULL;
                UPDATE permission_grants
                    SET revoked_at = boundary, revoked_by = actor
                    WHERE membership_id = NEW.id AND revoked_at IS NULL;
                RETURN NEW;
            END
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.revoke_membership_authorization()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE boundary timestamptz := clock_timestamp();
            BEGIN
                UPDATE role_assignments SET revoked_at = boundary WHERE membership_id = NEW.id AND revoked_at IS NULL;
                UPDATE permission_grants SET revoked_at = boundary WHERE membership_id = NEW.id AND revoked_at IS NULL;
                RETURN NEW;
            END
            $$;
            SQL);
    }
};
