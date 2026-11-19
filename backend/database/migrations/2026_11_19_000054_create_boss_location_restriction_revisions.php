<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.boss_location_restriction_revisions (
                organization_id uuid NOT NULL REFERENCES public.organizations(id),
                user_id uuid NOT NULL REFERENCES public.users(id),
                version integer NOT NULL CHECK (version > 0),
                actor_id uuid NOT NULL REFERENCES public.users(id),
                previous_locations jsonb NOT NULL CHECK (jsonb_typeof(previous_locations) = 'array'),
                excluded_locations jsonb NOT NULL CHECK (jsonb_typeof(excluded_locations) = 'array'),
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY (organization_id, user_id, version)
            );
            CREATE TRIGGER boss_location_restriction_revisions_authorization_ticket
                BEFORE INSERT ON public.boss_location_restriction_revisions
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
            GRANT SELECT, INSERT ON public.boss_location_restriction_revisions TO veltra_app;
            SQL);

        DB::table('veltra_private.runtime_table_allowlist')->insert(['table_name' => 'boss_location_restriction_revisions']);
        DB::table('veltra_private.authorization_mutation_scopes')->insert([
            'table_name' => 'boss_location_restriction_revisions',
            'operation' => 'INSERT',
            'scope_prefix' => 'boss.location_restrictions',
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Boss restriction history cannot be removed by rolling back a migration.');
    }
};
