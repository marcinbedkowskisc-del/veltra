<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE public.system_permission_manifests
                ADD COLUMN content_hash char(64)
                    CHECK (content_hash IS NULL OR content_hash ~ '^[0-9a-f]{64}$')
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja utrwalająca skróty manifestów jest forward-only.');
    }
};
