<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                first_name varchar(100) NOT NULL CHECK (btrim(first_name) <> ''),
                last_name varchar(100) NOT NULL CHECK (btrim(last_name) <> ''),
                email varchar(254) NOT NULL CHECK (email = lower(btrim(email)) AND email <> ''),
                password varchar(255) NOT NULL,
                is_active boolean NOT NULL DEFAULT true,
                is_initial_identity boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (id, organization_id)
            );
            CREATE UNIQUE INDEX users_normalized_email_unique ON users (lower(email));
            CREATE UNIQUE INDEX users_single_initial_identity ON users (is_initial_identity) WHERE is_initial_identity;

            CREATE TABLE location_memberships (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                location_id uuid NOT NULL,
                is_active boolean NOT NULL DEFAULT true,
                assigned_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                deactivated_at timestamptz,
                deactivation_reason varchar(250),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                FOREIGN KEY (user_id, organization_id) REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (location_id, organization_id) REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                CHECK ((is_active AND deactivated_at IS NULL AND deactivation_reason IS NULL)
                    OR (NOT is_active AND deactivated_at IS NOT NULL AND deactivated_at >= assigned_at
                        AND deactivation_reason IS NOT NULL AND btrim(deactivation_reason) <> ''))
            );
            CREATE UNIQUE INDEX memberships_unique_active_pair ON location_memberships(user_id, location_id) WHERE is_active;
            CREATE INDEX memberships_location_idx ON location_memberships(location_id);

            CREATE OR REPLACE FUNCTION preserve_user_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'User identities cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.is_initial_identity IS DISTINCT FROM OLD.is_initial_identity THEN
                    RAISE EXCEPTION 'User identity and installation are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER users_preserve_identity BEFORE UPDATE OR DELETE ON users
                FOR EACH ROW EXECUTE FUNCTION preserve_user_identity();

            CREATE OR REPLACE FUNCTION preserve_membership_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Membership history cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NOT OLD.is_active OR NEW.is_active OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.location_id IS DISTINCT FROM OLD.location_id
                    OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Only membership deactivation is allowed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER memberships_preserve_history BEFORE UPDATE OR DELETE ON location_memberships
                FOR EACH ROW EXECUTE FUNCTION preserve_membership_history();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE location_memberships;
            DROP TABLE users;
            DROP FUNCTION preserve_membership_history();
            DROP FUNCTION preserve_user_identity();
            SQL);
    }
};
