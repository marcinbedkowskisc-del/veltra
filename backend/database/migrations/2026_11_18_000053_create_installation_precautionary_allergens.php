<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                CREATE TABLE public.installation_precautionary_allergens (
                    allergen_id varchar(40) PRIMARY KEY
                        REFERENCES public.international_allergen_canonical_definitions(id) ON DELETE RESTRICT,
                    added_by uuid NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
                );

                CREATE TRIGGER installation_precautionary_allergens_authorization_ticket
                    BEFORE INSERT OR DELETE ON public.installation_precautionary_allergens
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                SQL);

            DB::table('veltra_private.runtime_table_allowlist')->insertOrIgnore([
                'table_name' => 'installation_precautionary_allergens',
            ]);

            foreach (['INSERT', 'DELETE'] as $operation) {
                DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                    'table_name' => 'installation_precautionary_allergens',
                    'operation' => $operation,
                    'scope_prefix' => 'administration.allergen.manage',
                ]);
            }

            DB::unprepared(<<<'SQL'
                GRANT SELECT, INSERT, DELETE ON public.installation_precautionary_allergens TO veltra_app;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 053 jest wyłącznie do przodu; jej wycofanie wymaga osobnej, przetestowanej migracji.');
    }
};
