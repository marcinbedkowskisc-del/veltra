<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            CREATE TABLE organizations (
                id uuid PRIMARY KEY,
                name varchar(200) NOT NULL CHECK (btrim(name) <> ''),
                code varchar(50) NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9_-]+$'),
                is_active boolean NOT NULL DEFAULT true,
                installation boolean NOT NULL DEFAULT true CHECK (installation),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CONSTRAINT organizations_single_installation_unique UNIQUE (installation)
            );

            CREATE TABLE locations (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                name varchar(200) NOT NULL CHECK (btrim(name) <> ''),
                code varchar(50) NOT NULL CHECK (code ~ '^[A-Z0-9_-]+$'),
                type varchar(30) NOT NULL CHECK (type IN ('CENTRAL_KITCHEN', 'RESTAURANT')),
                is_active boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (organization_id, code),
                UNIQUE (id, organization_id)
            );

            CREATE TABLE location_management_assignments (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                restaurant_id uuid NOT NULL,
                managing_kitchen_id uuid NOT NULL,
                effective_from timestamptz NOT NULL CHECK (isfinite(effective_from)),
                effective_until timestamptz CHECK (isfinite(effective_until) AND effective_until > effective_from),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                FOREIGN KEY (restaurant_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (managing_kitchen_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                CONSTRAINT management_no_overlapping_periods EXCLUDE USING gist (
                    restaurant_id WITH =,
                    tstzrange(effective_from, effective_until, '[)') WITH &&
                )
            );
            CREATE INDEX management_kitchen_idx ON location_management_assignments(managing_kitchen_id);

            CREATE TABLE location_supply_relationships (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                supplier_kitchen_id uuid NOT NULL,
                restaurant_id uuid NOT NULL,
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                FOREIGN KEY (supplier_kitchen_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (restaurant_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT
            );
            CREATE UNIQUE INDEX supply_unique_active_pair
                ON location_supply_relationships(supplier_kitchen_id, restaurant_id) WHERE is_active;
            CREATE INDEX supply_restaurant_idx ON location_supply_relationships(restaurant_id);

            CREATE OR REPLACE FUNCTION serialize_installation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM id FROM organizations WHERE id = OLD.organization_id FOR UPDATE;
                    RETURN OLD;
                END IF;
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                RETURN NEW;
            END $$;
            CREATE TRIGGER locations_serialize BEFORE INSERT OR UPDATE OR DELETE ON locations
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE TRIGGER management_serialize BEFORE INSERT OR UPDATE OR DELETE ON location_management_assignments
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();
            CREATE TRIGGER supply_serialize BEFORE INSERT OR UPDATE OR DELETE ON location_supply_relationships
                FOR EACH ROW EXECUTE FUNCTION serialize_installation_mutation();

            CREATE OR REPLACE FUNCTION validate_location_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.type IS DISTINCT FROM OLD.type OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.id IS DISTINCT FROM OLD.id THEN
                    RAISE EXCEPTION 'Location identity, organization and type are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER locations_immutable_identity BEFORE UPDATE ON locations
                FOR EACH ROW EXECUTE FUNCTION validate_location_identity();

            CREATE OR REPLACE FUNCTION validate_location_relationship() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE kitchen_id uuid;
            BEGIN
                IF TG_TABLE_NAME = 'location_management_assignments' THEN
                    kitchen_id := NEW.managing_kitchen_id;
                ELSE
                    kitchen_id := NEW.supplier_kitchen_id;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM locations WHERE id = NEW.restaurant_id AND type = 'RESTAURANT') THEN
                    RAISE EXCEPTION 'Destination must be a restaurant' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM locations WHERE id = kitchen_id AND type = 'CENTRAL_KITCHEN') THEN
                    RAISE EXCEPTION 'Source must be a central kitchen' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER management_location_types BEFORE INSERT OR UPDATE ON location_management_assignments
                FOR EACH ROW EXECUTE FUNCTION validate_location_relationship();
            CREATE TRIGGER supply_location_types BEFORE INSERT OR UPDATE ON location_supply_relationships
                FOR EACH ROW EXECUTE FUNCTION validate_location_relationship();

            CREATE OR REPLACE FUNCTION preserve_management_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Management history cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.effective_until IS NOT NULL OR NEW.effective_until IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.restaurant_id IS DISTINCT FROM OLD.restaurant_id
                    OR NEW.managing_kitchen_id IS DISTINCT FROM OLD.managing_kitchen_id
                    OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Only closing an open management period is allowed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER management_preserve_history BEFORE UPDATE OR DELETE ON location_management_assignments
                FOR EACH ROW EXECUTE FUNCTION preserve_management_history();

            CREATE OR REPLACE FUNCTION require_operational_management() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM locations restaurant
                    WHERE restaurant.type = 'RESTAURANT' AND restaurant.is_active
                    AND NOT EXISTS (
                        SELECT 1 FROM location_management_assignments assignment
                        JOIN locations kitchen ON kitchen.id = assignment.managing_kitchen_id
                        WHERE assignment.restaurant_id = restaurant.id AND kitchen.is_active
                        AND assignment.effective_from <= statement_timestamp()
                        AND (assignment.effective_until IS NULL OR assignment.effective_until > statement_timestamp())
                    )
                ) THEN
                    RAISE EXCEPTION 'An active restaurant requires a current active managing kitchen' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER locations_operational_management AFTER INSERT OR UPDATE OR DELETE ON locations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_operational_management();
            CREATE CONSTRAINT TRIGGER assignments_operational_management AFTER INSERT OR UPDATE OR DELETE ON location_management_assignments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_operational_management();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE location_supply_relationships;
            DROP TABLE location_management_assignments;
            DROP TABLE locations;
            DROP TABLE organizations;
            DROP FUNCTION require_operational_management();
            DROP FUNCTION preserve_management_history();
            DROP FUNCTION validate_location_relationship();
            DROP FUNCTION validate_location_identity();
            DROP FUNCTION serialize_installation_mutation();
            SQL);
    }
};
