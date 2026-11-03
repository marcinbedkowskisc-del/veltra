<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD COLUMN auth_version bigint NOT NULL DEFAULT 1 CHECK (auth_version > 0);
            CREATE TABLE activation_tokens (email varchar(254) PRIMARY KEY, token varchar(255) NOT NULL, created_at timestamp);
            CREATE TABLE password_reset_tokens (email varchar(254) PRIMARY KEY, token varchar(255) NOT NULL, created_at timestamp);
            CREATE TABLE account_email_deliveries (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                kind varchar(20) NOT NULL CHECK (kind IN ('activation','reset')),
                ip_hash varchar(64) NOT NULL,
                sent_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE INDEX account_email_user_time_idx ON account_email_deliveries(user_id, sent_at);
            CREATE INDEX account_email_ip_time_idx ON account_email_deliveries(ip_hash, sent_at);
            CREATE TRIGGER email_delivery_history BEFORE UPDATE OR DELETE ON account_email_deliveries
                FOR EACH ROW EXECUTE FUNCTION preserve_security_event();
            CREATE OR REPLACE FUNCTION invalidate_changed_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.email IS DISTINCT FROM OLD.email THEN
                    NEW.email_verified_at := NULL;
                END IF;
                IF NEW.email IS DISTINCT FROM OLD.email OR NEW.password IS DISTINCT FROM OLD.password
                    OR (OLD.is_active AND NOT NEW.is_active) THEN
                    NEW.auth_version := OLD.auth_version + 1;
                    DELETE FROM activation_tokens WHERE email IN (OLD.email, NEW.email);
                    DELETE FROM password_reset_tokens WHERE email IN (OLD.email, NEW.email);
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER users_invalidate_sessions BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION invalidate_changed_identity();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER users_invalidate_sessions ON users;
            DROP FUNCTION invalidate_changed_identity();
            DROP TABLE account_email_deliveries;
            DROP TABLE password_reset_tokens;
            DROP TABLE activation_tokens;
            ALTER TABLE users DROP COLUMN auth_version;
            SQL);
    }
};
