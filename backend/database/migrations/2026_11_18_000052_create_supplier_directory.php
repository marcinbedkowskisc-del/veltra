<?php

use App\Domain\Suppliers\SupplierTaxIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::scalar("SELECT to_regclass('public.organization_suppliers') IS NOT NULL")
            || ! DB::scalar("SELECT to_regclass('veltra_private.authorization_mutation_scopes') IS NOT NULL")) {
            throw new RuntimeException('Migracja dostawców wymaga istniejącej kartoteki i ochrony mutacji.');
        }

        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.organization_suppliers
                    ALTER COLUMN display_name DROP NOT NULL,
                    DROP CONSTRAINT IF EXISTS organization_suppliers_display_name_check,
                    ADD CONSTRAINT organization_suppliers_display_name_check
                        CHECK (display_name IS NULL OR btrim(display_name) <> ''),
                    ADD COLUMN street_address varchar(240),
                    ADD COLUMN city varchar(120),
                    ADD COLUMN phone varchar(40),
                    ADD COLUMN email varchar(254),
                    ADD COLUMN website varchar(2048),
                    ADD COLUMN tax_identifier_fingerprint char(64)
                        CHECK (tax_identifier_fingerprint IS NULL OR tax_identifier_fingerprint ~ '^[0-9a-f]{64}$');

                UPDATE public.organization_suppliers
                    SET legal_name = display_name
                    WHERE legal_name IS NULL;

                CREATE INDEX organization_suppliers_tax_fingerprint_idx
                    ON public.organization_suppliers(organization_id, tax_identifier_fingerprint)
                    WHERE tax_identifier_fingerprint IS NOT NULL;

                CREATE TABLE public.supplier_contacts (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    supplier_id uuid NOT NULL,
                    full_name varchar(200) NOT NULL CHECK (btrim(full_name) <> ''),
                    position varchar(120) CHECK (position IS NULL OR btrim(position) <> ''),
                    phone varchar(40) CHECK (phone IS NULL OR btrim(phone) <> ''),
                    email varchar(254) CHECK (email IS NULL OR btrim(email) <> ''),
                    is_primary boolean NOT NULL DEFAULT false,
                    lifecycle_state varchar(16) NOT NULL DEFAULT 'ACTIVE'
                        CHECK (lifecycle_state IN ('ACTIVE', 'REMOVED')),
                    created_by uuid NOT NULL,
                    updated_by uuid NOT NULL,
                    removed_by uuid,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    removed_at timestamptz,
                    UNIQUE (id, organization_id),
                    FOREIGN KEY (supplier_id, organization_id)
                        REFERENCES public.organization_suppliers(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (created_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (updated_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (removed_by, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    CHECK (
                        (lifecycle_state = 'ACTIVE' AND removed_at IS NULL AND removed_by IS NULL)
                        OR (lifecycle_state = 'REMOVED' AND removed_at IS NOT NULL
                            AND removed_by IS NOT NULL AND is_primary = false)
                    )
                );
                CREATE INDEX supplier_contacts_search_idx
                    ON public.supplier_contacts(organization_id, full_name);
                CREATE INDEX supplier_contacts_supplier_idx
                    ON public.supplier_contacts(organization_id, supplier_id, lifecycle_state);
                CREATE UNIQUE INDEX supplier_contacts_one_primary_active
                    ON public.supplier_contacts(organization_id, supplier_id)
                    WHERE lifecycle_state = 'ACTIVE' AND is_primary;
                SQL);

            DB::table('organization_suppliers')
                ->whereNotNull('tax_identifier_ciphertext')
                ->orderBy('id')
                ->chunkById(100, function ($suppliers): void {
                    foreach ($suppliers as $supplier) {
                        $identifier = Crypt::decryptString($supplier->tax_identifier_ciphertext);
                        if (SupplierTaxIdentifier::normalize($identifier) === '') {
                            continue;
                        }
                        DB::table('organization_suppliers')->where('id', $supplier->id)->update([
                            'tax_identifier_fingerprint' => SupplierTaxIdentifier::fingerprint($identifier),
                        ]);
                    }
                }, 'id');

            foreach ([
                ['organization_suppliers', 'UPDATE', 'administration.supplier.restore'],
                ['supplier_contacts', 'INSERT', 'raw-material.commercial'],
                ['supplier_contacts', 'UPDATE', 'raw-material.commercial'],
            ] as [$table, $operation, $scope]) {
                DB::table('veltra_private.authorization_mutation_scopes')->insertOrIgnore([
                    'table_name' => $table,
                    'operation' => $operation,
                    'scope_prefix' => $scope,
                ]);
            }

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER supplier_contacts_authorization_ticket
                    BEFORE INSERT OR UPDATE OR DELETE ON public.supplier_contacts
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();
                CREATE TRIGGER supplier_contacts_serialize
                    BEFORE INSERT OR UPDATE OR DELETE ON public.supplier_contacts
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_commercial_serialize();

                CREATE OR REPLACE FUNCTION public.raw_materials_commercial_supplier_preserve()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                    actor_id uuid;
                    details_changed boolean;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Suppliers cannot be deleted' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.supplier_code IS DISTINCT FROM OLD.supplier_code
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Supplier identity is immutable' USING ERRCODE = '23514';
                    END IF;

                    details_changed := NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR NEW.legal_name IS DISTINCT FROM OLD.legal_name
                        OR NEW.tax_identifier_ciphertext IS DISTINCT FROM OLD.tax_identifier_ciphertext
                        OR NEW.tax_identifier_fingerprint IS DISTINCT FROM OLD.tax_identifier_fingerprint
                        OR NEW.street_address IS DISTINCT FROM OLD.street_address
                        OR NEW.city IS DISTINCT FROM OLD.city
                        OR NEW.phone IS DISTINCT FROM OLD.phone
                        OR NEW.email IS DISTINCT FROM OLD.email
                        OR NEW.website IS DISTINCT FROM OLD.website;

                    IF details_changed THEN
                        IF OLD.lifecycle_state <> 'ACTIVE'
                            OR NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state THEN
                            RAISE EXCEPTION 'Only active supplier details can be corrected'
                                USING ERRCODE = '23514';
                        END IF;

                        ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                        IF ticket->>'scope' IS DISTINCT FROM 'raw-material.supplier.correct'
                            OR ticket->>'subject_id' IS DISTINCT FROM OLD.id::text
                            OR coalesce(ticket->>'actor_id', '') !~* '^[0-9a-f-]{36}$' THEN
                            RAISE EXCEPTION 'Supplier correction requires a signed, supplier-bound actor'
                                USING ERRCODE = '42501';
                        END IF;

                        actor_id := (ticket->>'actor_id')::uuid;
                        IF NOT EXISTS (
                            SELECT 1
                            FROM public.users users
                            JOIN public.organizations organizations
                                ON organizations.id = users.organization_id
                            WHERE users.id = actor_id
                                AND users.organization_id = OLD.organization_id
                                AND users.is_active
                                AND users.email_verified_at IS NOT NULL
                                AND organizations.is_active
                        ) THEN
                            RAISE EXCEPTION 'Supplier correction actor is not active in this organization'
                                USING ERRCODE = '42501';
                        END IF;
                    ELSIF NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state THEN
                        ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                        IF OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state = 'ARCHIVED' THEN
                            RETURN NEW;
                        END IF;
                        IF OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state = 'ACTIVE'
                            AND ticket->>'scope' = 'administration.supplier.restore'
                            AND ticket->>'subject_id' = OLD.id::text
                            AND coalesce(ticket->>'actor_id', '') ~* '^[0-9a-f-]{36}$' THEN
                            actor_id := (ticket->>'actor_id')::uuid;
                            IF EXISTS (
                                SELECT 1
                                FROM public.users users
                                JOIN public.organizations organizations
                                    ON organizations.id = users.organization_id
                                JOIN public.role_assignments assignments
                                    ON assignments.user_id = users.id
                                    AND assignments.organization_id = users.organization_id
                                WHERE users.id = actor_id
                                    AND users.organization_id = OLD.organization_id
                                    AND users.is_active
                                    AND users.email_verified_at IS NOT NULL
                                    AND organizations.is_active
                                    AND assignments.role = 'ADMINISTRATOR'
                                    AND assignments.revoked_at IS NULL
                            ) THEN
                                RETURN NEW;
                            END IF;
                        END IF;
                        RAISE EXCEPTION 'Supplier archive history is immutable'
                            USING ERRCODE = '23514';
                    ELSIF NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
                        RAISE EXCEPTION 'Supplier timestamps cannot be changed independently'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$;

                CREATE OR REPLACE FUNCTION public.supplier_contact_preserve()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                DECLARE
                    ticket jsonb;
                    actor_id uuid;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Supplier contacts cannot be deleted'
                            USING ERRCODE = '23514';
                    END IF;

                    ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                    actor_id := nullif(ticket->>'actor_id', '')::uuid;
                    IF ticket->>'scope' IS DISTINCT FROM 'raw-material.commercial'
                        OR actor_id IS NULL
                        OR NOT EXISTS (
                            SELECT 1 FROM public.users users
                            JOIN public.organizations organizations
                                ON organizations.id = users.organization_id
                            WHERE users.id = actor_id
                                AND users.organization_id = NEW.organization_id
                                AND users.is_active
                                AND users.email_verified_at IS NOT NULL
                                AND organizations.is_active
                        ) THEN
                        RAISE EXCEPTION 'Supplier contact mutation requires an active signed actor'
                            USING ERRCODE = '42501';
                    END IF;

                    IF TG_OP = 'INSERT' THEN
                        IF NEW.created_by IS DISTINCT FROM actor_id
                            OR NEW.updated_by IS DISTINCT FROM actor_id THEN
                            RAISE EXCEPTION 'Supplier contact creator must match the signed actor'
                                USING ERRCODE = '42501';
                        END IF;
                        RETURN NEW;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.updated_by IS DISTINCT FROM actor_id
                        OR OLD.lifecycle_state = 'REMOVED'
                        OR (NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state
                            AND NOT (OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state = 'REMOVED')) THEN
                        RAISE EXCEPTION 'Supplier contact identity or removal history is immutable'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$;

                CREATE TRIGGER supplier_contacts_preserve
                    BEFORE UPDATE OR DELETE ON public.supplier_contacts
                    FOR EACH ROW EXECUTE FUNCTION public.supplier_contact_preserve();

                REVOKE ALL ON FUNCTION public.supplier_contact_preserve() FROM PUBLIC, veltra_app;
                GRANT SELECT, INSERT, UPDATE ON public.supplier_contacts TO veltra_app;
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja katalogu dostawców jest wyłącznie do przodu; usunięcie danych wymaga osobnej migracji.');
    }
};
