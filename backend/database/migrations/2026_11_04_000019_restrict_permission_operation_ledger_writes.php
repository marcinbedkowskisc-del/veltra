<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runtimeUsername = (string) env('APP_DB_USERNAME', 'veltra_app');
        if ($runtimeUsername === '') {
            throw new RuntimeException('Nie skonfigurowano roli runtime PostgreSQL.');
        }

        $quotedRuntimeRole = DB::selectOne(
            'SELECT quote_ident(?) AS role_name',
            [$runtimeUsername],
        )->role_name;

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.raw_materials_reject_bootstrap_grant_replay()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
                use_id uuid;
            BEGIN
                IF NEW.bootstrap_operation_id IS NULL THEN
                    RETURN NEW;
                END IF;

                INSERT INTO public.raw_material_permission_grant_operation_uses (
                    id, organization_id, bootstrap_operation_id, permission, created_at
                ) VALUES (
                    gen_random_uuid(), NEW.organization_id, NEW.bootstrap_operation_id,
                    NEW.permission, clock_timestamp()
                )
                ON CONFLICT (organization_id, bootstrap_operation_id, permission) DO NOTHING
                RETURNING id INTO use_id;

                IF use_id IS NULL THEN
                    RAISE EXCEPTION 'A bootstrap permission operation cannot grant the same permission twice'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $$;

            REVOKE ALL ON FUNCTION public.raw_materials_reject_bootstrap_grant_replay() FROM PUBLIC;
            SQL);

        DB::unprepared(sprintf(
            'GRANT EXECUTE ON FUNCTION public.raw_materials_reject_bootstrap_grant_replay() TO %s',
            $quotedRuntimeRole,
        ));
        DB::unprepared(sprintf(
            'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE public.raw_material_permission_grant_operation_uses FROM %s',
            $quotedRuntimeRole,
        ));
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można przywrócić zapisu runtime do rejestru użyć operacji uprawnień.');
    }
};
