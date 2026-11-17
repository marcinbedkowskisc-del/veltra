<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.raw_material_categories ADD COLUMN description text NULL');
        DB::statement('ALTER TABLE public.raw_material_categories ADD COLUMN archived_at timestamptz NULL');
        DB::statement('ALTER TABLE public.raw_material_categories ADD COLUMN archived_by uuid NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE public.raw_material_categories
                ADD CONSTRAINT raw_material_categories_archive_actor_fk
                    FOREIGN KEY (archived_by, organization_id)
                    REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                ADD CONSTRAINT raw_material_categories_archive_state_check
                    CHECK ((archived_at IS NULL) = (archived_by IS NULL))
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX raw_material_categories_lifecycle_idx
                ON public.raw_material_categories(organization_id, archived_at, parent_id, name)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS public.raw_material_categories_lifecycle_idx');
        DB::statement(<<<'SQL'
            ALTER TABLE public.raw_material_categories
                DROP CONSTRAINT IF EXISTS raw_material_categories_archive_state_check,
                DROP CONSTRAINT IF EXISTS raw_material_categories_archive_actor_fk,
                DROP COLUMN IF EXISTS archived_by,
                DROP COLUMN IF EXISTS archived_at,
                DROP COLUMN IF EXISTS description
            SQL);
    }
};
