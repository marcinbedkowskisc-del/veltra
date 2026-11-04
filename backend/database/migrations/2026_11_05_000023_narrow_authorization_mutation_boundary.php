<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROTECTED_TABLES = [
        'organizations',
        'locations',
        'location_management_assignments',
        'location_supply_relationships',
        'users',
        'location_memberships',
        'role_assignments',
        'security_events',
        'membership_requests',
        'permission_grants',
        'report_shares',
        'activation_tokens',
        'password_reset_tokens',
        'account_email_deliveries',
        'location_module_states',
        'raw_material_revision_approval_events',
        'raw_material_audit_events',
        'supplier_product_revisions',
        'supplier_offer_events',
        'supplier_offer_fx_normalization_events',
        'organization_exchange_rate_events',
        'raw_material_reference_price_events',
        'raw_material_financial_restatements',
        'raw_materials_commercial_idempotency',
        'raw_materials_commercial_audit_events',
        'organization_permission_grants',
        'organization_permission_grant_events',
        'raw_material_permission_upgrade_operations',
        'raw_material_permission_grant_operation_uses',
    ];

    public function up(): void
    {
        if (! DB::scalar("SELECT to_regclass('veltra_private.mutation_signing_keys') IS NOT NULL")) {
            return;
        }

        $configuredKey = (string) config('authorization.database_mutation_signing_key', '');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $configuredKey)) {
            throw new RuntimeException('Wymagany jest niezależny, 32-bajtowy klucz podpisujący mutacje bazy danych zapisany jako 64 znaki hex.');
        }

        $signingKey = hash_hmac(
            'sha256',
            'veltra-db-authorization-ticket-v2',
            hex2bin($configuredKey),
            true,
        );

        DB::transaction(function () use ($signingKey): void {
            $protectedTriggerCount = DB::table('pg_trigger as triggers')
                ->join('pg_class as relations', 'relations.oid', '=', 'triggers.tgrelid')
                ->join('pg_namespace as namespaces', 'namespaces.oid', '=', 'relations.relnamespace')
                ->join('pg_proc as functions', 'functions.oid', '=', 'triggers.tgfoid')
                ->join('pg_namespace as function_namespaces', 'function_namespaces.oid', '=', 'functions.pronamespace')
                ->where('namespaces.nspname', 'public')
                ->where('function_namespaces.nspname', 'veltra_private')
                ->where('functions.proname', 'enforce_authorization_mutation')
                ->where('triggers.tgisinternal', false)
                ->whereIn('relations.relname', self::PROTECTED_TABLES)
                ->distinct('relations.oid')
                ->count('relations.oid');
            if ($protectedTriggerCount !== count(self::PROTECTED_TABLES)) {
                throw new RuntimeException('Nie znaleziono oczekiwanych triggerów ochronnych dla wszystkich krytycznych tabel.');
            }

            $removableTriggers = DB::select(<<<'SQL'
                SELECT trigger_relation.relname AS table_name, triggers.tgname AS trigger_name
                FROM pg_trigger triggers
                JOIN pg_class trigger_relation ON trigger_relation.oid = triggers.tgrelid
                JOIN pg_namespace trigger_namespace ON trigger_namespace.oid = trigger_relation.relnamespace
                JOIN pg_proc trigger_function ON trigger_function.oid = triggers.tgfoid
                JOIN pg_namespace function_namespace ON function_namespace.oid = trigger_function.pronamespace
                WHERE trigger_namespace.nspname = 'public'
                    AND NOT triggers.tgisinternal
                    AND function_namespace.nspname = 'veltra_private'
                    AND trigger_function.proname = 'enforce_authorization_mutation'
                    AND NOT (trigger_relation.relname = ANY (?::name[]))
                SQL, ['{'.implode(',', self::PROTECTED_TABLES).'}']);

            foreach ($removableTriggers as $trigger) {
                DB::statement(
                    'DROP TRIGGER '.$this->quoteIdentifier($trigger->trigger_name)
                        .' ON public.'.$this->quoteIdentifier($trigger->table_name),
                );
            }

            DB::table('veltra_private.authorization_mutation_scopes')
                ->whereNotIn('table_name', self::PROTECTED_TABLES)
                ->delete();

            $updatedKeys = DB::update(
                "UPDATE veltra_private.mutation_signing_keys SET signing_key = decode(?, 'hex') WHERE singleton",
                [bin2hex($signingKey)],
            );
            if ($updatedKeys !== 1) {
                throw new RuntimeException('Nie znaleziono dokładnie jednego rekordu klucza mutacji do ponownego ustawienia.');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 023 jest nieodwracalna; powrót wymaga osobnej, przetestowanej migracji do przodu.');
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
