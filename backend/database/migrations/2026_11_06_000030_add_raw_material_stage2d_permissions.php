<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.organization_permission_grants
                DROP CONSTRAINT organization_permission_grants_permission_check;
            ALTER TABLE public.organization_permission_grants
                ADD CONSTRAINT organization_permission_grants_permission_check CHECK (permission IN (
                    'RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE', 'RAW_MATERIALS_EDIT',
                    'RAW_MATERIALS_SUBMIT', 'RAW_MATERIALS_REVIEW', 'RAW_MATERIALS_APPROVE',
                    'RAW_MATERIALS_BLOCK', 'RAW_MATERIALS_UNBLOCK', 'RAW_MATERIALS_ARCHIVE',
                    'RAW_MATERIALS_RESTORE', 'RAW_MATERIALS_CATEGORY_MANAGE',
                    'RAW_MATERIALS_SUPPLIER_VIEW', 'RAW_MATERIALS_SUPPLIER_MANAGE',
                    'RAW_MATERIALS_PURCHASING_COSTS_VIEW', 'RAW_MATERIALS_PURCHASING_COSTS_MANAGE',
                    'RAW_MATERIALS_REFERENCE_PRICE_VIEW', 'RAW_MATERIALS_REFERENCE_PRICE_MANAGE',
                    'RAW_MATERIALS_EXCHANGE_RATE_VIEW', 'RAW_MATERIALS_EXCHANGE_RATE_MANAGE',
                    'RAW_MATERIALS_ALLERGEN_MANAGE', 'RAW_MATERIALS_STORAGE_MANAGE',
                    'RAW_MATERIALS_AUDIT_VIEW', 'RAW_MATERIALS_PERMISSION_DELEGATE'
                ));
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 030 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
