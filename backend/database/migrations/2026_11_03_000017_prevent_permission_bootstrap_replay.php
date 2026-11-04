<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE raw_material_permission_grant_operation_uses (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                bootstrap_operation_id uuid NOT NULL,
                permission varchar(80) NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (organization_id, bootstrap_operation_id, permission)
            );

            INSERT INTO raw_material_permission_grant_operation_uses (
                id, organization_id, bootstrap_operation_id, permission, created_at
            )
            SELECT gen_random_uuid(), organization_id, bootstrap_operation_id, permission, min(granted_at)
            FROM organization_permission_grants
            WHERE bootstrap_operation_id IS NOT NULL
            GROUP BY organization_id, bootstrap_operation_id, permission;

            CREATE TRIGGER raw_material_permission_grant_operation_uses_append_only
                BEFORE UPDATE OR DELETE ON raw_material_permission_grant_operation_uses
                FOR EACH ROW EXECUTE FUNCTION raw_materials_append_only();

            CREATE OR REPLACE FUNCTION raw_materials_reject_bootstrap_grant_replay()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                use_id uuid;
            BEGIN
                IF NEW.bootstrap_operation_id IS NULL THEN
                    RETURN NEW;
                END IF;

                INSERT INTO raw_material_permission_grant_operation_uses (
                    id, organization_id, bootstrap_operation_id, permission, created_at
                ) VALUES (
                    gen_random_uuid(), NEW.organization_id, NEW.bootstrap_operation_id,
                    NEW.permission, clock_timestamp()
                )
                ON CONFLICT (organization_id, bootstrap_operation_id, permission) DO NOTHING
                RETURNING id INTO use_id;

                IF use_id IS NULL THEN
                    RAISE EXCEPTION 'A bootstrap permission operation cannot grant the same permission twice'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER organization_permission_grants_bootstrap_replay_guard
                BEFORE INSERT ON organization_permission_grants
                FOR EACH ROW EXECUTE FUNCTION raw_materials_reject_bootstrap_grant_replay();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można wycofać ochrony przed ponownym użyciem bootstrapu uprawnień.');
    }
};
