<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runtimeRole = (string) env('APP_DB_USERNAME', 'veltra_app');
        if ($runtimeRole === '') {
            throw new RuntimeException('Nie skonfigurowano roli runtime PostgreSQL.');
        }

        $quotedRuntimeRole = DB::selectOne('SELECT quote_ident(?) AS role_name', [$runtimeRole])->role_name;

        DB::transaction(function () use ($quotedRuntimeRole, $runtimeRole): void {
            DB::unprepared(<<<'SQL'
                LOCK TABLE public.organization_permission_grants IN ACCESS EXCLUSIVE MODE;

                CREATE TABLE public.system_permission_manifests (
                    manifest_id varchar(80) NOT NULL CHECK (manifest_id ~ '^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$'),
                    version integer NOT NULL CHECK (version > 0),
                    module_key varchar(80) NOT NULL CHECK (module_key ~ '^[A-Z][A-Z0-9_]{0,79}$'),
                    PRIMARY KEY (manifest_id, version)
                );

                CREATE TABLE public.system_permission_manifest_permissions (
                    manifest_id varchar(80) NOT NULL,
                    manifest_version integer NOT NULL,
                    permission varchar(80) NOT NULL CHECK (permission ~ '^[A-Z][A-Z0-9_]{0,79}$'),
                    PRIMARY KEY (manifest_id, manifest_version, permission),
                    FOREIGN KEY (manifest_id, manifest_version)
                        REFERENCES public.system_permission_manifests(manifest_id, version) ON DELETE RESTRICT
                );

                CREATE TABLE public.system_permission_provisioning_operations (
                    id uuid PRIMARY KEY,
                    organization_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
                    recipient_user_id uuid NOT NULL,
                    location_id uuid NOT NULL,
                    manifest_id varchar(80) NOT NULL,
                    manifest_version integer NOT NULL,
                    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                    FOREIGN KEY (recipient_user_id, organization_id)
                        REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (location_id, organization_id)
                        REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT,
                    FOREIGN KEY (manifest_id, manifest_version)
                        REFERENCES public.system_permission_manifests(manifest_id, version) ON DELETE RESTRICT,
                    UNIQUE (id, organization_id, recipient_user_id, manifest_id, manifest_version),
                    UNIQUE (organization_id, recipient_user_id, manifest_id, manifest_version)
                );

                CREATE FUNCTION veltra_private.lock_system_permission_manifest()
                RETURNS trigger LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $function$
                BEGIN
                    PERFORM 1 FROM public.system_permission_manifests manifest
                    WHERE manifest.manifest_id = NEW.manifest_id AND manifest.version = NEW.manifest_version
                    FOR UPDATE;
                    IF TG_TABLE_NAME = 'system_permission_manifest_permissions'
                        AND EXISTS (
                            SELECT 1 FROM public.system_permission_provisioning_operations operation
                            WHERE operation.manifest_id = NEW.manifest_id
                                AND operation.manifest_version = NEW.manifest_version
                        ) THEN
                        RAISE EXCEPTION 'A used system permission manifest version cannot be extended'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END
                $function$;
                REVOKE ALL ON FUNCTION veltra_private.lock_system_permission_manifest() FROM PUBLIC;
                CREATE TRIGGER system_permission_manifest_permissions_lock
                    BEFORE INSERT ON public.system_permission_manifest_permissions
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.lock_system_permission_manifest();
                CREATE TRIGGER system_permission_provisioning_operations_manifest_lock
                    BEFORE INSERT ON public.system_permission_provisioning_operations
                    FOR EACH ROW EXECUTE FUNCTION veltra_private.lock_system_permission_manifest();

                CREATE TRIGGER system_permission_manifests_append_only
                    BEFORE UPDATE OR DELETE ON public.system_permission_manifests
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_append_only();
                CREATE TRIGGER system_permission_manifest_permissions_append_only
                    BEFORE UPDATE OR DELETE ON public.system_permission_manifest_permissions
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_append_only();
                CREATE TRIGGER system_permission_provisioning_operations_append_only
                    BEFORE UPDATE OR DELETE ON public.system_permission_provisioning_operations
                    FOR EACH ROW EXECUTE FUNCTION public.raw_materials_append_only();

                ALTER TABLE public.organization_permission_grants
                    ALTER COLUMN granted_by DROP NOT NULL,
                    ADD COLUMN grant_origin varchar(24) NOT NULL DEFAULT 'USER_DELEGATION',
                    ADD COLUMN system_provisioning_operation_id uuid,
                    ADD COLUMN system_manifest_id varchar(80),
                    ADD COLUMN system_manifest_version integer,
                    DROP CONSTRAINT organization_permission_grants_check2,
                    ADD CONSTRAINT organization_permission_grants_origin_check CHECK (
                        (
                            grant_origin = 'USER_DELEGATION'
                            AND granted_by IS NOT NULL AND granted_by <> user_id
                            AND system_provisioning_operation_id IS NULL
                            AND system_manifest_id IS NULL AND system_manifest_version IS NULL
                            AND ((delegation_source_id IS NULL) = (bootstrap_operation_id IS NOT NULL))
                        )
                        OR (
                            grant_origin = 'SYSTEM_PROVISIONING'
                            AND granted_by IS NULL
                            AND delegation_source_id IS NULL AND bootstrap_operation_id IS NULL
                            AND system_provisioning_operation_id IS NOT NULL
                            AND system_manifest_id IS NOT NULL AND system_manifest_version IS NOT NULL
                        )
                    ),
                    ADD CONSTRAINT organization_permission_grants_system_operation_fkey
                        FOREIGN KEY (
                            system_provisioning_operation_id, organization_id, user_id,
                            system_manifest_id, system_manifest_version
                        ) REFERENCES public.system_permission_provisioning_operations (
                            id, organization_id, recipient_user_id, manifest_id, manifest_version
                        ) ON DELETE RESTRICT,
                    ADD CONSTRAINT organization_permission_grants_system_permission_fkey
                        FOREIGN KEY (system_manifest_id, system_manifest_version, permission)
                        REFERENCES public.system_permission_manifest_permissions (
                            manifest_id, manifest_version, permission
                        ) ON DELETE RESTRICT;

                CREATE UNIQUE INDEX organization_permission_grants_system_operation_permission_unique
                    ON public.organization_permission_grants(system_provisioning_operation_id, permission)
                    WHERE grant_origin = 'SYSTEM_PROVISIONING';

                DROP TRIGGER organization_permission_grants_validate ON public.organization_permission_grants;
                CREATE TRIGGER organization_permission_grants_validate
                    BEFORE INSERT ON public.organization_permission_grants
                    FOR EACH ROW WHEN (NEW.grant_origin = 'USER_DELEGATION')
                    EXECUTE FUNCTION public.raw_materials_serialize_grant();

                CREATE FUNCTION veltra_private.validate_system_permission_grant()
                RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER
                SET search_path = pg_catalog, public, veltra_private, pg_temp
                AS $function$
                BEGIN
                    IF session_user IS DISTINCT FROM (
                        SELECT migration_role FROM veltra_private.mutation_signing_keys WHERE singleton
                    ) THEN
                        RAISE EXCEPTION 'System grant provisioning is unavailable to the runtime role'
                            USING ERRCODE = '42501';
                    END IF;

                    PERFORM id FROM public.organizations WHERE id = NEW.organization_id FOR UPDATE;
                    IF EXISTS (
                        SELECT 1 FROM public.organization_permission_grants existing
                        WHERE existing.organization_id = NEW.organization_id
                            AND existing.user_id = NEW.user_id
                            AND existing.permission = NEW.permission
                            AND tstzrange(existing.valid_from, existing.valid_until, '[)')
                                && tstzrange(NEW.valid_from, NEW.valid_until, '[)')
                            AND NOT EXISTS (
                                SELECT 1 FROM public.organization_permission_grant_events event
                                WHERE event.grant_id = existing.id AND event.event_type = 'REVOKED'
                            )
                    ) THEN
                        RAISE EXCEPTION 'An active organization permission grant already exists'
                            USING ERRCODE = '23505';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM public.raw_material_audit_events audit
                        WHERE audit.organization_id = NEW.organization_id
                            AND audit.operation_id = NEW.system_provisioning_operation_id
                            AND audit.actor_user_id IS NULL
                            AND audit.event_code = 'SYSTEM_PERMISSION_PROVISIONING'
                            AND audit.outcome = 'ACCEPTED'
                            AND audit.metadata->>'manifest_id' = NEW.system_manifest_id
                            AND audit.metadata->>'manifest_version' = NEW.system_manifest_version::text
                            AND audit.metadata->>'recipient_user_id' = NEW.user_id::text
                            AND audit.metadata->'permissions' @> to_jsonb(NEW.permission)
                    ) THEN
                        RAISE EXCEPTION 'System grant requires matching accepted audit history'
                            USING ERRCODE = '23514';
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM public.system_permission_provisioning_operations operation
                        JOIN public.system_permission_manifests manifest
                            ON manifest.manifest_id = operation.manifest_id
                            AND manifest.version = operation.manifest_version
                        WHERE operation.id = NEW.system_provisioning_operation_id
                            AND manifest.module_key <> 'RAW_MATERIALS'
                    ) THEN
                        RAISE EXCEPTION 'System grant manifest does not belong to RAW_MATERIALS'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END
                $function$;
                REVOKE ALL ON FUNCTION veltra_private.validate_system_permission_grant() FROM PUBLIC;
                CREATE TRIGGER organization_permission_grants_validate_system
                    BEFORE INSERT ON public.organization_permission_grants
                    FOR EACH ROW WHEN (NEW.grant_origin = 'SYSTEM_PROVISIONING')
                    EXECUTE FUNCTION veltra_private.validate_system_permission_grant();

                REVOKE ALL ON public.system_permission_manifests,
                    public.system_permission_manifest_permissions,
                    public.system_permission_provisioning_operations FROM PUBLIC;
                SQL);

            DB::unprepared(sprintf(
                'REVOKE ALL ON public.system_permission_manifests, public.system_permission_manifest_permissions, public.system_permission_provisioning_operations FROM %s',
                $quotedRuntimeRole,
            ));

            foreach (['system_permission_manifests', 'system_permission_manifest_permissions', 'system_permission_provisioning_operations'] as $table) {
                if (DB::scalar("SELECT has_table_privilege(?, 'public.{$table}', 'INSERT,UPDATE,DELETE,TRUNCATE')", [$runtimeRole])) {
                    throw new RuntimeException("Rola runtime zachowuje prawo zapisu do {$table}.");
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja pochodzenia grantów systemowych jest forward-only.');
    }
};
