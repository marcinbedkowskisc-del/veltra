<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.boss_location_restriction_revisions
                ADD COLUMN previous_categories jsonb NOT NULL DEFAULT '[]'::jsonb
                    CHECK (jsonb_typeof(previous_categories) = 'array'),
                ADD COLUMN excluded_categories jsonb NOT NULL DEFAULT '[]'::jsonb
                    CHECK (jsonb_typeof(excluded_categories) = 'array'),
                ADD COLUMN previous_category_locations jsonb NOT NULL DEFAULT '[]'::jsonb
                    CHECK (jsonb_typeof(previous_category_locations) = 'array'),
                ADD COLUMN excluded_category_locations jsonb NOT NULL DEFAULT '[]'::jsonb
                    CHECK (jsonb_typeof(excluded_category_locations) = 'array'),
                ADD CONSTRAINT boss_restrictions_user_organization_fk
                    FOREIGN KEY (user_id, organization_id) REFERENCES public.users(id, organization_id),
                ADD CONSTRAINT boss_restrictions_actor_organization_fk
                    FOREIGN KEY (actor_id, organization_id) REFERENCES public.users(id, organization_id);
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION veltra_private.validate_boss_visibility_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public, veltra_private, pg_temp
            AS $function$
            DECLARE
                ticket jsonb;
                previous public.boss_location_restriction_revisions%ROWTYPE;
                allowed_categories text[] := ARRAY[
                    'RAW_SUPPLIERS', 'PURCHASE_FINANCIALS', 'FOODCOST', 'PRODUCTS_RECIPES',
                    'PRODUCTION', 'WAREHOUSE_INVENTORY', 'ORDERS', 'REPORTS_ANALYTICS'
                ];
            BEGIN
                ticket := nullif(current_setting('veltra.authorization_payload', true), '')::jsonb;
                IF ticket->>'actor_id' IS DISTINCT FROM NEW.actor_id::text THEN
                    RAISE EXCEPTION 'BOSS restriction actor must match the signed mutation actor' USING ERRCODE = '42501';
                END IF;

                SELECT * INTO previous
                FROM public.boss_location_restriction_revisions
                WHERE organization_id = NEW.organization_id AND user_id = NEW.user_id
                ORDER BY version DESC LIMIT 1;
                IF FOUND THEN
                    IF NEW.version <> previous.version + 1
                        OR NEW.previous_locations IS DISTINCT FROM previous.excluded_locations
                        OR NEW.previous_categories IS DISTINCT FROM previous.excluded_categories
                        OR NEW.previous_category_locations IS DISTINCT FROM previous.excluded_category_locations THEN
                        RAISE EXCEPTION 'BOSS restriction revision must extend the current policy' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.version <> 1
                    OR NEW.previous_locations <> '[]'::jsonb
                    OR NEW.previous_categories <> '[]'::jsonb
                    OR NEW.previous_category_locations <> '[]'::jsonb THEN
                    RAISE EXCEPTION 'Initial BOSS restriction revision must start at version one' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM jsonb_array_elements_text(NEW.excluded_locations) ids
                    WHERE ids.value !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                ) OR jsonb_array_length(NEW.excluded_locations) <>
                    (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(NEW.excluded_locations))
                    OR EXISTS (
                        SELECT 1 FROM jsonb_array_elements_text(NEW.excluded_locations) ids
                        WHERE NOT EXISTS (
                            SELECT 1 FROM public.locations locations
                            WHERE locations.id::text = ids.value AND locations.organization_id = NEW.organization_id
                        )
                    ) THEN
                    RAISE EXCEPTION 'BOSS location restrictions contain invalid or foreign locations' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM jsonb_array_elements_text(NEW.excluded_categories) categories
                    WHERE NOT coalesce(categories.value = ANY(allowed_categories), false)
                ) OR jsonb_array_length(NEW.excluded_categories) <>
                    (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(NEW.excluded_categories)) THEN
                    RAISE EXCEPTION 'BOSS category restrictions contain invalid or duplicate categories' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM jsonb_array_elements(NEW.excluded_category_locations) pairs
                    WHERE jsonb_typeof(pairs.value) <> 'object'
                        OR pairs.value - 'category' - 'location_id' <> '{}'::jsonb
                        OR NOT (pairs.value ? 'category')
                        OR NOT (pairs.value ? 'location_id')
                        OR NOT coalesce(pairs.value->>'category' = ANY(allowed_categories), false)
                        OR coalesce(pairs.value->>'location_id', '') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                        OR NOT EXISTS (
                            SELECT 1 FROM public.locations locations
                            WHERE locations.id::text = pairs.value->>'location_id'
                                AND locations.organization_id = NEW.organization_id
                        )
                ) OR jsonb_array_length(NEW.excluded_category_locations) <>
                    (SELECT count(DISTINCT (value->>'category') || ':' || (value->>'location_id'))
                     FROM jsonb_array_elements(NEW.excluded_category_locations)) THEN
                    RAISE EXCEPTION 'BOSS category-location restrictions contain invalid or duplicate entries' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $function$;
            REVOKE ALL ON FUNCTION veltra_private.validate_boss_visibility_revision() FROM PUBLIC;
            CREATE TRIGGER boss_location_restrictions_validate
                BEFORE INSERT ON public.boss_location_restriction_revisions
                FOR EACH ROW EXECUTE FUNCTION veltra_private.validate_boss_visibility_revision();

            CREATE OR REPLACE FUNCTION veltra_private.prevent_boss_visibility_revision_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog
            AS $function$
            BEGIN
                RAISE EXCEPTION 'BOSS restriction revisions are append only' USING ERRCODE = '23514';
            END
            $function$;
            REVOKE ALL ON FUNCTION veltra_private.prevent_boss_visibility_revision_mutation() FROM PUBLIC;
            CREATE TRIGGER boss_location_restrictions_append_only
                BEFORE UPDATE OR DELETE ON public.boss_location_restriction_revisions
                FOR EACH ROW EXECUTE FUNCTION veltra_private.prevent_boss_visibility_revision_mutation();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('BOSS visibility policy migration is append-only and cannot be rolled back.');
    }
};
