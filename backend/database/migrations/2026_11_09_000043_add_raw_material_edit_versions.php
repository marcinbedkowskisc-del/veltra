<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.raw_materials ADD COLUMN edit_version uuid NOT NULL DEFAULT gen_random_uuid();
            CREATE FUNCTION public.raw_materials_advance_edit_version() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                NEW.edit_version := gen_random_uuid();
                RETURN NEW;
            END $$;
            -- Generate metadata after the existing guards have validated the requested changes.
            CREATE TRIGGER raw_materials_zz_advance_edit_version
                BEFORE UPDATE ON public.raw_materials
                FOR EACH ROW EXECUTE FUNCTION public.raw_materials_advance_edit_version();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja wersji edycji RAW jest nieodwracalna.');
    }
};
