<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.raw_material_revisions
                    ADD COLUMN nominal_quantity numeric(24,6),
                    ADD COLUMN usable_quantity numeric(24,6),
                    ADD CONSTRAINT raw_revision_quantities_check CHECK (
                        (nominal_quantity IS NULL AND usable_quantity IS NULL)
                        OR (nominal_quantity IS NOT NULL AND usable_quantity IS NOT NULL
                            AND nominal_quantity > 0 AND usable_quantity > 0
                            AND (base_unit_code <> 'pc' OR
                                (nominal_quantity = trunc(nominal_quantity) AND usable_quantity = trunc(usable_quantity))))
                    );
                ALTER TABLE public.raw_material_reference_price_events
                    ADD COLUMN net_pln_per_package numeric(31,14)
                        CHECK (net_pln_per_package IS NULL OR (net_pln_per_package >= 0 AND event_type = 'PRICE_SET'));

                CREATE TABLE public.raw_material_sku_reservations (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    actor_user_id uuid NOT NULL,
                    location_id uuid NOT NULL,
                    sku varchar(16) NOT NULL,
                    idempotency_key varchar(120) NOT NULL,
                    created_at timestamptz NOT NULL,
                    expires_at timestamptz NOT NULL,
                    consumed_at timestamptz,
                    request_hash varchar(64),
                    response_body jsonb,
                    FOREIGN KEY (actor_user_id, organization_id) REFERENCES public.users(id, organization_id),
                    FOREIGN KEY (location_id, organization_id) REFERENCES public.locations(id, organization_id),
                    FOREIGN KEY (organization_id, sku) REFERENCES public.organization_sku_assignments(organization_id, sku),
                    UNIQUE (organization_id, actor_user_id, idempotency_key),
                    UNIQUE (organization_id, sku),
                    CHECK (expires_at > created_at),
                    CHECK ((consumed_at IS NULL AND request_hash IS NULL AND response_body IS NULL)
                        OR (consumed_at IS NOT NULL AND request_hash IS NOT NULL
                            AND request_hash ~ '^[0-9a-f]{64}$' AND response_body IS NOT NULL))
                );
                CREATE INDEX raw_sku_reservations_abuse_idx
                    ON public.raw_material_sku_reservations(organization_id, created_at, actor_user_id);

                CREATE FUNCTION public.raw_sku_reservation_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'SKU reservations cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        IF OLD.consumed_at IS NOT NULL OR NEW.consumed_at IS NULL
                            OR (to_jsonb(NEW) - 'consumed_at' - 'request_hash' - 'response_body')
                                IS DISTINCT FROM (to_jsonb(OLD) - 'consumed_at' - 'request_hash' - 'response_body')
                            OR NOT EXISTS (SELECT 1 FROM public.raw_materials m
                                WHERE m.id = NEW.id AND m.organization_id = NEW.organization_id AND m.code = NEW.sku) THEN
                            RAISE EXCEPTION 'Only a matching material may consume a reservation once' USING ERRCODE = '23514';
                        END IF;
                    ELSIF NEW.consumed_at IS NOT NULL OR NOT EXISTS (
                        SELECT 1 FROM public.organization_sku_assignments a
                        WHERE a.organization_id = NEW.organization_id AND a.sku = NEW.sku
                            AND a.entity_id = NEW.id AND a.assigned_by = NEW.actor_user_id AND a.prefix = 'RAW'
                    ) THEN
                        RAISE EXCEPTION 'Reservation requires its durable RAW assignment' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_sku_reservation_guard BEFORE INSERT OR UPDATE OR DELETE
                    ON public.raw_material_sku_reservations FOR EACH ROW EXECUTE FUNCTION public.raw_sku_reservation_guard();
                INSERT INTO veltra_private.runtime_table_allowlist(table_name) VALUES ('raw_material_sku_reservations');
                INSERT INTO veltra_private.authorization_mutation_scopes(table_name, operation, scope_prefix)
                    VALUES ('raw_material_sku_reservations', 'INSERT', 'raw-material.mutate'),
                           ('raw_material_sku_reservations', 'UPDATE', 'raw-material.mutate');
                CREATE TRIGGER raw_sku_reservation_ticket BEFORE INSERT OR UPDATE
                    ON public.raw_material_sku_reservations FOR EACH ROW
                    EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                REVOKE ALL ON FUNCTION public.raw_sku_reservation_guard() FROM PUBLIC;
                GRANT SELECT, INSERT, UPDATE ON public.raw_material_sku_reservations TO veltra_app;
                CREATE UNIQUE INDEX raw_sku_installation_unique ON public.organization_sku_assignments(sku) WHERE prefix = 'RAW';

                CREATE FUNCTION public.raw_sku_six_digit_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
                BEGIN
                    IF NEW.prefix = 'RAW' AND (NEW.sequence_number > 999999 OR NEW.sku !~ '^RAW-[0-9]{6}$') THEN
                        RAISE EXCEPTION 'New RAW allocations require exactly six digits' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                CREATE TRIGGER raw_sku_six_digit_guard BEFORE INSERT ON public.organization_sku_assignments
                    FOR EACH ROW EXECUTE FUNCTION public.raw_sku_six_digit_guard();
                REVOKE ALL ON FUNCTION public.raw_sku_six_digit_guard() FROM PUBLIC;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 042 jest forward-only; rezerwacji SKU nie wolno usuwać ani odzyskiwać.');
    }
};
