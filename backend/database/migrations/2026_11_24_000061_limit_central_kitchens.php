<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE public.locations IN ACCESS EXCLUSIVE MODE;
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM public.locations
                    WHERE type = 'CENTRAL_KITCHEN'
                    GROUP BY organization_id
                    HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'Multiple central kitchen records exist; resolve them explicitly before migration'
                        USING ERRCODE = '23514';
                END IF;
            END $$;
            CREATE UNIQUE INDEX locations_one_central_kitchen_per_org
                ON public.locations (organization_id)
                WHERE type = 'CENTRAL_KITCHEN';
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja jest tylko do przodu; wycofanie wymaga osobnej migracji.');
    }
};
