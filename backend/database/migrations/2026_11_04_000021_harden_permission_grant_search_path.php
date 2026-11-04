<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runtimeUsername = (string) env('APP_DB_USERNAME', 'veltra_app');
        if ($runtimeUsername === '') {
            throw new RuntimeException('Nie skonfigurowano roli runtime PostgreSQL.');
        }

        $quotedRuntimeRole = DB::selectOne(
            'SELECT quote_ident(?) AS role_name',
            [$runtimeUsername],
        )->role_name;

        DB::transaction(function () use ($quotedRuntimeRole, $runtimeUsername): void {
            DB::unprepared(<<<'SQL'
                LOCK TABLE public.organization_permission_grants IN ACCESS EXCLUSIVE MODE;

                ALTER FUNCTION public.raw_materials_serialize_grant()
                    SET search_path = pg_catalog, public, pg_temp;
                ALTER FUNCTION public.raw_materials_permission_upgrade_authority_is_effective(uuid, uuid)
                    SET search_path = pg_catalog, public, pg_temp;
                ALTER FUNCTION public.raw_materials_revoke_grants_on_suspension()
                    SET search_path = pg_catalog, public, pg_temp;
                ALTER FUNCTION public.raw_materials_reject_bootstrap_grant_replay()
                    SET search_path = pg_catalog, public, pg_temp;

                DO $$
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM public.organization_permission_grants
                        WHERE bootstrap_operation_id IS NOT NULL
                        GROUP BY organization_id, bootstrap_operation_id, permission
                        HAVING count(*) > 1
                    ) THEN
                        RAISE EXCEPTION
                            'Duplicate historical bootstrap grants require manual review'
                            USING ERRCODE = '23514';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM public.raw_material_permission_grant_operation_uses ledger
                        LEFT JOIN public.organization_permission_grants grants
                            ON grants.organization_id = ledger.organization_id
                            AND grants.bootstrap_operation_id = ledger.bootstrap_operation_id
                            AND grants.permission = ledger.permission
                        WHERE grants.id IS NULL
                    ) THEN
                        RAISE EXCEPTION
                            'Orphan permission operation ledger entries require manual review'
                            USING ERRCODE = '23514';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM public.organization_permission_grants grants
                        WHERE grants.bootstrap_operation_id IS NOT NULL
                            AND NOT EXISTS (
                                SELECT 1
                                FROM public.raw_material_audit_events audit
                                WHERE audit.organization_id = grants.organization_id
                                    AND audit.operation_id = grants.bootstrap_operation_id
                                    AND audit.actor_user_id = grants.granted_by
                                    AND audit.outcome = 'ACCEPTED'
                                    AND (
                                        (
                                            audit.event_code = 'PERMISSION_BOOTSTRAP'
                                            AND audit.metadata->>'recipient_user_id' = grants.user_id::text
                                            AND audit.metadata->'permissions' @> to_jsonb(grants.permission)
                                        )
                                        OR (
                                            audit.event_code = 'PERMISSION_UPGRADE'
                                            AND audit.metadata->>'recipient_user_id' = grants.user_id::text
                                            AND audit.metadata->'permissions' @> to_jsonb(grants.permission)
                                            AND EXISTS (
                                                SELECT 1
                                                FROM public.raw_material_permission_upgrade_operations operations
                                                WHERE operations.id = grants.bootstrap_operation_id
                                                    AND operations.organization_id = grants.organization_id
                                                    AND operations.actor_user_id = grants.granted_by
                                                    AND operations.recipient_user_id = grants.user_id
                                                    AND operations.permissions @> to_jsonb(grants.permission)
                                                    AND audit.metadata->>'authority_grant_id'
                                                        = operations.authority_grant_id::text
                                            )
                                        )
                                    )
                            )
                    ) THEN
                        RAISE EXCEPTION
                            'Permission grants without matching accepted audit history require manual review'
                            USING ERRCODE = '23514';
                    END IF;
                END;
                $$;

                INSERT INTO public.raw_material_permission_grant_operation_uses (
                    id, organization_id, bootstrap_operation_id, permission, created_at
                )
                SELECT gen_random_uuid(), grants.organization_id,
                    grants.bootstrap_operation_id, grants.permission, min(grants.granted_at)
                FROM public.organization_permission_grants grants
                WHERE grants.bootstrap_operation_id IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1
                        FROM public.raw_material_permission_grant_operation_uses ledger
                        WHERE ledger.organization_id = grants.organization_id
                            AND ledger.bootstrap_operation_id = grants.bootstrap_operation_id
                            AND ledger.permission = grants.permission
                    )
                GROUP BY grants.organization_id, grants.bootstrap_operation_id, grants.permission;

                REVOKE ALL ON TABLE public.raw_material_permission_grant_operation_uses FROM PUBLIC;
                REVOKE ALL ON FUNCTION public.raw_materials_reject_bootstrap_grant_replay() FROM PUBLIC;
                SQL);

            DB::unprepared(sprintf(
                'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE public.raw_material_permission_grant_operation_uses FROM %s',
                $quotedRuntimeRole,
            ));

            DB::unprepared(sprintf(
                'REVOKE ALL ON FUNCTION public.raw_materials_reject_bootstrap_grant_replay() FROM %s',
                $quotedRuntimeRole,
            ));

            DB::unprepared(<<<'SQL'
                DO $$
                DECLARE
                    grantee_name name;
                BEGIN
                    FOR grantee_name IN
                        SELECT DISTINCT grantee.rolname
                        FROM pg_proc functions
                        CROSS JOIN LATERAL aclexplode(
                            COALESCE(functions.proacl, acldefault('f', functions.proowner))
                        ) privileges
                        JOIN pg_roles grantee ON grantee.oid = privileges.grantee
                        WHERE functions.oid =
                            'public.raw_materials_reject_bootstrap_grant_replay()'::regprocedure
                            AND privileges.grantee <> functions.proowner
                            AND privileges.privilege_type = 'EXECUTE'
                    LOOP
                        EXECUTE format(
                            'REVOKE ALL ON FUNCTION public.raw_materials_reject_bootstrap_grant_replay() FROM %I',
                            grantee_name
                        );
                    END LOOP;

                    IF EXISTS (
                        SELECT 1
                        FROM pg_roles roles
                        WHERE roles.rolcanlogin
                            AND NOT roles.rolsuper
                            AND roles.oid <> (
                                SELECT proowner FROM pg_proc
                                WHERE oid = 'public.raw_materials_reject_bootstrap_grant_replay()'::regprocedure
                            )
                            AND has_function_privilege(
                                roles.oid,
                                'public.raw_materials_reject_bootstrap_grant_replay()'::regprocedure,
                                'EXECUTE'
                            )
                    ) THEN
                        RAISE EXCEPTION
                            'Non-owner login roles retain EXECUTE on the bootstrap replay guard'
                            USING ERRCODE = '42501';
                    END IF;
                END;
                $$;
                SQL);

            $ledgerPrivileges = DB::selectOne(
                <<<'SQL'
                    SELECT has_table_privilege(?, 'public.raw_material_permission_grant_operation_uses', 'INSERT') AS can_insert,
                        has_table_privilege(?, 'public.raw_material_permission_grant_operation_uses', 'UPDATE') AS can_update,
                        has_table_privilege(?, 'public.raw_material_permission_grant_operation_uses', 'DELETE') AS can_delete,
                        has_table_privilege(?, 'public.raw_material_permission_grant_operation_uses', 'TRUNCATE') AS can_truncate
                    SQL,
                [$runtimeUsername, $runtimeUsername, $runtimeUsername, $runtimeUsername],
            );
            if ($ledgerPrivileges->can_insert || $ledgerPrivileges->can_update
                || $ledgerPrivileges->can_delete || $ledgerPrivileges->can_truncate) {
                throw new RuntimeException('Rola runtime nadal dziedziczy prawo zapisu do ledgeru uprawnień.');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można przywrócić podatnego wyszukiwania relacji ani niekompletnego ledgeru uprawnień.');
    }
};
