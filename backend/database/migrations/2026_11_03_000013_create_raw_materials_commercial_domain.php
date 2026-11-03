<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organization_permission_grants
                DROP CONSTRAINT organization_permission_grants_permission_check;
            ALTER TABLE organization_permission_grants
                ADD CONSTRAINT organization_permission_grants_permission_check CHECK (permission IN (
                    'RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE', 'RAW_MATERIALS_EDIT',
                    'RAW_MATERIALS_SUBMIT', 'RAW_MATERIALS_REVIEW', 'RAW_MATERIALS_APPROVE',
                    'RAW_MATERIALS_BLOCK', 'RAW_MATERIALS_UNBLOCK', 'RAW_MATERIALS_ARCHIVE',
                    'RAW_MATERIALS_RESTORE', 'RAW_MATERIALS_CATEGORY_MANAGE',
                    'RAW_MATERIALS_SUPPLIER_VIEW', 'RAW_MATERIALS_SUPPLIER_MANAGE',
                    'RAW_MATERIALS_PURCHASING_COSTS_VIEW', 'RAW_MATERIALS_PURCHASING_COSTS_MANAGE',
                    'RAW_MATERIALS_REFERENCE_PRICE_VIEW', 'RAW_MATERIALS_REFERENCE_PRICE_MANAGE',
                    'RAW_MATERIALS_EXCHANGE_RATE_VIEW', 'RAW_MATERIALS_EXCHANGE_RATE_MANAGE',
                    'RAW_MATERIALS_AUDIT_VIEW', 'RAW_MATERIALS_PERMISSION_DELEGATE'
                ));

            CREATE OR REPLACE FUNCTION raw_materials_round_half_even_v1(input_value numeric, output_scale integer)
            RETURNS numeric
            LANGUAGE plpgsql
            IMMUTABLE
            STRICT
            AS $$
            DECLARE
                factor numeric;
                scaled numeric;
                integral numeric;
                fraction numeric;
                sign_value numeric;
            BEGIN
                IF output_scale < 0 OR output_scale > 14
                    OR input_value::text IN ('NaN', 'Infinity', '-Infinity') THEN
                    RAISE EXCEPTION 'Invalid half-even input' USING ERRCODE = '22023';
                END IF;
                factor := power(10::numeric, output_scale);
                sign_value := CASE WHEN input_value < 0 THEN -1 ELSE 1 END;
                scaled := abs(input_value) * factor;
                integral := trunc(scaled);
                fraction := scaled - integral;
                IF fraction > 0.5 OR (fraction = 0.5 AND mod(integral, 2) = 1) THEN
                    integral := integral + 1;
                END IF;

                RETURN trunc(sign_value * integral / factor, output_scale);
            END
            $$;

            CREATE TABLE raw_materials_currency_codes (
                currency_code char(3) PRIMARY KEY CHECK (currency_code ~ '^[A-Z]{3}$'),
                currency_name varchar(80) NOT NULL,
                valid_from date NOT NULL,
                valid_until date,
                CHECK (valid_until IS NULL OR valid_until > valid_from)
            );
            INSERT INTO raw_materials_currency_codes (currency_code, currency_name, valid_from) VALUES
                ('PLN', 'złoty polski', '1995-01-01'),
                ('EUR', 'euro', '1999-01-01'),
                ('USD', 'dolar amerykański', '1792-01-01'),
                ('GBP', 'funt szterling', '1694-01-01'),
                ('CHF', 'frank szwajcarski', '1850-01-01'),
                ('CZK', 'korona czeska', '1993-01-01'),
                ('SEK', 'korona szwedzka', '1873-01-01'),
                ('NOK', 'korona norweska', '1875-01-01'),
                ('DKK', 'korona duńska', '1875-01-01'),
                ('HUF', 'forint węgierski', '1946-01-01'),
                ('JPY', 'jen japoński', '1871-01-01'),
                ('CAD', 'dolar kanadyjski', '1858-01-01'),
                ('AUD', 'dolar australijski', '1966-01-01'),
                ('BRL', 'real brazylijski', '1994-01-01'),
                ('CLP', 'peso chilijskie', '2017-01-01'),
                ('CNY', 'yuan chiński', '1948-01-01'),
                ('HKD', 'dolar hongkoński', '1935-01-01'),
                ('IDR', 'rupia indonezyjska', '1949-01-01'),
                ('ISK', 'korona islandzka', '1981-01-01'),
                ('ILS', 'nowy szekel izraelski', '1985-01-01'),
                ('INR', 'rupia indyjska', '1957-01-01'),
                ('KRW', 'won południowokoreański', '1962-01-01'),
                ('MXN', 'peso meksykańskie', '1993-01-01'),
                ('MYR', 'ringgit malezyjski', '1975-01-01'),
                ('NZD', 'dolar nowozelandzki', '1967-01-01'),
                ('PHP', 'peso filipińskie', '1949-01-01'),
                ('RON', 'lej rumuński', '2005-01-01'),
                ('SGD', 'dolar singapurski', '1967-01-01'),
                ('THB', 'bat tajlandzki', '1928-01-01'),
                ('TRY', 'lira turecka', '2005-01-01'),
                ('UAH', 'hrywna ukraińska', '1996-01-01'),
                ('XDR', 'specjalne prawo ciągnienia MFW', '1969-01-01'),
                ('ZAR', 'rand południowoafrykański', '1961-01-01');
            INSERT INTO raw_materials_currency_codes
                (currency_code, currency_name, valid_from, valid_until)
            VALUES ('BGN', 'lew bułgarski', '1999-01-01', '2026-01-01');

            ALTER TABLE raw_material_revision_units
                ADD CONSTRAINT raw_material_revision_units_composite_unique
                    UNIQUE (id, revision_id, organization_id);

            CREATE TABLE organization_suppliers (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                supplier_code varchar(40) NOT NULL CHECK (supplier_code ~ '^[A-Z0-9_-]+$'),
                display_name varchar(200) NOT NULL CHECK (btrim(display_name) <> ''),
                legal_name varchar(240),
                tax_identifier_ciphertext text,
                lifecycle_state varchar(16) NOT NULL DEFAULT 'ACTIVE'
                    CHECK (lifecycle_state IN ('ACTIVE', 'ARCHIVED')),
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (organization_id, supplier_code),
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (legal_name IS NULL OR btrim(legal_name) <> '')
            );
            CREATE INDEX organization_suppliers_search_idx
                ON organization_suppliers(organization_id, lifecycle_state, display_name);

            CREATE TABLE supplier_products (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                supplier_id uuid NOT NULL,
                raw_material_id uuid NOT NULL,
                lifecycle_state varchar(16) NOT NULL DEFAULT 'ACTIVE'
                    CHECK (lifecycle_state IN ('ACTIVE', 'ARCHIVED')),
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                archived_at timestamptz,
                archived_by uuid,
                UNIQUE (id, organization_id),
                UNIQUE (id, organization_id, raw_material_id),
                FOREIGN KEY (supplier_id, organization_id)
                    REFERENCES organization_suppliers(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (archived_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK ((lifecycle_state = 'ARCHIVED' AND archived_at IS NOT NULL AND archived_by IS NOT NULL)
                    OR (lifecycle_state = 'ACTIVE' AND archived_at IS NULL AND archived_by IS NULL))
            );
            CREATE INDEX supplier_products_material_idx
                ON supplier_products(organization_id, raw_material_id, lifecycle_state);
            CREATE INDEX supplier_products_supplier_idx
                ON supplier_products(organization_id, supplier_id, lifecycle_state);

            CREATE TABLE supplier_product_revisions (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                supplier_product_id uuid NOT NULL,
                revision_no integer NOT NULL CHECK (revision_no > 0),
                raw_material_id uuid NOT NULL,
                raw_material_revision_id uuid NOT NULL,
                raw_material_revision_unit_id uuid NOT NULL,
                supplier_sku varchar(100),
                gtin varchar(14),
                product_name varchar(200) NOT NULL CHECK (btrim(product_name) <> ''),
                purchase_unit_code varchar(24) NOT NULL CHECK (purchase_unit_code ~ '^[A-Z][A-Z0-9_-]{0,23}$'),
                content_quantity numeric NOT NULL,
                content_unit_code varchar(24) NOT NULL CHECK (content_unit_code IN ('mg', 'g', 'kg', 'ml', 'l', 'pc')),
                to_base_factor_snapshot numeric NOT NULL,
                base_quantity_snapshot numeric NOT NULL,
                content_hash char(64) NOT NULL CHECK (content_hash ~ '^[0-9a-f]{64}$'),
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (id, supplier_product_id, organization_id),
                UNIQUE (supplier_product_id, revision_no),
                FOREIGN KEY (supplier_product_id, organization_id, raw_material_id)
                    REFERENCES supplier_products(id, organization_id, raw_material_id) ON DELETE RESTRICT,
                FOREIGN KEY (raw_material_revision_id, raw_material_id, organization_id)
                    REFERENCES raw_material_revisions(id, raw_material_id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (raw_material_revision_unit_id, raw_material_revision_id, organization_id)
                    REFERENCES raw_material_revision_units(id, revision_id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (content_quantity > 0 AND content_quantity < 1000000000000000
                    AND scale(content_quantity) <= 9),
                CHECK (to_base_factor_snapshot > 0 AND to_base_factor_snapshot < 1000000000000
                    AND scale(to_base_factor_snapshot) <= 12),
                CHECK (base_quantity_snapshot > 0 AND base_quantity_snapshot < 1000000000000000000
                    AND scale(base_quantity_snapshot) <= 14),
                CHECK (supplier_sku IS NULL OR btrim(supplier_sku) <> ''),
                CHECK (gtin IS NULL OR gtin ~ '^[0-9]{8,14}$')
            );
            CREATE INDEX supplier_product_revision_sku_idx
                ON supplier_product_revisions(organization_id, supplier_sku)
                WHERE supplier_sku IS NOT NULL;
            CREATE INDEX supplier_product_revisions_product_idx
                ON supplier_product_revisions(organization_id, supplier_product_id, revision_no DESC);

            CREATE TABLE supplier_offers (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                supplier_product_id uuid NOT NULL,
                offer_reference varchar(100),
                lifecycle_state varchar(16) NOT NULL DEFAULT 'ACTIVE'
                    CHECK (lifecycle_state IN ('ACTIVE', 'WITHDRAWN')),
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                FOREIGN KEY (supplier_product_id, organization_id)
                    REFERENCES supplier_products(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (offer_reference IS NULL OR btrim(offer_reference) <> '')
            );
            CREATE INDEX supplier_offers_product_idx
                ON supplier_offers(organization_id, supplier_product_id, lifecycle_state);

            CREATE TABLE organization_exchange_rate_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                base_currency_code char(3) NOT NULL DEFAULT 'PLN' CHECK (base_currency_code = 'PLN'),
                quote_currency_code char(3) NOT NULL REFERENCES raw_materials_currency_codes(currency_code) ON DELETE RESTRICT,
                rate_date date NOT NULL,
                pln_per_quote_unit numeric NOT NULL,
                source_code varchar(24) NOT NULL CHECK (source_code IN ('NBP_TABLE_A', 'MANUAL_OVERRIDE')),
                source_rate_id varchar(24),
                source_payload_hash char(64) CHECK (source_payload_hash IS NULL OR source_payload_hash ~ '^[0-9a-f]{64}$'),
                revision_no integer NOT NULL CHECK (revision_no > 0),
                override_of_id uuid,
                supersedes_id uuid,
                recorded_by uuid,
                reason_code varchar(80),
                operation_id uuid NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (id, organization_id, quote_currency_code, rate_date),
                UNIQUE (organization_id, quote_currency_code, rate_date, revision_no),
                UNIQUE (organization_id, quote_currency_code, source_rate_id, revision_no),
                FOREIGN KEY (quote_currency_code)
                    REFERENCES raw_materials_currency_codes(currency_code) ON DELETE RESTRICT,
                FOREIGN KEY (override_of_id, organization_id)
                    REFERENCES organization_exchange_rate_events(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (supersedes_id, organization_id)
                    REFERENCES organization_exchange_rate_events(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (recorded_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (quote_currency_code <> 'PLN'),
                CHECK (pln_per_quote_unit > 0 AND pln_per_quote_unit < 1000000000000
                    AND scale(pln_per_quote_unit) <= 12),
                CHECK ((source_code = 'NBP_TABLE_A' AND source_rate_id IS NOT NULL
                        AND source_payload_hash IS NOT NULL AND override_of_id IS NULL
                        AND recorded_by IS NULL AND reason_code IS NULL)
                    OR (source_code = 'MANUAL_OVERRIDE' AND source_rate_id IS NULL
                        AND source_payload_hash IS NULL AND override_of_id IS NOT NULL
                        AND recorded_by IS NOT NULL AND reason_code IS NOT NULL
                        AND btrim(reason_code) <> ''))
            );
            CREATE INDEX organization_exchange_rates_as_of_idx
                ON organization_exchange_rate_events(organization_id, quote_currency_code, rate_date DESC, revision_no DESC);

            CREATE TABLE supplier_offer_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                offer_id uuid NOT NULL,
                supplier_product_id uuid NOT NULL,
                supplier_product_revision_id uuid NOT NULL,
                event_no integer NOT NULL CHECK (event_no > 0),
                event_type varchar(16) NOT NULL CHECK (event_type IN ('PRICE_SET', 'WITHDRAWN')),
                valid_from date NOT NULL,
                valid_until date,
                net_amount_per_purchase_unit numeric,
                currency_code char(3),
                minimum_order_quantity numeric,
                quantity_from numeric,
                quantity_until numeric,
                source_code varchar(40),
                source_reference varchar(160),
                supersedes_event_id uuid,
                recorded_by uuid NOT NULL,
                reason_code varchar(80) NOT NULL CHECK (btrim(reason_code) <> ''),
                operation_id uuid NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (id, organization_id, supplier_product_revision_id),
                UNIQUE (offer_id, event_no),
                FOREIGN KEY (offer_id, organization_id) REFERENCES supplier_offers(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (supplier_product_id, organization_id)
                    REFERENCES supplier_products(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (supplier_product_revision_id, supplier_product_id, organization_id)
                    REFERENCES supplier_product_revisions(id, supplier_product_id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (currency_code) REFERENCES raw_materials_currency_codes(currency_code) ON DELETE RESTRICT,
                FOREIGN KEY (supersedes_event_id, organization_id)
                    REFERENCES supplier_offer_events(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (recorded_by, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (valid_until IS NULL OR valid_until > valid_from),
                CHECK ((event_type = 'PRICE_SET' AND net_amount_per_purchase_unit IS NOT NULL
                        AND currency_code IS NOT NULL AND quantity_from IS NOT NULL
                        AND net_amount_per_purchase_unit >= 0
                        AND net_amount_per_purchase_unit < 100000000000000000000
                        AND scale(net_amount_per_purchase_unit) <= 8
                        AND quantity_from > 0 AND scale(quantity_from) <= 9
                        AND (quantity_until IS NULL OR (quantity_until > quantity_from AND scale(quantity_until) <= 9))
                        AND (minimum_order_quantity IS NULL OR (minimum_order_quantity > 0 AND scale(minimum_order_quantity) <= 9))
                        AND supersedes_event_id IS NULL)
                    OR (event_type = 'WITHDRAWN' AND net_amount_per_purchase_unit IS NULL
                        AND currency_code IS NULL AND minimum_order_quantity IS NULL
                        AND quantity_from IS NULL AND quantity_until IS NULL
                        AND supersedes_event_id IS NOT NULL))
            );
            CREATE UNIQUE INDEX supplier_offer_event_withdrawal_unique
                ON supplier_offer_events(supersedes_event_id) WHERE event_type = 'WITHDRAWN';
            CREATE INDEX supplier_offer_events_history_idx
                ON supplier_offer_events(organization_id, offer_id, event_no DESC);

            CREATE TABLE supplier_offer_fx_normalization_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                supplier_offer_event_id uuid NOT NULL,
                exchange_rate_event_id uuid,
                rate_snapshot numeric NOT NULL,
                operation_date date NOT NULL,
                rate_date date,
                source_code varchar(24) NOT NULL CHECK (source_code IN ('PLN_IDENTITY', 'NBP_TABLE_A', 'MANUAL_OVERRIDE')),
                reporting_amount_pln_per_purchase_unit numeric NOT NULL,
                reporting_amount_pln_per_base_unit numeric NOT NULL,
                rounding_rule varchar(32) NOT NULL DEFAULT 'HALF_EVEN_V1' CHECK (rounding_rule = 'HALF_EVEN_V1'),
                recorded_by uuid NOT NULL,
                operation_id uuid NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                FOREIGN KEY (supplier_offer_event_id, organization_id)
                    REFERENCES supplier_offer_events(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (exchange_rate_event_id, organization_id)
                    REFERENCES organization_exchange_rate_events(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (recorded_by, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (rate_snapshot > 0 AND rate_snapshot < 1000000000000 AND scale(rate_snapshot) <= 12),
                CHECK (reporting_amount_pln_per_purchase_unit >= 0
                    AND scale(reporting_amount_pln_per_purchase_unit) <= 14
                    AND reporting_amount_pln_per_purchase_unit =
                        raw_materials_round_half_even_v1(reporting_amount_pln_per_purchase_unit, 14)),
                CHECK (reporting_amount_pln_per_base_unit >= 0
                    AND scale(reporting_amount_pln_per_base_unit) <= 14
                    AND reporting_amount_pln_per_base_unit =
                        raw_materials_round_half_even_v1(reporting_amount_pln_per_base_unit, 14))
            );
            CREATE INDEX supplier_offer_normalization_history_idx
                ON supplier_offer_fx_normalization_events(organization_id, supplier_offer_event_id, recorded_at DESC);

            CREATE TABLE raw_material_reference_price_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                raw_material_id uuid NOT NULL,
                event_no integer NOT NULL CHECK (event_no > 0),
                event_type varchar(20) NOT NULL CHECK (event_type IN ('PRICE_SET', 'PRICE_WITHDRAWN')),
                effective_from date NOT NULL,
                net_pln_per_base_unit numeric,
                source_net_amount numeric,
                source_currency_code char(3),
                source_offer_event_id uuid,
                source_product_revision_id uuid,
                exchange_rate_event_id uuid,
                rate_snapshot numeric,
                operation_date date,
                rate_date date,
                source_code varchar(24),
                reason_code varchar(80) NOT NULL CHECK (btrim(reason_code) <> ''),
                recorded_by uuid NOT NULL,
                operation_id uuid NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (id, organization_id, raw_material_id),
                UNIQUE (raw_material_id, event_no),
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (source_offer_event_id, organization_id, source_product_revision_id)
                    REFERENCES supplier_offer_events(id, organization_id, supplier_product_revision_id) ON DELETE RESTRICT,
                FOREIGN KEY (source_currency_code)
                    REFERENCES raw_materials_currency_codes(currency_code) ON DELETE RESTRICT,
                FOREIGN KEY (exchange_rate_event_id, organization_id)
                    REFERENCES organization_exchange_rate_events(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (recorded_by, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK ((event_type = 'PRICE_SET' AND net_pln_per_base_unit IS NOT NULL
                        AND net_pln_per_base_unit >= 0 AND net_pln_per_base_unit < 100000000000000000
                        AND scale(net_pln_per_base_unit) <= 14)
                    OR (event_type = 'PRICE_WITHDRAWN' AND net_pln_per_base_unit IS NULL
                        AND source_net_amount IS NULL AND source_currency_code IS NULL
                        AND source_offer_event_id IS NULL AND source_product_revision_id IS NULL
                        AND exchange_rate_event_id IS NULL AND rate_snapshot IS NULL
                        AND operation_date IS NULL AND rate_date IS NULL AND source_code IS NULL)),
                CHECK ((source_net_amount IS NULL AND source_currency_code IS NULL
                        AND source_offer_event_id IS NULL AND source_product_revision_id IS NULL
                        AND exchange_rate_event_id IS NULL AND rate_snapshot IS NULL
                        AND operation_date IS NULL AND rate_date IS NULL AND source_code IS NULL)
                    OR (event_type = 'PRICE_SET' AND source_net_amount IS NOT NULL
                        AND source_currency_code IS NOT NULL AND source_offer_event_id IS NOT NULL
                        AND source_product_revision_id IS NOT NULL AND operation_date IS NOT NULL
                        AND source_code IS NOT NULL AND source_net_amount >= 0
                        AND scale(source_net_amount) <= 8
                        AND ((source_currency_code = 'PLN' AND exchange_rate_event_id IS NULL
                                AND rate_snapshot = 1 AND source_code = 'PLN_IDENTITY')
                            OR (source_currency_code <> 'PLN' AND exchange_rate_event_id IS NOT NULL
                                AND rate_snapshot IS NOT NULL AND rate_date IS NOT NULL
                                AND source_code IN ('NBP_TABLE_A', 'MANUAL_OVERRIDE')))))
            );
            CREATE INDEX raw_material_reference_price_as_of_idx
                ON raw_material_reference_price_events(organization_id, raw_material_id, effective_from DESC, event_no DESC);

            CREATE TABLE raw_material_financial_restatements (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                raw_material_id uuid NOT NULL,
                original_reference_price_event_id uuid NOT NULL,
                original_snapshot_hash char(64) NOT NULL CHECK (original_snapshot_hash ~ '^[0-9a-f]{64}$'),
                restated_net_pln_per_base_unit numeric NOT NULL,
                currency_code char(3) NOT NULL DEFAULT 'PLN' CHECK (currency_code = 'PLN'),
                operation_date date NOT NULL,
                reason_code varchar(80) NOT NULL CHECK (btrim(reason_code) <> ''),
                recorded_by uuid NOT NULL,
                operation_id uuid NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (original_reference_price_event_id, organization_id, raw_material_id)
                    REFERENCES raw_material_reference_price_events(id, organization_id, raw_material_id) ON DELETE RESTRICT,
                FOREIGN KEY (recorded_by, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK (restated_net_pln_per_base_unit >= 0
                    AND restated_net_pln_per_base_unit < 100000000000000000
                    AND scale(restated_net_pln_per_base_unit) <= 14
                    AND restated_net_pln_per_base_unit =
                        raw_materials_round_half_even_v1(restated_net_pln_per_base_unit, 14))
            );
            CREATE INDEX raw_material_financial_restatements_history_idx
                ON raw_material_financial_restatements(organization_id, original_reference_price_event_id, recorded_at DESC);

            CREATE TABLE raw_materials_commercial_idempotency (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                actor_user_id uuid NOT NULL,
                operation_code varchar(100) NOT NULL,
                idempotency_key varchar(120) NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                response_body jsonb NOT NULL CHECK (jsonb_typeof(response_body) = 'object'),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (organization_id, actor_user_id, operation_code, idempotency_key),
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_materials_commercial_idempotency_created_idx
                ON raw_materials_commercial_idempotency(organization_id, created_at);

            CREATE TABLE raw_materials_commercial_audit_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                operation_id uuid NOT NULL,
                actor_user_id uuid,
                actor_location_id uuid,
                subject_type varchar(60) NOT NULL,
                subject_id uuid,
                event_code varchar(80) NOT NULL CHECK (event_code ~ '^[A-Z][A-Z0-9_]{0,79}$'),
                outcome varchar(16) NOT NULL CHECK (outcome IN ('ACCEPTED', 'REJECTED')),
                reason_code varchar(80),
                before_hash char(64) CHECK (before_hash IS NULL OR before_hash ~ '^[0-9a-f]{64}$'),
                after_hash char(64) CHECK (after_hash IS NULL OR after_hash ~ '^[0-9a-f]{64}$'),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (actor_location_id, organization_id)
                    REFERENCES locations(id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_materials_commercial_audit_time_idx
                ON raw_materials_commercial_audit_events(organization_id, occurred_at DESC);
            CREATE INDEX raw_materials_commercial_audit_subject_idx
                ON raw_materials_commercial_audit_events(organization_id, subject_type, subject_id, occurred_at DESC);

            CREATE FUNCTION raw_materials_commercial_serialize() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE organization_key uuid;
            BEGIN
                organization_key := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
                PERFORM id FROM organizations WHERE id = organization_key FOR UPDATE;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END
            $$;

            CREATE FUNCTION raw_materials_commercial_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Commercial history is append-only' USING ERRCODE = '23514';
            END
            $$;

            CREATE FUNCTION raw_materials_commercial_product_revision_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                unit_factor numeric;
                material_state varchar(16);
                approved_revision uuid;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT lifecycle_state, approved_revision_id INTO material_state, approved_revision
                    FROM raw_materials WHERE id = NEW.raw_material_id
                        AND organization_id = NEW.organization_id FOR SHARE;
                IF material_state <> 'ACTIVE' OR approved_revision IS DISTINCT FROM NEW.raw_material_revision_id THEN
                    RAISE EXCEPTION 'Supplier product requires the current approved raw material revision'
                        USING ERRCODE = '23514';
                END IF;
                SELECT to_base_factor INTO unit_factor FROM raw_material_revision_units
                    WHERE id = NEW.raw_material_revision_unit_id
                        AND revision_id = NEW.raw_material_revision_id
                        AND organization_id = NEW.organization_id
                        AND unit_code = NEW.content_unit_code;
                IF unit_factor IS NULL THEN
                    RAISE EXCEPTION 'Supplier product unit does not belong to the approved revision'
                        USING ERRCODE = '23514';
                END IF;
                NEW.to_base_factor_snapshot := unit_factor;
                NEW.base_quantity_snapshot := raw_materials_round_half_even_v1(
                    NEW.content_quantity * unit_factor, 14);
                NEW.content_hash := encode(sha256(convert_to(concat_ws('|', NEW.supplier_product_id, NEW.revision_no,
                    NEW.raw_material_revision_id, NEW.raw_material_revision_unit_id, NEW.supplier_sku,
                    NEW.gtin, NEW.product_name, NEW.purchase_unit_code, NEW.content_quantity,
                    NEW.content_unit_code, NEW.to_base_factor_snapshot, NEW.base_quantity_snapshot), 'UTF8')), 'hex');
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER supplier_product_revisions_validate
                BEFORE INSERT ON supplier_product_revisions
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_product_revision_validate();

            CREATE FUNCTION raw_materials_commercial_sku_unique() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.supplier_sku IS NOT NULL AND EXISTS (
                    SELECT 1 FROM supplier_product_revisions existing
                    JOIN supplier_products existing_products
                        ON existing_products.id = existing.supplier_product_id
                        AND existing_products.organization_id = existing.organization_id
                    JOIN supplier_products new_products
                        ON new_products.id = NEW.supplier_product_id
                        AND new_products.organization_id = NEW.organization_id
                    WHERE existing.organization_id = NEW.organization_id
                        AND existing.supplier_sku = NEW.supplier_sku
                        AND existing.supplier_product_id <> NEW.supplier_product_id
                        AND existing_products.supplier_id = new_products.supplier_id
                ) THEN
                    RAISE EXCEPTION 'Supplier SKU already exists for this supplier' USING ERRCODE = '23505';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER supplier_product_revisions_sku_unique
                BEFORE INSERT ON supplier_product_revisions
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_sku_unique();

            CREATE FUNCTION raw_materials_commercial_offer_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF NOT EXISTS (
                    SELECT 1
                    FROM supplier_products products
                    JOIN organization_suppliers suppliers
                        ON suppliers.id = products.supplier_id
                        AND suppliers.organization_id = products.organization_id
                    JOIN raw_materials materials
                        ON materials.id = products.raw_material_id
                        AND materials.organization_id = products.organization_id
                    JOIN LATERAL (
                        SELECT revisions.raw_material_revision_id
                        FROM supplier_product_revisions revisions
                        WHERE revisions.organization_id = products.organization_id
                            AND revisions.supplier_product_id = products.id
                        ORDER BY revisions.revision_no DESC
                        LIMIT 1
                    ) latest_revision ON true
                    WHERE products.id = NEW.supplier_product_id
                        AND products.organization_id = NEW.organization_id
                        AND products.lifecycle_state = 'ACTIVE'
                        AND suppliers.lifecycle_state = 'ACTIVE'
                        AND materials.lifecycle_state = 'ACTIVE'
                        AND materials.approved_revision_id = latest_revision.raw_material_revision_id
                ) THEN
                    RAISE EXCEPTION 'Offer requires an active supplier, product and approved material revision'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE FUNCTION raw_materials_commercial_event_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                parent_product uuid;
                parent_state varchar(16);
                latest_event integer;
                prior supplier_offer_events%ROWTYPE;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT supplier_product_id, lifecycle_state INTO parent_product, parent_state
                    FROM supplier_offers WHERE id = NEW.offer_id AND organization_id = NEW.organization_id FOR SHARE;
                IF parent_product IS NULL OR parent_state <> 'ACTIVE'
                    OR parent_product IS DISTINCT FROM NEW.supplier_product_id THEN
                    RAISE EXCEPTION 'Offer is not active or product scope differs' USING ERRCODE = '23514';
                END IF;
                SELECT COALESCE(max(event_no), 0) INTO latest_event FROM supplier_offer_events
                    WHERE organization_id = NEW.organization_id AND offer_id = NEW.offer_id;
                IF NEW.event_no <> latest_event + 1 THEN
                    RAISE EXCEPTION 'Offer event number is not the next sequence' USING ERRCODE = '23514';
                END IF;
                IF NEW.event_type = 'WITHDRAWN' THEN
                    SELECT * INTO prior FROM supplier_offer_events
                        WHERE id = NEW.supersedes_event_id AND organization_id = NEW.organization_id FOR SHARE;
                    IF prior.id IS NULL OR prior.offer_id <> NEW.offer_id OR prior.event_type <> 'PRICE_SET'
                        OR NEW.valid_from < prior.valid_from THEN
                        RAISE EXCEPTION 'Withdrawal does not reference an eligible offer event' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NOT EXISTS (
                        SELECT 1 FROM supplier_product_revisions revisions
                        JOIN supplier_products products ON products.id = revisions.supplier_product_id
                            AND products.organization_id = revisions.organization_id
                        JOIN organization_suppliers suppliers ON suppliers.id = products.supplier_id
                            AND suppliers.organization_id = products.organization_id
                        JOIN raw_materials materials ON materials.id = products.raw_material_id
                            AND materials.organization_id = products.organization_id
                        WHERE revisions.id = NEW.supplier_product_revision_id
                            AND revisions.supplier_product_id = NEW.supplier_product_id
                            AND revisions.organization_id = NEW.organization_id
                            AND products.lifecycle_state = 'ACTIVE' AND suppliers.lifecycle_state = 'ACTIVE'
                            AND materials.lifecycle_state = 'ACTIVE'
                            AND materials.approved_revision_id = revisions.raw_material_revision_id
                            AND revisions.revision_no = (
                                SELECT max(latest.revision_no) FROM supplier_product_revisions latest
                                WHERE latest.organization_id = revisions.organization_id
                                    AND latest.supplier_product_id = revisions.supplier_product_id
                            )
                    ) THEN
                        RAISE EXCEPTION 'Offer price requires an active supplier, product and approved material revision'
                            USING ERRCODE = '23514';
                    END IF;
                    IF EXISTS (
                        SELECT 1 FROM supplier_offer_events existing
                        WHERE existing.organization_id = NEW.organization_id
                            AND existing.offer_id = NEW.offer_id
                            AND existing.supplier_product_revision_id = NEW.supplier_product_revision_id
                            AND existing.event_type = 'PRICE_SET'
                            AND existing.currency_code = NEW.currency_code
                            AND daterange(
                                existing.valid_from,
                                LEAST(
                                    existing.valid_until,
                                    (SELECT min(withdrawal.valid_from)
                                        FROM supplier_offer_events withdrawal
                                        WHERE withdrawal.organization_id = existing.organization_id
                                            AND withdrawal.offer_id = existing.offer_id
                                            AND withdrawal.event_type = 'WITHDRAWN'
                                            AND withdrawal.supersedes_event_id = existing.id)
                                ),
                                '[)'
                            )
                                && daterange(NEW.valid_from, NEW.valid_until, '[)')
                            AND numrange(existing.quantity_from, existing.quantity_until, '[)')
                                && numrange(NEW.quantity_from, NEW.quantity_until, '[)')
                    ) THEN
                        RAISE EXCEPTION 'Offer price tiers overlap' USING ERRCODE = '23P01';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER supplier_offer_events_validate
                BEFORE INSERT ON supplier_offer_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_event_validate();

            CREATE FUNCTION raw_materials_commercial_fx_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                previous_rate organization_exchange_rate_events%ROWTYPE;
                overridden_rate organization_exchange_rate_events%ROWTYPE;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT * INTO previous_rate FROM organization_exchange_rate_events
                    WHERE organization_id = NEW.organization_id
                        AND quote_currency_code = NEW.quote_currency_code
                        AND rate_date = NEW.rate_date
                    ORDER BY revision_no DESC LIMIT 1 FOR SHARE;
                IF NEW.revision_no <> COALESCE(previous_rate.revision_no, 0) + 1 THEN
                    RAISE EXCEPTION 'Exchange rate revision is not the next sequence' USING ERRCODE = '23514';
                END IF;
                IF NEW.source_code = 'MANUAL_OVERRIDE' THEN
                    SELECT * INTO overridden_rate FROM organization_exchange_rate_events
                        WHERE id = NEW.override_of_id AND organization_id = NEW.organization_id FOR SHARE;
                    IF overridden_rate.id IS NULL OR overridden_rate.source_code <> 'NBP_TABLE_A'
                        OR overridden_rate.quote_currency_code <> NEW.quote_currency_code
                        OR overridden_rate.rate_date <> NEW.rate_date THEN
                        RAISE EXCEPTION 'Manual override must target the same-date NBP rate'
                            USING ERRCODE = '23514';
                    END IF;
                    NEW.supersedes_id := previous_rate.id;
                ELSE
                    IF NEW.supersedes_id IS NOT NULL
                        AND (previous_rate.id IS NULL OR previous_rate.id <> NEW.supersedes_id) THEN
                        RAISE EXCEPTION 'Rate correction does not supersede the latest same-day event'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER organization_exchange_rate_events_validate
                BEFORE INSERT ON organization_exchange_rate_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_fx_validate();

            CREATE FUNCTION raw_materials_commercial_normalization_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                offer_event supplier_offer_events%ROWTYPE;
                product_revision supplier_product_revisions%ROWTYPE;
                rate_event organization_exchange_rate_events%ROWTYPE;
                current_rate organization_exchange_rate_events%ROWTYPE;
                expected_rate numeric;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT * INTO offer_event FROM supplier_offer_events
                    WHERE id = NEW.supplier_offer_event_id AND organization_id = NEW.organization_id FOR SHARE;
                IF offer_event.id IS NULL OR offer_event.event_type <> 'PRICE_SET'
                    OR NEW.operation_date <> offer_event.valid_from THEN
                    RAISE EXCEPTION 'Normalization does not match a price event' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO product_revision FROM supplier_product_revisions
                    WHERE id = offer_event.supplier_product_revision_id AND organization_id = NEW.organization_id;
                IF product_revision.id IS NULL THEN
                    RAISE EXCEPTION 'Product revision for normalization does not exist' USING ERRCODE = '23514';
                END IF;
                IF offer_event.currency_code = 'PLN' THEN
                    IF NEW.exchange_rate_event_id IS NOT NULL OR NEW.rate_snapshot <> 1
                        OR NEW.source_code <> 'PLN_IDENTITY' OR NEW.rate_date IS NOT NULL THEN
                        RAISE EXCEPTION 'PLN normalization must use the identity rate' USING ERRCODE = '23514';
                    END IF;
                    expected_rate := 1;
                ELSE
                    SELECT * INTO rate_event FROM organization_exchange_rate_events
                        WHERE id = NEW.exchange_rate_event_id AND organization_id = NEW.organization_id FOR SHARE;
                    SELECT * INTO current_rate FROM organization_exchange_rate_events
                        WHERE organization_id = NEW.organization_id
                            AND quote_currency_code = offer_event.currency_code
                            AND rate_date <= NEW.operation_date
                        ORDER BY rate_date DESC,
                            CASE WHEN source_code = 'MANUAL_OVERRIDE' THEN 1 ELSE 0 END DESC,
                            revision_no DESC
                        LIMIT 1 FOR SHARE;
                    IF rate_event.id IS NULL OR rate_event.quote_currency_code <> offer_event.currency_code
                        OR rate_event.rate_date > NEW.operation_date
                        OR rate_event.rate_date <> NEW.rate_date
                        OR rate_event.source_code <> NEW.source_code
                        OR rate_event.pln_per_quote_unit <> NEW.rate_snapshot
                        OR current_rate.id IS DISTINCT FROM rate_event.id THEN
                        RAISE EXCEPTION 'Normalization exchange rate snapshot is invalid' USING ERRCODE = '23514';
                    END IF;
                    expected_rate := rate_event.pln_per_quote_unit;
                END IF;
                IF NEW.reporting_amount_pln_per_purchase_unit <>
                        raw_materials_round_half_even_v1(offer_event.net_amount_per_purchase_unit * expected_rate, 14)
                    OR NEW.reporting_amount_pln_per_base_unit <>
                        raw_materials_round_half_even_v1(
                            offer_event.net_amount_per_purchase_unit * expected_rate / product_revision.base_quantity_snapshot, 14
                        ) THEN
                    RAISE EXCEPTION 'Normalization does not match exact decimal calculation' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER supplier_offer_normalization_validate
                BEFORE INSERT ON supplier_offer_fx_normalization_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_normalization_validate();

            CREATE FUNCTION raw_materials_commercial_reference_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                next_event integer;
                price_event supplier_offer_events%ROWTYPE;
                product_revision supplier_product_revisions%ROWTYPE;
                rate_event organization_exchange_rate_events%ROWTYPE;
                current_rate organization_exchange_rate_events%ROWTYPE;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT COALESCE(max(event_no), 0) INTO next_event FROM raw_material_reference_price_events
                    WHERE organization_id = NEW.organization_id AND raw_material_id = NEW.raw_material_id;
                IF NEW.event_no <> next_event + 1 THEN
                    RAISE EXCEPTION 'Reference price event number is not the next sequence' USING ERRCODE = '23514';
                END IF;
                IF NEW.event_type = 'PRICE_SET' THEN
                    IF NOT EXISTS (SELECT 1 FROM raw_materials WHERE id = NEW.raw_material_id
                        AND organization_id = NEW.organization_id AND lifecycle_state = 'ACTIVE'
                        AND approved_revision_id IS NOT NULL) THEN
                        RAISE EXCEPTION 'Reference price requires active approved material' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.source_offer_event_id IS NOT NULL THEN
                        SELECT * INTO price_event FROM supplier_offer_events
                            WHERE id = NEW.source_offer_event_id AND organization_id = NEW.organization_id;
                        SELECT * INTO product_revision FROM supplier_product_revisions
                            WHERE id = price_event.supplier_product_revision_id AND organization_id = NEW.organization_id;
                        IF price_event.id IS NULL OR price_event.event_type <> 'PRICE_SET'
                            OR price_event.valid_from > NEW.effective_from
                            OR (price_event.valid_until IS NOT NULL AND price_event.valid_until <= NEW.effective_from)
                            OR EXISTS (SELECT 1 FROM supplier_offer_events withdrawal
                                WHERE withdrawal.organization_id = NEW.organization_id
                                    AND withdrawal.event_type = 'WITHDRAWN'
                                    AND withdrawal.supersedes_event_id = price_event.id
                                    AND withdrawal.valid_from <= NEW.effective_from)
                            OR product_revision.raw_material_id <> NEW.raw_material_id
                            OR NEW.source_product_revision_id <> product_revision.id
                            OR NEW.source_currency_code <> price_event.currency_code
                            OR NEW.source_net_amount <> price_event.net_amount_per_purchase_unit
                            OR NOT EXISTS (
                                SELECT 1
                                FROM supplier_offers offers
                                JOIN supplier_products products
                                    ON products.id = offers.supplier_product_id
                                    AND products.organization_id = offers.organization_id
                                JOIN organization_suppliers suppliers
                                    ON suppliers.id = products.supplier_id
                                    AND suppliers.organization_id = products.organization_id
                                JOIN raw_materials materials
                                    ON materials.id = products.raw_material_id
                                    AND materials.organization_id = products.organization_id
                                JOIN supplier_product_revisions latest_revision
                                    ON latest_revision.supplier_product_id = products.id
                                    AND latest_revision.organization_id = products.organization_id
                                WHERE offers.id = price_event.offer_id
                                    AND offers.organization_id = NEW.organization_id
                                    AND offers.lifecycle_state = 'ACTIVE'
                                    AND products.lifecycle_state = 'ACTIVE'
                                    AND suppliers.lifecycle_state = 'ACTIVE'
                                    AND materials.lifecycle_state = 'ACTIVE'
                                    AND materials.approved_revision_id = product_revision.raw_material_revision_id
                                    AND latest_revision.id = product_revision.id
                                    AND product_revision.supplier_product_id = products.id
                                    AND product_revision.raw_material_id = NEW.raw_material_id
                                    AND latest_revision.revision_no = (
                                        SELECT max(current_revision.revision_no)
                                        FROM supplier_product_revisions current_revision
                                        WHERE current_revision.organization_id = products.organization_id
                                            AND current_revision.supplier_product_id = products.id
                                    )
                            ) THEN
                            RAISE EXCEPTION 'Reference price source offer does not match its material'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    IF NEW.source_currency_code = 'PLN' AND
                        NEW.net_pln_per_base_unit <> raw_materials_round_half_even_v1(
                            NEW.source_net_amount / COALESCE(product_revision.base_quantity_snapshot, 1), 14) THEN
                        RAISE EXCEPTION 'Reference price does not match PLN source' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.source_currency_code <> 'PLN' THEN
                        SELECT * INTO rate_event FROM organization_exchange_rate_events
                            WHERE id = NEW.exchange_rate_event_id AND organization_id = NEW.organization_id;
                        SELECT * INTO current_rate FROM organization_exchange_rate_events
                            WHERE organization_id = NEW.organization_id
                                AND quote_currency_code = NEW.source_currency_code
                                AND rate_date <= NEW.operation_date
                            ORDER BY rate_date DESC,
                                CASE WHEN source_code = 'MANUAL_OVERRIDE' THEN 1 ELSE 0 END DESC,
                                revision_no DESC
                            LIMIT 1;
                        IF rate_event.id IS NULL OR rate_event.quote_currency_code <> NEW.source_currency_code
                            OR rate_event.rate_date > NEW.operation_date
                            OR rate_event.rate_date <> NEW.rate_date
                            OR rate_event.pln_per_quote_unit <> NEW.rate_snapshot
                            OR current_rate.id IS DISTINCT FROM rate_event.id
                            OR (NEW.source_offer_event_id IS NOT NULL AND
                                NEW.net_pln_per_base_unit <> raw_materials_round_half_even_v1(
                                    NEW.source_net_amount * rate_event.pln_per_quote_unit
                                        / product_revision.base_quantity_snapshot, 14)) THEN
                            RAISE EXCEPTION 'Reference price exchange-rate snapshot is invalid'
                                USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER raw_material_reference_price_events_validate
                BEFORE INSERT ON raw_material_reference_price_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_reference_validate();

            CREATE FUNCTION raw_materials_commercial_restatement_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE original_event raw_material_reference_price_events%ROWTYPE;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT * INTO original_event FROM raw_material_reference_price_events
                    WHERE id = NEW.original_reference_price_event_id
                        AND organization_id = NEW.organization_id
                        AND raw_material_id = NEW.raw_material_id FOR SHARE;
                IF original_event.id IS NULL OR original_event.event_type <> 'PRICE_SET'
                    OR original_event.effective_from <> NEW.operation_date THEN
                    RAISE EXCEPTION 'Restatement must match an original price event' USING ERRCODE = '23514';
                END IF;
                NEW.original_snapshot_hash := encode(sha256(convert_to(concat_ws('|', original_event.id,
                    original_event.net_pln_per_base_unit, original_event.source_net_amount,
                    original_event.source_currency_code, original_event.exchange_rate_event_id,
                    original_event.rate_snapshot), 'UTF8')), 'hex');
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER raw_material_financial_restatements_validate
                BEFORE INSERT ON raw_material_financial_restatements
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_restatement_validate();

            CREATE FUNCTION raw_materials_commercial_product_preserve() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Supplier products cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                    OR NEW.raw_material_id IS DISTINCT FROM OLD.raw_material_id
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state <> 'ARCHIVED') THEN
                    RAISE EXCEPTION 'Supplier product identity or archive history is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE FUNCTION raw_materials_commercial_supplier_preserve() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Suppliers cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.supplier_code IS DISTINCT FROM OLD.supplier_code
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state <> 'ARCHIVED') THEN
                    RAISE EXCEPTION 'Supplier identity or archive history is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE FUNCTION raw_materials_commercial_offer_preserve() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Supplier offers cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.supplier_product_id IS DISTINCT FROM OLD.supplier_product_id
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.lifecycle_state = 'WITHDRAWN' AND NEW.lifecycle_state <> 'WITHDRAWN') THEN
                    RAISE EXCEPTION 'Supplier offer identity or withdrawal history is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER organization_suppliers_preserve
                BEFORE UPDATE OR DELETE ON organization_suppliers
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_supplier_preserve();
            CREATE TRIGGER supplier_products_preserve
                BEFORE UPDATE OR DELETE ON supplier_products
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_product_preserve();
            CREATE TRIGGER supplier_offers_preserve
                BEFORE UPDATE OR DELETE ON supplier_offers
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_offer_preserve();

            CREATE TRIGGER organization_suppliers_serialize
                BEFORE INSERT OR UPDATE OR DELETE ON organization_suppliers
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_serialize();
            CREATE TRIGGER supplier_products_serialize
                BEFORE INSERT OR UPDATE OR DELETE ON supplier_products
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_serialize();
            CREATE TRIGGER supplier_product_revisions_append_only
                BEFORE UPDATE OR DELETE ON supplier_product_revisions
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER supplier_offers_serialize
                BEFORE INSERT OR UPDATE OR DELETE ON supplier_offers
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_serialize();
            CREATE TRIGGER supplier_offers_validate
                BEFORE INSERT ON supplier_offers
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_offer_validate();
            CREATE TRIGGER supplier_offer_events_append_only
                BEFORE UPDATE OR DELETE ON supplier_offer_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER organization_exchange_rate_events_append_only
                BEFORE UPDATE OR DELETE ON organization_exchange_rate_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER supplier_offer_normalization_append_only
                BEFORE UPDATE OR DELETE ON supplier_offer_fx_normalization_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER raw_material_reference_price_events_append_only
                BEFORE UPDATE OR DELETE ON raw_material_reference_price_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER raw_material_financial_restatements_append_only
                BEFORE UPDATE OR DELETE ON raw_material_financial_restatements
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER raw_materials_commercial_audit_append_only
                BEFORE UPDATE OR DELETE ON raw_materials_commercial_audit_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            CREATE TRIGGER raw_materials_commercial_idempotency_append_only
                BEFORE UPDATE OR DELETE ON raw_materials_commercial_idempotency
                FOR EACH ROW EXECUTE FUNCTION raw_materials_commercial_append_only();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS raw_materials_commercial_idempotency_append_only ON raw_materials_commercial_idempotency;
            DROP TRIGGER IF EXISTS raw_materials_commercial_audit_append_only ON raw_materials_commercial_audit_events;
            DROP TRIGGER IF EXISTS raw_material_financial_restatements_append_only ON raw_material_financial_restatements;
            DROP TRIGGER IF EXISTS raw_material_reference_price_events_append_only ON raw_material_reference_price_events;
            DROP TRIGGER IF EXISTS supplier_offer_normalization_append_only ON supplier_offer_fx_normalization_events;
            DROP TRIGGER IF EXISTS organization_exchange_rate_events_append_only ON organization_exchange_rate_events;
            DROP TRIGGER IF EXISTS supplier_offer_events_append_only ON supplier_offer_events;
            DROP TRIGGER IF EXISTS supplier_product_revisions_append_only ON supplier_product_revisions;
            DROP TRIGGER IF EXISTS supplier_offers_serialize ON supplier_offers;
            DROP TRIGGER IF EXISTS supplier_offers_validate ON supplier_offers;
            DROP TRIGGER IF EXISTS supplier_products_serialize ON supplier_products;
            DROP TRIGGER IF EXISTS organization_suppliers_serialize ON organization_suppliers;
            DROP TRIGGER IF EXISTS supplier_offers_preserve ON supplier_offers;
            DROP TRIGGER IF EXISTS supplier_products_preserve ON supplier_products;
            DROP TRIGGER IF EXISTS organization_suppliers_preserve ON organization_suppliers;
            DROP TRIGGER IF EXISTS raw_material_financial_restatements_validate ON raw_material_financial_restatements;
            DROP TRIGGER IF EXISTS raw_material_reference_price_events_validate ON raw_material_reference_price_events;
            DROP TRIGGER IF EXISTS supplier_offer_normalization_validate ON supplier_offer_fx_normalization_events;
            DROP TRIGGER IF EXISTS organization_exchange_rate_events_validate ON organization_exchange_rate_events;
            DROP TRIGGER IF EXISTS supplier_offer_events_validate ON supplier_offer_events;
            DROP TRIGGER IF EXISTS supplier_product_revisions_validate ON supplier_product_revisions;
            DROP TRIGGER IF EXISTS supplier_product_revisions_sku_unique ON supplier_product_revisions;
            DROP FUNCTION IF EXISTS raw_materials_commercial_offer_preserve();
            DROP FUNCTION IF EXISTS raw_materials_commercial_supplier_preserve();
            DROP FUNCTION IF EXISTS raw_materials_commercial_product_preserve();
            DROP FUNCTION IF EXISTS raw_materials_commercial_restatement_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_reference_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_normalization_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_fx_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_event_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_offer_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_product_revision_validate();
            DROP FUNCTION IF EXISTS raw_materials_commercial_sku_unique();
            DROP FUNCTION IF EXISTS raw_materials_commercial_append_only();
            DROP FUNCTION IF EXISTS raw_materials_commercial_serialize();
            DROP TABLE IF EXISTS raw_materials_commercial_audit_events;
            DROP TABLE IF EXISTS raw_materials_commercial_idempotency;
            DROP TABLE IF EXISTS raw_material_financial_restatements;
            DROP TABLE IF EXISTS raw_material_reference_price_events;
            DROP TABLE IF EXISTS supplier_offer_fx_normalization_events;
            DROP TABLE IF EXISTS supplier_offer_events;
            DROP TABLE IF EXISTS organization_exchange_rate_events;
            DROP TABLE IF EXISTS supplier_offers;
            DROP TABLE IF EXISTS supplier_product_revisions;
            DROP TABLE IF EXISTS supplier_products;
            DROP TABLE IF EXISTS organization_suppliers;
            DROP TABLE IF EXISTS raw_materials_currency_codes;
            ALTER TABLE raw_material_revision_units
                DROP CONSTRAINT IF EXISTS raw_material_revision_units_composite_unique;
            DROP FUNCTION IF EXISTS raw_materials_round_half_even_v1(numeric, integer);
            ALTER TABLE organization_permission_grants
                DROP CONSTRAINT organization_permission_grants_permission_check;
            ALTER TABLE organization_permission_grants
                ADD CONSTRAINT organization_permission_grants_permission_check CHECK (permission IN (
                    'RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE', 'RAW_MATERIALS_EDIT',
                    'RAW_MATERIALS_SUBMIT', 'RAW_MATERIALS_REVIEW', 'RAW_MATERIALS_APPROVE',
                    'RAW_MATERIALS_BLOCK', 'RAW_MATERIALS_UNBLOCK', 'RAW_MATERIALS_ARCHIVE',
                    'RAW_MATERIALS_RESTORE', 'RAW_MATERIALS_CATEGORY_MANAGE',
                    'RAW_MATERIALS_AUDIT_VIEW', 'RAW_MATERIALS_PERMISSION_DELEGATE'
                ));
            SQL);
    }
};
