<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raw_materials_permission_upgrade_authority_is_effective(
                organization_key uuid,
                operation_key uuid
            )
            RETURNS boolean
            LANGUAGE sql
            STABLE
            AS $$
                WITH RECURSIVE authority_chain(operation_id, organization_id) AS (
                    SELECT operation.id, operation.organization_id
                    FROM raw_material_permission_upgrade_operations operation
                    WHERE operation.id = operation_key
                        AND operation.organization_id = organization_key
                    UNION
                    SELECT parent_operation.id, parent_operation.organization_id
                    FROM authority_chain child
                    JOIN raw_material_permission_upgrade_operations child_operation
                        ON child_operation.id = child.operation_id
                        AND child_operation.organization_id = child.organization_id
                    JOIN organization_permission_grants authority
                        ON authority.id = child_operation.authority_grant_id
                        AND authority.organization_id = child_operation.organization_id
                    JOIN raw_material_permission_upgrade_operations parent_operation
                        ON parent_operation.id = authority.bootstrap_operation_id
                        AND parent_operation.organization_id = authority.organization_id
                )
                SELECT NOT EXISTS (
                    SELECT 1
                    FROM raw_material_permission_upgrade_operations operation
                    WHERE operation.id = operation_key
                        AND operation.organization_id = organization_key
                ) OR (
                    EXISTS (SELECT 1 FROM authority_chain)
                    AND NOT EXISTS (
                        SELECT 1
                        FROM authority_chain chain
                        JOIN raw_material_permission_upgrade_operations operation
                            ON operation.id = chain.operation_id
                            AND operation.organization_id = chain.organization_id
                        JOIN organization_permission_grants authority
                            ON authority.id = operation.authority_grant_id
                            AND authority.organization_id = operation.organization_id
                        JOIN organizations ON organizations.id = operation.organization_id
                        JOIN users actor ON actor.id = operation.actor_user_id
                            AND actor.organization_id = operation.organization_id
                        WHERE NOT (
                            organizations.is_active
                            AND actor.is_active
                            AND actor.email_verified_at IS NOT NULL
                            AND authority.user_id = actor.id
                            AND authority.permission = 'RAW_MATERIALS_PERMISSION_DELEGATE'
                            AND authority.delegation_source_id IS NULL
                            AND authority.valid_from <= statement_timestamp()
                            AND (authority.valid_until IS NULL OR authority.valid_until > statement_timestamp())
                            AND NOT EXISTS (
                                SELECT 1 FROM organization_permission_grant_events events
                                WHERE events.grant_id = authority.id AND events.event_type = 'REVOKED'
                            )
                            AND EXISTS (
                                SELECT 1 FROM location_memberships memberships
                                JOIN locations ON locations.id = memberships.location_id
                                    AND locations.organization_id = memberships.organization_id
                                WHERE memberships.user_id = actor.id
                                    AND memberships.organization_id = operation.organization_id
                                    AND memberships.is_active AND locations.is_active
                            )
                        )
                    )
                );
            $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można wycofać rekurencyjnego sprawdzania authority grantów.');
    }
};
