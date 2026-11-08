<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN primary_central_kitchen_unresolved boolean NOT NULL DEFAULT false;

            -- Existing installations have no trusted record of prior kitchen creation or deletion.
            UPDATE organizations
            SET primary_central_kitchen_unresolved = true;

            ALTER TABLE locations
                ADD COLUMN is_primary_central_kitchen boolean NOT NULL DEFAULT false,
                ADD CONSTRAINT locations_primary_central_kitchen_type_check
                    CHECK (NOT is_primary_central_kitchen OR type = 'CENTRAL_KITCHEN');

            CREATE UNIQUE INDEX locations_one_primary_central_kitchen_per_org
                ON locations (organization_id)
                WHERE is_primary_central_kitchen;

            CREATE OR REPLACE FUNCTION assign_primary_central_kitchen_on_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            DECLARE
                identity_unresolved boolean;
                kitchen_exists boolean;
            BEGIN
                NEW.is_primary_central_kitchen := false;
                IF NEW.type <> 'CENTRAL_KITCHEN' THEN
                    RETURN NEW;
                END IF;

                SELECT organization.primary_central_kitchen_unresolved
                INTO identity_unresolved
                FROM organizations organization
                WHERE organization.id = NEW.organization_id
                FOR UPDATE;

                IF NOT FOUND OR identity_unresolved THEN
                    RETURN NEW;
                END IF;

                SELECT EXISTS (
                    SELECT 1
                    FROM locations kitchen
                    WHERE kitchen.organization_id = NEW.organization_id
                        AND kitchen.type = 'CENTRAL_KITCHEN'
                ) INTO kitchen_exists;

                NEW.is_primary_central_kitchen := NOT kitchen_exists;

                RETURN NEW;
            END
            $function$;

            CREATE TRIGGER locations_assign_primary_central_kitchen
                BEFORE INSERT ON locations
                FOR EACH ROW EXECUTE FUNCTION assign_primary_central_kitchen_on_insert();

            CREATE OR REPLACE FUNCTION preserve_primary_central_kitchen_identity()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.is_primary_central_kitchen THEN
                        RAISE EXCEPTION 'Primary central kitchen identity is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.is_primary_central_kitchen IS DISTINCT FROM OLD.is_primary_central_kitchen THEN
                    RAISE EXCEPTION 'Primary central kitchen identity is immutable'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $function$;

            CREATE TRIGGER locations_primary_central_kitchen_immutable
                BEFORE UPDATE OR DELETE ON locations
                FOR EACH ROW EXECUTE FUNCTION preserve_primary_central_kitchen_identity();

            CREATE OR REPLACE FUNCTION preserve_primary_central_kitchen_resolution()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public
            AS $function$
            BEGIN
                IF NEW.primary_central_kitchen_unresolved
                    IS DISTINCT FROM OLD.primary_central_kitchen_unresolved THEN
                    RAISE EXCEPTION 'Primary central kitchen resolution state is immutable'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $function$;

            CREATE TRIGGER organizations_primary_central_kitchen_resolution_immutable
                BEFORE UPDATE OF primary_central_kitchen_unresolved ON organizations
                FOR EACH ROW EXECUTE FUNCTION preserve_primary_central_kitchen_resolution();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja 038 jest nieodwracalna; powrót wymaga osobnej, przetestowanej migracji do przodu.');
    }
};
