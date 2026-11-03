<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                integral_text text;
                rounded_text text;
                integer_digits integer;
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

                integral_text := integral::text;
                IF output_scale = 0 THEN
                    RETURN sign_value * integral;
                END IF;
                IF length(integral_text) <= output_scale THEN
                    integral_text := lpad(integral_text, output_scale + 1, '0');
                END IF;
                integer_digits := length(integral_text) - output_scale;
                rounded_text := CASE WHEN sign_value < 0 THEN '-' ELSE '' END
                    || substr(integral_text, 1, integer_digits)
                    || '.'
                    || substr(integral_text, integer_digits + 1, output_scale);

                RETURN rounded_text::numeric;
            END
            $$;

            CREATE TABLE raw_material_permission_upgrade_operations (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                idempotency_key varchar(120) NOT NULL CHECK (idempotency_key ~ '^[A-Za-z0-9._:-]{8,120}$'),
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                actor_user_id uuid NOT NULL,
                recipient_user_id uuid NOT NULL,
                authority_grant_id uuid NOT NULL,
                permissions jsonb NOT NULL
                    CHECK (jsonb_typeof(permissions) = 'array' AND jsonb_array_length(permissions) > 0),
                reason varchar(500) NOT NULL CHECK (btrim(reason) <> ''),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (organization_id, idempotency_key),
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (recipient_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (authority_grant_id, organization_id)
                    REFERENCES organization_permission_grants(id, organization_id) ON DELETE RESTRICT,
                CHECK (actor_user_id <> recipient_user_id)
            );
            CREATE INDEX raw_material_permission_upgrade_actor_idx
                ON raw_material_permission_upgrade_operations(organization_id, actor_user_id, created_at DESC);

            CREATE TRIGGER raw_material_permission_upgrade_operations_append_only
                BEFORE UPDATE OR DELETE ON raw_material_permission_upgrade_operations
                FOR EACH ROW EXECUTE FUNCTION raw_materials_append_only();

            CREATE OR REPLACE FUNCTION raw_materials_permission_upgrade_authority_is_effective(
                organization_key uuid,
                operation_key uuid
            )
            RETURNS boolean
            LANGUAGE sql
            STABLE
            AS $$
                SELECT NOT EXISTS (
                    SELECT 1 FROM raw_material_permission_upgrade_operations operation
                    WHERE operation.id = operation_key
                        AND operation.organization_id = organization_key
                ) OR EXISTS (
                    SELECT 1
                    FROM raw_material_permission_upgrade_operations operation
                    JOIN organization_permission_grants authority
                        ON authority.id = operation.authority_grant_id
                        AND authority.organization_id = operation.organization_id
                    JOIN organizations ON organizations.id = operation.organization_id
                    JOIN users actor ON actor.id = operation.actor_user_id
                        AND actor.organization_id = operation.organization_id
                    WHERE operation.id = operation_key
                        AND operation.organization_id = organization_key
                        AND organizations.is_active
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
                );
            $$;

            CREATE OR REPLACE FUNCTION raw_materials_serialize_grant() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF EXISTS (
                    SELECT 1 FROM organization_permission_grants g
                    WHERE g.organization_id = NEW.organization_id AND g.user_id = NEW.user_id
                        AND g.permission = NEW.permission
                        AND tstzrange(g.valid_from, g.valid_until, '[)')
                            && tstzrange(NEW.valid_from, NEW.valid_until, '[)')
                        AND NOT EXISTS (
                            SELECT 1 FROM organization_permission_grant_events e
                            WHERE e.grant_id = g.id AND e.event_type = 'REVOKED'
                        )
                ) THEN
                    RAISE EXCEPTION 'An active organization permission grant already exists' USING ERRCODE = '23505';
                END IF;
                IF NEW.delegation_source_id IS NOT NULL THEN
                    IF NEW.permission = 'RAW_MATERIALS_PERMISSION_DELEGATE' OR NOT EXISTS (
                        WITH RECURSIVE effective_grants AS (
                            SELECT grants.id, grants.organization_id, grants.user_id, grants.permission,
                                grants.delegation_source_id, grants.valid_from, grants.valid_until
                            FROM organization_permission_grants grants
                            JOIN organizations ON organizations.id = grants.organization_id
                            JOIN users recipient ON recipient.id = grants.user_id
                                AND recipient.organization_id = grants.organization_id
                            WHERE grants.organization_id = NEW.organization_id
                                AND organizations.is_active
                                AND recipient.is_active
                                AND recipient.email_verified_at IS NOT NULL
                                AND EXISTS (
                                    SELECT 1 FROM location_memberships memberships
                                    JOIN locations ON locations.id = memberships.location_id
                                        AND locations.organization_id = memberships.organization_id
                                    WHERE memberships.user_id = recipient.id
                                        AND memberships.organization_id = grants.organization_id
                                        AND memberships.is_active AND locations.is_active
                                )
                                AND grants.delegation_source_id IS NULL
                                AND grants.valid_from <= statement_timestamp()
                                AND (grants.valid_until IS NULL OR grants.valid_until > statement_timestamp())
                                AND raw_materials_permission_upgrade_authority_is_effective(
                                    grants.organization_id, grants.bootstrap_operation_id
                                )
                                AND NOT EXISTS (
                                    SELECT 1 FROM organization_permission_grant_events events
                                    WHERE events.grant_id = grants.id AND events.event_type = 'REVOKED'
                                )
                            UNION ALL
                            SELECT child.id, child.organization_id, child.user_id, child.permission,
                                child.delegation_source_id, child.valid_from, child.valid_until
                            FROM organization_permission_grants child
                            JOIN effective_grants parent ON child.delegation_source_id = parent.id
                            JOIN users recipient ON recipient.id = child.user_id
                                AND recipient.organization_id = child.organization_id
                            WHERE child.valid_from <= statement_timestamp()
                                AND child.granted_by = parent.user_id
                                AND child.permission = parent.permission
                                AND recipient.is_active
                                AND recipient.email_verified_at IS NOT NULL
                                AND EXISTS (
                                    SELECT 1 FROM location_memberships memberships
                                    JOIN locations ON locations.id = memberships.location_id
                                        AND locations.organization_id = memberships.organization_id
                                    WHERE memberships.user_id = recipient.id
                                        AND memberships.organization_id = child.organization_id
                                        AND memberships.is_active AND locations.is_active
                                )
                                AND (child.valid_until IS NULL OR child.valid_until > statement_timestamp())
                                AND NOT EXISTS (
                                    SELECT 1 FROM organization_permission_grant_events events
                                    WHERE events.grant_id = child.id AND events.event_type = 'REVOKED'
                                )
                                AND EXISTS (
                                    SELECT 1 FROM organization_permission_grants authority
                                    WHERE authority.organization_id = child.organization_id
                                        AND authority.user_id = child.granted_by
                                        AND authority.permission = 'RAW_MATERIALS_PERMISSION_DELEGATE'
                                        AND authority.delegation_source_id IS NULL
                                        AND authority.valid_from <= statement_timestamp()
                                        AND (authority.valid_until IS NULL
                                            OR authority.valid_until > statement_timestamp())
                                        AND raw_materials_permission_upgrade_authority_is_effective(
                                            authority.organization_id, authority.bootstrap_operation_id
                                        )
                                        AND NOT EXISTS (
                                            SELECT 1 FROM organization_permission_grant_events events
                                            WHERE events.grant_id = authority.id AND events.event_type = 'REVOKED'
                                        )
                                )
                        )
                        SELECT 1 FROM effective_grants source
                        WHERE source.id = NEW.delegation_source_id
                            AND source.user_id = NEW.granted_by
                            AND source.permission = NEW.permission
                            AND source.permission <> 'RAW_MATERIALS_PERMISSION_DELEGATE'
                            AND source.valid_from <= NEW.valid_from
                            AND (source.valid_until IS NULL
                                OR (NEW.valid_until IS NOT NULL AND NEW.valid_until <= source.valid_until))
                            AND EXISTS (
                                SELECT 1 FROM effective_grants delegate
                                WHERE delegate.user_id = NEW.granted_by
                                    AND delegate.permission = 'RAW_MATERIALS_PERMISSION_DELEGATE'
                                    AND delegate.valid_from <= NEW.valid_from
                                    AND (delegate.valid_until IS NULL
                                        OR (NEW.valid_until IS NOT NULL AND NEW.valid_until <= delegate.valid_until))
                            )
                    ) THEN
                        RAISE EXCEPTION 'Delegation source does not authorize this grant' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NOT (
                        EXISTS (
                            SELECT 1
                            FROM raw_material_permission_upgrade_operations operation
                            JOIN organization_permission_grants authority
                                ON authority.id = operation.authority_grant_id
                                AND authority.organization_id = operation.organization_id
                            JOIN raw_material_audit_events audit
                                ON audit.organization_id = operation.organization_id
                                AND audit.operation_id = operation.id
                            JOIN users actor ON actor.id = operation.actor_user_id
                                AND actor.organization_id = operation.organization_id
                            JOIN users recipient ON recipient.id = operation.recipient_user_id
                                AND recipient.organization_id = operation.organization_id
                            JOIN organizations ON organizations.id = operation.organization_id
                            WHERE operation.id = NEW.bootstrap_operation_id
                                AND operation.organization_id = NEW.organization_id
                                AND operation.actor_user_id = NEW.granted_by
                                AND operation.recipient_user_id = NEW.user_id
                                AND operation.permissions @> to_jsonb(NEW.permission)
                                AND organizations.is_active
                                AND recipient.is_active
                                AND recipient.email_verified_at IS NOT NULL
                                AND EXISTS (
                                    SELECT 1 FROM location_memberships memberships
                                    JOIN locations ON locations.id = memberships.location_id
                                        AND locations.organization_id = memberships.organization_id
                                    WHERE memberships.user_id = recipient.id
                                        AND memberships.organization_id = operation.organization_id
                                        AND memberships.is_active AND locations.is_active
                                )
                                AND audit.actor_user_id = operation.actor_user_id
                                AND audit.event_code = 'PERMISSION_UPGRADE'
                                AND audit.outcome = 'ACCEPTED'
                                AND audit.metadata->>'recipient_user_id' = operation.recipient_user_id::text
                                AND audit.metadata->'permissions' @> to_jsonb(NEW.permission)
                                AND audit.metadata->>'authority_grant_id' = operation.authority_grant_id::text
                                AND actor.is_active
                                AND actor.email_verified_at IS NOT NULL
                                AND EXISTS (
                                    SELECT 1 FROM role_assignments roles
                                    WHERE roles.organization_id = operation.organization_id
                                        AND roles.user_id = operation.actor_user_id
                                        AND roles.role = 'ADMINISTRATOR'
                                        AND roles.location_id IS NULL
                                        AND roles.revoked_at IS NULL
                                )
                                AND raw_materials_permission_upgrade_authority_is_effective(
                                    operation.organization_id, operation.id
                                )
                                AND authority.valid_from <= NEW.valid_from
                                AND (authority.valid_until IS NULL
                                    OR (NEW.valid_until IS NOT NULL AND NEW.valid_until <= authority.valid_until))
                        )
                        OR (
                            EXISTS (
                                SELECT 1 FROM raw_material_audit_events audit
                                WHERE audit.organization_id = NEW.organization_id
                                    AND audit.operation_id = NEW.bootstrap_operation_id
                                    AND audit.actor_user_id = NEW.granted_by
                                    AND audit.event_code = 'PERMISSION_BOOTSTRAP'
                                    AND audit.outcome = 'ACCEPTED'
                                    AND audit.metadata->>'recipient_user_id' = NEW.user_id::text
                                    AND audit.metadata->'permissions' @> to_jsonb(NEW.permission)
                            )
                            AND NOT EXISTS (
                                SELECT 1 FROM organization_permission_grants existing
                                WHERE existing.organization_id = NEW.organization_id
                                    AND existing.bootstrap_operation_id IS DISTINCT FROM NEW.bootstrap_operation_id
                            )
                        )
                    ) THEN
                        RAISE EXCEPTION 'Organization grant bootstrap is invalid' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION raw_materials_revoke_grants_on_suspension() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                organization_key uuid;
                user_key uuid;
                suspension_reason varchar(80);
                suspension_type varchar(40);
                operation_key uuid := gen_random_uuid();
            BEGIN
                IF NOT OLD.is_active OR NEW.is_active THEN
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'users' THEN
                    organization_key := NEW.organization_id;
                    user_key := NEW.id;
                    suspension_reason := 'USER_DEACTIVATED';
                    suspension_type := 'USER';
                ELSE
                    organization_key := NEW.organization_id;
                    user_key := NEW.user_id;
                    suspension_reason := 'MEMBERSHIP_DEACTIVATED';
                    suspension_type := 'MEMBERSHIP';
                END IF;

                PERFORM id FROM organizations WHERE id = organization_key FOR UPDATE;

                WITH RECURSIVE affected_grants(id) AS (
                    SELECT grants.id
                    FROM organization_permission_grants grants
                    WHERE grants.organization_id = organization_key
                        AND grants.user_id = user_key
                    UNION
                    SELECT grants.id
                    FROM raw_material_permission_upgrade_operations upgrades
                    JOIN organization_permission_grants grants
                        ON grants.bootstrap_operation_id = upgrades.id
                        AND grants.organization_id = upgrades.organization_id
                    WHERE upgrades.organization_id = organization_key
                        AND upgrades.actor_user_id = user_key
                    UNION
                    SELECT child.id
                    FROM organization_permission_grants child
                    JOIN affected_grants parent ON child.delegation_source_id = parent.id
                    WHERE child.organization_id = organization_key
                ),
                revoked_grants AS (
                    INSERT INTO organization_permission_grant_events (
                        id, organization_id, grant_id, event_type, actor_user_id, occurred_at, reason_code
                    )
                    SELECT gen_random_uuid(), organization_key, affected_grants.id, 'REVOKED',
                        NULL, clock_timestamp(), suspension_reason
                    FROM affected_grants
                    ON CONFLICT (grant_id, event_type) DO NOTHING
                    RETURNING grant_id
                )
                INSERT INTO raw_material_audit_events (
                    id, organization_id, operation_id, actor_user_id, subject_type, subject_id,
                    event_code, outcome, reason_code, metadata
                )
                SELECT gen_random_uuid(), organization_key, operation_key, NULL,
                    'ORGANIZATION_PERMISSION_GRANT', revoked_grants.grant_id,
                    'PERMISSION_REVOKED', 'ACCEPTED', suspension_reason,
                    jsonb_build_object(
                        'source_type', suspension_type,
                        'source_id', NEW.id,
                        'affected_user_id', user_key
                    )
                FROM revoked_grants;

                RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('This migration is forward-only to preserve financial precision and permission-upgrade audit history.');
    }
};
