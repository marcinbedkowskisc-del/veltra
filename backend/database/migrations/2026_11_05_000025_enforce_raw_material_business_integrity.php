<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertPrerequisitesAndHistoricalData();

            DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                ['table_name' => 'raw_materials', 'operation' => 'UPDATE', 'scope_prefix' => 'raw-material.unblock'],
                ['table_name' => 'raw_material_audit_events', 'operation' => 'INSERT', 'scope_prefix' => 'raw-material.unblock'],
            ]);

            DB::statement('ALTER TABLE public.raw_material_audit_events ADD COLUMN transaction_id bigint');
            DB::statement('ALTER TABLE public.raw_material_audit_events ALTER COLUMN transaction_id SET DEFAULT txid_current()');
            DB::statement(<<<'SQL'
                CREATE INDEX raw_material_audit_unblock_tx_idx
                    ON public.raw_material_audit_events
                        (transaction_id, organization_id, subject_type, subject_id, actor_user_id)
                    WHERE event_code = 'UNBLOCKED'
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.raw_materials_unblock_ticket_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                BEGIN
                    IF OLD.lifecycle_state = 'BLOCKED' AND NEW.lifecycle_state = 'ACTIVE' THEN
                        ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                        IF ticket->>'scope' IS DISTINCT FROM 'raw-material.unblock'
                            OR ticket->>'subject_id' IS DISTINCT FROM NEW.id::text
                            OR coalesce(ticket->>'actor_id', '') = '' THEN
                            RAISE EXCEPTION 'Odblokowanie wymaga podpisanego kontekstu dla tego surowca'
                                USING ERRCODE = '42501';
                        END IF;
                        IF NEW.approved_revision_id IS DISTINCT FROM OLD.approved_revision_id
                            OR NEW.pending_revision_id IS NOT NULL
                            OR NEW.working_revision_id IS DISTINCT FROM OLD.working_revision_id
                            OR (to_jsonb(NEW) - ARRAY[
                                'lifecycle_state', 'blocked_at', 'blocked_by', 'block_reason', 'updated_at'
                            ]::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY[
                                'lifecycle_state', 'blocked_at', 'blocked_by', 'block_reason', 'updated_at'
                            ]::text[]) THEN
                            RAISE EXCEPTION 'Odblokowanie nie może zmieniać danych ani rewizji surowca'
                                USING ERRCODE = '23514';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.raw_material_audit_events events
                            WHERE events.organization_id = NEW.organization_id
                                AND events.subject_type = 'RAW_MATERIAL'
                                AND events.subject_id = NEW.id
                                AND events.event_code = 'UNBLOCKED'
                                AND events.outcome = 'ACCEPTED'
                                AND events.actor_user_id::text = ticket->>'actor_id'
                                AND events.transaction_id = txid_current()
                        ) THEN
                            RAISE EXCEPTION 'Odblokowanie wymaga niezmiennego zdarzenia audytowego w tej samej transakcji'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    RETURN NEW;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.raw_materials_unblock_audit_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                BEGIN
                    ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                    IF ticket->>'scope' = 'raw-material.unblock'
                        AND (
                            ticket->>'subject_id' IS DISTINCT FROM NEW.subject_id::text
                            OR ticket->>'actor_id' IS DISTINCT FROM NEW.actor_user_id::text
                            OR NEW.subject_type IS DISTINCT FROM 'RAW_MATERIAL'
                            OR NEW.event_code IS DISTINCT FROM 'UNBLOCKED'
                            OR NEW.outcome IS DISTINCT FROM 'ACCEPTED'
                            OR NEW.transaction_id IS DISTINCT FROM txid_current()
                        ) THEN
                        RAISE EXCEPTION 'Zdarzenie odblokowania nie odpowiada podpisanemu podmiotowi i aktorowi'
                            USING ERRCODE = '42501';
                    END IF;
                    RETURN NEW;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.supplier_product_initial_revision_validate()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    current_state varchar(16);
                BEGIN
                    SELECT products.lifecycle_state INTO current_state
                    FROM public.supplier_products products
                    WHERE products.id = NEW.id
                    FOR UPDATE;

                    IF current_state = 'ACTIVE' AND NOT EXISTS (
                        SELECT 1
                        FROM public.supplier_product_revisions revisions
                        WHERE revisions.supplier_product_id = NEW.id
                            AND revisions.organization_id = NEW.organization_id
                            AND revisions.revision_no = 1
                    ) THEN
                        RAISE EXCEPTION 'Aktywny produkt dostawcy wymaga rewizji początkowej'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END
                $function$
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER raw_materials_unblock_authorization_ticket
                    BEFORE UPDATE ON public.raw_materials
                    FOR EACH ROW
                    WHEN (OLD.lifecycle_state = 'BLOCKED' AND NEW.lifecycle_state = 'ACTIVE')
                    EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER raw_materials_z_unblock_ticket
                    BEFORE UPDATE ON public.raw_materials
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_unblock_ticket_validate();
                CREATE TRIGGER raw_material_audit_events_z_unblock_ticket
                    BEFORE INSERT ON public.raw_material_audit_events
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_unblock_audit_validate();
                CREATE CONSTRAINT TRIGGER supplier_products_initial_revision
                    AFTER INSERT OR UPDATE ON public.supplier_products
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW EXECUTE FUNCTION public.supplier_product_initial_revision_validate();
                ALTER TABLE public.supplier_offers
                    ADD CONSTRAINT supplier_offers_lifecycle_active_check
                    CHECK (lifecycle_state = 'ACTIVE') NOT VALID;
                ALTER TABLE public.supplier_offers
                    VALIDATE CONSTRAINT supplier_offers_lifecycle_active_check;
                SQL);

            $this->assertInstalledInvariants();
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migracja 025 jest nieodwracalna; zmiany integralności wymagają osobnej migracji naprawczej do przodu.',
        );
    }

    private function assertPrerequisitesAndHistoricalData(): void
    {
        foreach ([
            '2026_11_05_000022_enforce_signed_authorization_mutations',
            '2026_11_05_000023_narrow_authorization_mutation_boundary',
            '2026_11_05_000024_harden_raw_material_integrity_search_path',
        ] as $migration) {
            if (! DB::table('migrations')->where('migration', $migration)->exists()) {
                throw new RuntimeException("Migracja wymagana przed 025 nie została zastosowana: {$migration}.");
            }
        }

        foreach ([
            'public.raw_materials',
            'public.raw_material_audit_events',
            'public.supplier_products',
            'public.supplier_product_revisions',
            'public.supplier_offers',
            'veltra_private.authorization_mutation_scopes',
        ] as $relation) {
            if (! DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$relation])) {
                throw new RuntimeException("Brak wymaganej relacji przed migracją 025: {$relation}.");
            }
        }

        if (! DB::scalar("SELECT to_regprocedure('veltra_private.enforce_authorization_mutation()') IS NOT NULL")) {
            throw new RuntimeException('Brak walidatora podpisanych mutacji wymaganego przez migrację 025.');
        }

        $incompleteProducts = DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM public.supplier_products products
            WHERE products.lifecycle_state = 'ACTIVE'
                AND NOT EXISTS (
                    SELECT 1
                    FROM public.supplier_product_revisions revisions
                    WHERE revisions.supplier_product_id = products.id
                        AND revisions.organization_id = products.organization_id
                        AND revisions.revision_no = 1
                )
            SQL);
        if ((int) $incompleteProducts !== 0) {
            throw new RuntimeException(
                "Migracja 025 przerwana: znaleziono {$incompleteProducts} aktywnych produktów bez rewizji początkowej. Wymagany jest przegląd danych; migracja ich nie naprawia.",
            );
        }

        $withdrawnOffers = (int) DB::table('supplier_offers')->where('lifecycle_state', 'WITHDRAWN')->count();
        if ($withdrawnOffers !== 0) {
            throw new RuntimeException(
                "Migracja 025 przerwana: znaleziono {$withdrawnOffers} ofert ze stanem WITHDRAWN, którego nie da się potwierdzić jako wycofania całej oferty na podstawie obecnego ledgera cen. Wymagana jest decyzja właściciela danych; migracja nie zmienia historii.",
            );
        }

        if (DB::table('pg_trigger')->where('tgrelid', DB::raw("'public.raw_materials'::regclass"))
            ->whereIn('tgname', ['raw_materials_unblock_authorization_ticket', 'raw_materials_z_unblock_ticket'])
            ->exists()
            || DB::table('pg_trigger')->where('tgrelid', DB::raw("'public.raw_material_audit_events'::regclass"))
                ->where('tgname', 'raw_material_audit_events_z_unblock_ticket')->exists()
            || DB::table('pg_trigger')->where('tgrelid', DB::raw("'public.supplier_products'::regclass"))
                ->where('tgname', 'supplier_products_initial_revision')->exists()
            || DB::table('pg_constraint')->where('conrelid', DB::raw("'public.supplier_offers'::regclass"))
                ->where('conname', 'supplier_offers_lifecycle_active_check')->exists()) {
            throw new RuntimeException('Wykryto nieoczekiwany obiekt o nazwie zarezerwowanej przez migrację 025.');
        }
    }

    private function assertInstalledInvariants(): void
    {
        $missingTicketTriggers = DB::table('pg_trigger as triggers')
            ->join('pg_class as relations', 'relations.oid', '=', 'triggers.tgrelid')
            ->join('pg_namespace as namespaces', 'namespaces.oid', '=', 'relations.relnamespace')
            ->where('namespaces.nspname', 'public')
            ->whereIn('relations.relname', ['raw_materials', 'raw_material_audit_events'])
            ->whereIn('triggers.tgname', [
                'raw_materials_unblock_authorization_ticket',
                'raw_materials_z_unblock_ticket',
                'raw_material_audit_events_z_unblock_ticket',
            ])
            ->where('triggers.tgisinternal', false)
            ->count();
        if ($missingTicketTriggers !== 3) {
            throw new RuntimeException('Migracja 025 nie zainstalowała wszystkich triggerów odblokowania.');
        }

        $deferredConstraint = DB::table('pg_trigger as triggers')
            ->join('pg_class as relations', 'relations.oid', '=', 'triggers.tgrelid')
            ->join('pg_namespace as namespaces', 'namespaces.oid', '=', 'relations.relnamespace')
            ->where('namespaces.nspname', 'public')
            ->where('relations.relname', 'supplier_products')
            ->where('triggers.tgname', 'supplier_products_initial_revision')
            ->where('triggers.tgdeferrable', true)
            ->where('triggers.tginitdeferred', true)
            ->exists();
        if (! $deferredConstraint) {
            throw new RuntimeException('Brak odroczonego constraint triggera wymagającego rewizji początkowej.');
        }
    }
};
