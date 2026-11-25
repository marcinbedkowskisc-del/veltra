<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE public.role_assignments IN ACCESS EXCLUSIVE MODE;
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM public.role_assignments
                    WHERE revoked_at IS NULL
                    GROUP BY user_id
                    HAVING count(DISTINCT role) > 1
                ) THEN
                    RAISE EXCEPTION 'Conflicting active business roles exist; resolve them explicitly before migration'
                        USING ERRCODE = '23514';
                END IF;
            END $$;
            ALTER TABLE public.role_assignments
                ADD CONSTRAINT roles_one_business_role_per_user
                EXCLUDE USING gist (user_id WITH =, role WITH <>)
                WHERE (revoked_at IS NULL);
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja jest tylko do przodu; wycofanie wymaga osobnej migracji.');
    }
};
