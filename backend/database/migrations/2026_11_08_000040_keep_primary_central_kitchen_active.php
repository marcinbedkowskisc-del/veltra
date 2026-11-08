<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $block$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM public.locations
                    WHERE is_primary_central_kitchen
                        AND NOT is_active
                ) THEN
                    RAISE EXCEPTION 'Cannot protect an already inactive primary central kitchen; explicit recovery is required'
                        USING ERRCODE = '23514';
                END IF;
            END
            $block$;

            CREATE OR REPLACE FUNCTION prevent_inactive_primary_central_kitchen()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            BEGIN
                IF NEW.is_primary_central_kitchen AND NEW.is_active IS NOT TRUE THEN
                    RAISE EXCEPTION 'Primary central kitchen must remain active'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $function$;

            CREATE TRIGGER locations_primary_central_kitchen_active
                BEFORE INSERT OR UPDATE ON public.locations
                FOR EACH ROW EXECUTE FUNCTION prevent_inactive_primary_central_kitchen();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 040 jest wyłącznie do przodu; powrót wymaga osobnej, przetestowanej migracji.');
    }
};
