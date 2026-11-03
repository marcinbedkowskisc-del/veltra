<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE raw_material_categories (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                parent_id uuid,
                code varchar(40) NOT NULL CHECK (code ~ '^[A-Z0-9_-]+$'),
                name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
                depth smallint NOT NULL CHECK (depth BETWEEN 1 AND 3),
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (organization_id, code),
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (parent_id, organization_id)
                    REFERENCES raw_material_categories(id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_material_categories_parent_idx
                ON raw_material_categories(organization_id, parent_id, name);

            CREATE TABLE raw_materials (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                code varchar(16) NOT NULL CHECK (code ~ '^SUR-[0-9]{6,}$'),
                lifecycle_state varchar(16) NOT NULL CHECK (lifecycle_state IN (
                    'DRAFT', 'VERIFICATION', 'ACTIVE', 'BLOCKED', 'ARCHIVED')),
                approved_revision_id uuid,
                pending_revision_id uuid,
                working_revision_id uuid,
                blocked_at timestamptz,
                blocked_by uuid,
                block_reason text,
                archived_at timestamptz,
                archived_by uuid,
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                UNIQUE (organization_id, code),
                FOREIGN KEY (blocked_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (archived_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                CHECK ((lifecycle_state = 'BLOCKED' AND blocked_at IS NOT NULL AND blocked_by IS NOT NULL
                        AND block_reason IS NOT NULL AND btrim(block_reason) <> '')
                    OR (lifecycle_state <> 'BLOCKED' AND blocked_at IS NULL AND blocked_by IS NULL
                        AND block_reason IS NULL)),
                CHECK ((lifecycle_state = 'ARCHIVED' AND archived_at IS NOT NULL AND archived_by IS NOT NULL)
                    OR (lifecycle_state <> 'ARCHIVED' AND archived_at IS NULL AND archived_by IS NULL)),
                CHECK (lifecycle_state <> 'DRAFT' OR
                    (approved_revision_id IS NULL AND pending_revision_id IS NULL AND working_revision_id IS NOT NULL)),
                CHECK (lifecycle_state <> 'VERIFICATION' OR
                    (pending_revision_id IS NOT NULL AND working_revision_id IS NULL)),
                CHECK (lifecycle_state NOT IN ('ACTIVE', 'BLOCKED', 'ARCHIVED') OR approved_revision_id IS NOT NULL),
                CHECK (working_revision_id IS NULL OR pending_revision_id IS NULL)
            );
            CREATE INDEX raw_materials_organization_state_idx
                ON raw_materials(organization_id, lifecycle_state, code);

            CREATE TABLE raw_material_code_counters (
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                prefix varchar(3) NOT NULL CHECK (prefix = 'SUR'),
                last_value bigint NOT NULL CHECK (last_value BETWEEN 0 AND 999999999),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY (organization_id, prefix)
            );

            CREATE TABLE raw_material_revisions (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                raw_material_id uuid NOT NULL,
                revision_no integer NOT NULL CHECK (revision_no > 0),
                category_id uuid NOT NULL,
                name varchar(200) NOT NULL CHECK (btrim(name) <> ''),
                description text,
                base_unit_code varchar(24) NOT NULL CHECK (base_unit_code IN ('mg', 'g', 'kg', 'ml', 'l', 'pc')),
                base_unit_dimension varchar(12) NOT NULL CHECK (base_unit_dimension IN ('MASS', 'VOLUME', 'COUNT')),
                allergen_state varchar(16) NOT NULL DEFAULT 'UNKNOWN'
                    CHECK (allergen_state IN ('UNKNOWN', 'DECLARED')),
                critical_change boolean NOT NULL,
                change_reason text,
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                content_hash char(64) NOT NULL CHECK (content_hash ~ '^[0-9a-f]{64}$'),
                UNIQUE (raw_material_id, revision_no),
                UNIQUE (id, organization_id),
                UNIQUE (id, raw_material_id, organization_id),
                FOREIGN KEY (created_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (raw_material_id, organization_id)
                    REFERENCES raw_materials(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (category_id, organization_id)
                    REFERENCES raw_material_categories(id, organization_id) ON DELETE RESTRICT
            );

            ALTER TABLE raw_materials
                ADD CONSTRAINT raw_materials_approved_revision_fk
                    FOREIGN KEY (approved_revision_id, id, organization_id)
                    REFERENCES raw_material_revisions(id, raw_material_id, organization_id)
                    DEFERRABLE INITIALLY DEFERRED,
                ADD CONSTRAINT raw_materials_pending_revision_fk
                    FOREIGN KEY (pending_revision_id, id, organization_id)
                    REFERENCES raw_material_revisions(id, raw_material_id, organization_id)
                    DEFERRABLE INITIALLY DEFERRED,
                ADD CONSTRAINT raw_materials_working_revision_fk
                    FOREIGN KEY (working_revision_id, id, organization_id)
                    REFERENCES raw_material_revisions(id, raw_material_id, organization_id)
                    DEFERRABLE INITIALLY DEFERRED;

            CREATE TABLE raw_material_aliases (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                raw_material_id uuid NOT NULL,
                revision_id uuid NOT NULL,
                alias varchar(200) NOT NULL CHECK (btrim(alias) <> ''),
                normalized_alias varchar(220) NOT NULL CHECK (btrim(normalized_alias) <> ''),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (id, organization_id),
                FOREIGN KEY (revision_id, raw_material_id, organization_id)
                    REFERENCES raw_material_revisions(id, raw_material_id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_material_aliases_lookup_idx
                ON raw_material_aliases(organization_id, normalized_alias, raw_material_id);

            CREATE TABLE raw_material_revision_units (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                revision_id uuid NOT NULL,
                unit_code varchar(24) NOT NULL,
                dimension varchar(12) NOT NULL CHECK (dimension IN ('MASS', 'VOLUME', 'COUNT')),
                conversion_kind varchar(20) NOT NULL CHECK (conversion_kind IN ('BASE', 'METRIC', 'MATERIAL_SPECIFIC')),
                to_base_factor numeric(24,12) NOT NULL CHECK (to_base_factor > 0),
                basis text,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (revision_id, unit_code),
                CHECK (unit_code IN ('mg', 'g', 'kg', 'ml', 'l', 'pc')),
                FOREIGN KEY (revision_id, organization_id)
                    REFERENCES raw_material_revisions(id, organization_id) ON DELETE RESTRICT,
                CHECK ((conversion_kind = 'MATERIAL_SPECIFIC' AND basis IS NOT NULL AND btrim(basis) <> '')
                    OR (conversion_kind <> 'MATERIAL_SPECIFIC' AND basis IS NULL))
            );

            CREATE TABLE raw_material_revision_approval_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                revision_id uuid NOT NULL,
                operation_id uuid NOT NULL,
                outcome varchar(16) NOT NULL CHECK (outcome IN ('APPROVED', 'RETURNED')),
                actor_user_id uuid NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                reason text,
                content_hash char(64) NOT NULL CHECK (content_hash ~ '^[0-9a-f]{64}$'),
                FOREIGN KEY (revision_id, organization_id)
                    REFERENCES raw_material_revisions(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                UNIQUE (operation_id, revision_id)
            );
            CREATE INDEX raw_material_approval_history_idx
                ON raw_material_revision_approval_events(organization_id, revision_id, occurred_at DESC);

            CREATE TABLE raw_material_audit_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                operation_id uuid NOT NULL,
                actor_user_id uuid,
                actor_location_id uuid,
                subject_type varchar(60) NOT NULL,
                subject_id uuid,
                event_code varchar(80) NOT NULL CHECK (event_code ~ '^[A-Z][A-Z0-9_]{0,79}$'),
                outcome varchar(16) NOT NULL CHECK (outcome IN ('ACCEPTED', 'REJECTED')),
                reason_code varchar(80),
                before_hash char(64) CHECK (before_hash IS NULL OR before_hash ~ '^[0-9a-f]{64}$'),
                after_hash char(64) CHECK (after_hash IS NULL OR after_hash ~ '^[0-9a-f]{64}$'),
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
                FOREIGN KEY (actor_location_id, organization_id)
                    REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT
            );
            CREATE INDEX raw_material_audit_org_time_idx
                ON raw_material_audit_events(organization_id, occurred_at DESC);
            CREATE INDEX raw_material_audit_operation_idx
                ON raw_material_audit_events(organization_id, operation_id);
            CREATE INDEX raw_material_audit_subject_idx
                ON raw_material_audit_events(organization_id, subject_type, subject_id, occurred_at DESC);

            CREATE TABLE organization_permission_grants (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                user_id uuid NOT NULL,
                permission varchar(80) NOT NULL CHECK (permission IN (
                    'RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE', 'RAW_MATERIALS_EDIT',
                    'RAW_MATERIALS_SUBMIT', 'RAW_MATERIALS_REVIEW', 'RAW_MATERIALS_APPROVE',
                    'RAW_MATERIALS_BLOCK', 'RAW_MATERIALS_UNBLOCK', 'RAW_MATERIALS_ARCHIVE',
                    'RAW_MATERIALS_RESTORE', 'RAW_MATERIALS_CATEGORY_MANAGE',
                    'RAW_MATERIALS_AUDIT_VIEW', 'RAW_MATERIALS_PERMISSION_DELEGATE')),
                granted_by uuid NOT NULL,
                granted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                valid_from timestamptz NOT NULL CHECK (isfinite(valid_from)),
                valid_until timestamptz CHECK (isfinite(valid_until) AND valid_until > valid_from),
                delegation_source_id uuid,
                bootstrap_operation_id uuid,
                reason_code varchar(80) NOT NULL CHECK (btrim(reason_code) <> ''),
                FOREIGN KEY (user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (granted_by, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (delegation_source_id, organization_id)
                    REFERENCES organization_permission_grants(id, organization_id) ON DELETE RESTRICT,
                UNIQUE (id, organization_id),
                CHECK (granted_by <> user_id),
                CHECK ((delegation_source_id IS NULL) = (bootstrap_operation_id IS NOT NULL))
            );
            CREATE INDEX organization_permission_grants_effective_idx
                ON organization_permission_grants(organization_id, user_id, permission, valid_from, valid_until);

            CREATE TABLE organization_permission_grant_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                grant_id uuid NOT NULL,
                event_type varchar(16) NOT NULL CHECK (event_type IN ('REVOKED')),
                actor_user_id uuid NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                reason_code varchar(80) NOT NULL CHECK (btrim(reason_code) <> ''),
                FOREIGN KEY (grant_id, organization_id)
                    REFERENCES organization_permission_grants(id, organization_id) ON DELETE RESTRICT,
                FOREIGN KEY (actor_user_id, organization_id)
                    REFERENCES users(id, organization_id) ON DELETE RESTRICT,
                UNIQUE (grant_id, event_type)
            );
            CREATE INDEX organization_permission_grant_events_org_idx
                ON organization_permission_grant_events(organization_id, grant_id);

            CREATE FUNCTION raw_materials_serialize_organization() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE organization_key uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    organization_key := OLD.organization_id;
                ELSE
                    organization_key := NEW.organization_id;
                END IF;
                PERFORM id FROM organizations WHERE id = organization_key FOR UPDATE;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END $$;

            CREATE FUNCTION raw_materials_category_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_depth smallint;
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF NEW.parent_id IS NULL THEN
                    IF NEW.depth <> 1 THEN
                        RAISE EXCEPTION 'Root category depth must be one' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    SELECT depth INTO parent_depth FROM raw_material_categories
                        WHERE id = NEW.parent_id AND organization_id = NEW.organization_id;
                    IF parent_depth IS NULL OR NEW.depth <> parent_depth + 1 OR NEW.depth > 3 THEN
                        RAISE EXCEPTION 'Category depth exceeds the permitted hierarchy' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_material_categories_validate
                BEFORE INSERT OR UPDATE OF parent_id, depth, organization_id ON raw_material_categories
                FOR EACH ROW EXECUTE FUNCTION raw_materials_category_validate();
            CREATE TRIGGER raw_material_categories_serialize
                BEFORE INSERT OR UPDATE OR DELETE ON raw_material_categories
                FOR EACH ROW EXECUTE FUNCTION raw_materials_serialize_organization();
            CREATE FUNCTION raw_material_categories_preserve_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Raw material categories cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.parent_id IS DISTINCT FROM OLD.parent_id
                    OR NEW.depth IS DISTINCT FROM OLD.depth OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Category identity and hierarchy are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_material_categories_identity
                BEFORE UPDATE OR DELETE ON raw_material_categories
                FOR EACH ROW EXECUTE FUNCTION raw_material_categories_preserve_identity();

            CREATE FUNCTION raw_materials_preserve_master() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Raw materials cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Raw material identity and code are immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.approved_revision_id IS DISTINCT FROM OLD.approved_revision_id AND (
                    NEW.approved_revision_id IS NULL
                    OR NEW.approved_revision_id IS DISTINCT FROM OLD.pending_revision_id
                    OR NOT EXISTS (
                        SELECT 1 FROM raw_material_revision_approval_events approvals
                        JOIN raw_material_revisions revisions ON revisions.id = approvals.revision_id
                        WHERE approvals.organization_id = NEW.organization_id
                            AND approvals.revision_id = NEW.approved_revision_id
                            AND approvals.outcome = 'APPROVED'
                            AND approvals.content_hash = revisions.content_hash
                    )
                ) THEN
                    RAISE EXCEPTION 'An approved revision requires a matching approval event' USING ERRCODE = '23514';
                END IF;
                IF NEW.pending_revision_id IS NOT NULL
                    AND NEW.pending_revision_id IS DISTINCT FROM OLD.pending_revision_id
                    AND NEW.pending_revision_id IS DISTINCT FROM OLD.working_revision_id THEN
                    RAISE EXCEPTION 'A pending revision must be the current working revision' USING ERRCODE = '23514';
                END IF;
                IF NEW.working_revision_id IS NOT NULL
                    AND NEW.working_revision_id IS DISTINCT FROM OLD.working_revision_id
                    AND NEW.working_revision_id IS DISTINCT FROM OLD.pending_revision_id
                    AND EXISTS (SELECT 1 FROM raw_material_revisions WHERE id = NEW.working_revision_id) THEN
                    RAISE EXCEPTION 'A working revision cannot reuse a historical revision' USING ERRCODE = '23514';
                END IF;
                IF NEW.lifecycle_state IS DISTINCT FROM OLD.lifecycle_state AND NOT (
                    (OLD.lifecycle_state = 'DRAFT' AND NEW.lifecycle_state = 'VERIFICATION')
                    OR (OLD.lifecycle_state = 'VERIFICATION' AND NEW.lifecycle_state IN ('DRAFT', 'ACTIVE'))
                    OR (OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state IN ('BLOCKED', 'ARCHIVED'))
                    OR (OLD.lifecycle_state = 'BLOCKED' AND NEW.lifecycle_state IN ('ACTIVE', 'ARCHIVED'))
                    OR (OLD.lifecycle_state = 'ARCHIVED' AND NEW.lifecycle_state = 'VERIFICATION')
                ) THEN
                    RAISE EXCEPTION 'Raw material lifecycle transition is invalid' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_materials_preserve_master
                BEFORE UPDATE OR DELETE ON raw_materials
                FOR EACH ROW EXECUTE FUNCTION raw_materials_preserve_master();
            CREATE TRIGGER raw_materials_serialize
                BEFORE INSERT OR UPDATE OR DELETE ON raw_materials
                FOR EACH ROW EXECUTE FUNCTION raw_materials_serialize_organization();

            CREATE FUNCTION raw_materials_revision_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Raw material revisions are immutable' USING ERRCODE = '23514';
            END $$;
            CREATE TRIGGER raw_material_revisions_immutable
                BEFORE UPDATE OR DELETE ON raw_material_revisions
                FOR EACH ROW EXECUTE FUNCTION raw_materials_revision_immutable();
            CREATE FUNCTION raw_materials_revision_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF NOT EXISTS (
                    SELECT 1 FROM raw_materials
                    WHERE id = NEW.raw_material_id AND organization_id = NEW.organization_id
                        AND working_revision_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'A revision must be created as the current working revision' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_material_revisions_validate
                BEFORE INSERT ON raw_material_revisions
                FOR EACH ROW EXECUTE FUNCTION raw_materials_revision_validate();

            CREATE FUNCTION raw_materials_child_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE revision_key uuid;
            DECLARE material_key uuid;
            DECLARE organization_key uuid;
            DECLARE base_unit text;
            DECLARE base_dimension text;
            DECLARE unit_scale smallint;
            DECLARE base_scale smallint;
            BEGIN
                IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Raw material revision data is immutable' USING ERRCODE = '23514';
                END IF;
                revision_key := NEW.revision_id;
                organization_key := NEW.organization_id;
                IF TG_TABLE_NAME = 'raw_material_aliases' THEN
                    material_key := NEW.raw_material_id;
                ELSE
                    SELECT raw_material_id, base_unit_code, base_unit_dimension
                        INTO material_key, base_unit, base_dimension
                        FROM raw_material_revisions WHERE id = revision_key;
                    IF NOT FOUND OR NOT EXISTS (
                        SELECT 1 FROM (VALUES
                            ('mg', 'MASS'), ('g', 'MASS'), ('kg', 'MASS'),
                            ('ml', 'VOLUME'), ('l', 'VOLUME'), ('pc', 'COUNT')
                        ) AS units(code, dimension)
                        WHERE units.code = NEW.unit_code AND units.dimension = NEW.dimension
                    ) THEN
                        RAISE EXCEPTION 'Raw material unit is invalid' USING ERRCODE = '23514';
                    END IF;
                    IF (NEW.conversion_kind = 'BASE' AND
                            (NEW.unit_code <> base_unit OR NEW.dimension <> base_dimension OR NEW.to_base_factor <> 1))
                        OR (NEW.conversion_kind = 'METRIC' AND NEW.dimension <> base_dimension)
                        OR (NEW.conversion_kind = 'MATERIAL_SPECIFIC' AND
                            (NEW.dimension = base_dimension OR NEW.basis IS NULL OR btrim(NEW.basis) = '')) THEN
                        RAISE EXCEPTION 'Raw material conversion is invalid' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.conversion_kind = 'METRIC' THEN
                        unit_scale := CASE NEW.unit_code WHEN 'mg' THEN -3 WHEN 'kg' THEN 3 WHEN 'l' THEN 3 ELSE 0 END;
                        base_scale := CASE base_unit WHEN 'mg' THEN -3 WHEN 'kg' THEN 3 WHEN 'l' THEN 3 ELSE 0 END;
                        IF NEW.to_base_factor <> power(10::numeric, unit_scale - base_scale) THEN
                            RAISE EXCEPTION 'Metric conversion factor is invalid' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM raw_materials
                    WHERE id = material_key AND organization_id = organization_key
                        AND working_revision_id = revision_key
                ) THEN
                    RAISE EXCEPTION 'Revision is closed for changes' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_material_aliases_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON raw_material_aliases
                FOR EACH ROW EXECUTE FUNCTION raw_materials_child_immutable();
            CREATE TRIGGER raw_material_units_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON raw_material_revision_units
                FOR EACH ROW EXECUTE FUNCTION raw_materials_child_immutable();

            CREATE FUNCTION raw_material_alias_unique_for_organization() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF EXISTS (
                    SELECT 1 FROM raw_material_aliases a
                    WHERE a.organization_id = NEW.organization_id
                        AND a.normalized_alias = NEW.normalized_alias
                        AND a.raw_material_id <> NEW.raw_material_id
                ) THEN
                    RAISE EXCEPTION 'Raw material alias already exists' USING ERRCODE = '23505';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_material_alias_unique
                BEFORE INSERT ON raw_material_aliases
                FOR EACH ROW EXECUTE FUNCTION raw_material_alias_unique_for_organization();

            CREATE FUNCTION raw_materials_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Raw material history is append-only' USING ERRCODE = '23514';
            END $$;
            CREATE TRIGGER raw_material_approval_events_append_only
                BEFORE UPDATE OR DELETE ON raw_material_revision_approval_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_append_only();
            CREATE TRIGGER raw_material_audit_events_append_only
                BEFORE UPDATE OR DELETE ON raw_material_audit_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_append_only();
            CREATE TRIGGER organization_permission_grant_events_append_only
                BEFORE UPDATE OR DELETE ON organization_permission_grant_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_append_only();
            CREATE TRIGGER organization_permission_grants_append_only
                BEFORE UPDATE OR DELETE ON organization_permission_grants
                FOR EACH ROW EXECUTE FUNCTION raw_materials_append_only();
            CREATE FUNCTION raw_material_approval_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                IF NOT EXISTS (
                    SELECT 1 FROM raw_materials materials
                    JOIN raw_material_revisions revisions ON revisions.id = NEW.revision_id
                    WHERE materials.id = revisions.raw_material_id
                        AND materials.organization_id = NEW.organization_id
                        AND materials.pending_revision_id = NEW.revision_id
                        AND revisions.content_hash = NEW.content_hash
                        AND (NEW.outcome <> 'APPROVED' OR revisions.allergen_state = 'DECLARED')
                ) THEN
                    RAISE EXCEPTION 'Approval event does not match the pending revision' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER raw_material_approval_events_validate
                BEFORE INSERT ON raw_material_revision_approval_events
                FOR EACH ROW EXECUTE FUNCTION raw_material_approval_validate();
            CREATE FUNCTION raw_materials_serialize_grant() RETURNS trigger LANGUAGE plpgsql AS $$
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
                END IF;
                IF NEW.delegation_source_id IS NULL AND (
                    NOT EXISTS (
                        SELECT 1 FROM raw_material_audit_events audit
                        WHERE audit.organization_id = NEW.organization_id
                            AND audit.operation_id = NEW.bootstrap_operation_id
                            AND audit.actor_user_id = NEW.granted_by
                            AND audit.event_code = 'PERMISSION_BOOTSTRAP'
                            AND audit.outcome = 'ACCEPTED'
                            AND audit.metadata->>'recipient_user_id' = NEW.user_id::text
                            AND audit.metadata->'permissions' @> to_jsonb(NEW.permission)
                    )
                    OR EXISTS (
                        SELECT 1 FROM organization_permission_grants existing
                        WHERE existing.organization_id = NEW.organization_id
                            AND existing.bootstrap_operation_id IS DISTINCT FROM NEW.bootstrap_operation_id
                    )
                ) THEN
                    RAISE EXCEPTION 'Organization grant bootstrap is invalid' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER organization_permission_grants_validate
                BEFORE INSERT ON organization_permission_grants
                FOR EACH ROW EXECUTE FUNCTION raw_materials_serialize_grant();
            CREATE TRIGGER organization_permission_grant_events_serialize
                BEFORE INSERT ON organization_permission_grant_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_serialize_organization();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE organization_permission_grant_events;
            DROP TABLE organization_permission_grants;
            DROP TABLE raw_material_audit_events;
            DROP TABLE raw_material_revision_approval_events;
            DROP TABLE raw_material_revision_units;
            DROP TABLE raw_material_aliases;
            ALTER TABLE raw_materials
                DROP CONSTRAINT raw_materials_working_revision_fk,
                DROP CONSTRAINT raw_materials_pending_revision_fk,
                DROP CONSTRAINT raw_materials_approved_revision_fk;
            DROP TABLE raw_material_revisions;
            DROP TABLE raw_material_code_counters;
            DROP TABLE raw_materials;
            DROP TABLE raw_material_categories;
            DROP FUNCTION raw_materials_serialize_grant();
            DROP FUNCTION raw_materials_serialize_organization();
            DROP FUNCTION raw_materials_category_validate();
            DROP FUNCTION raw_material_categories_preserve_identity();
            DROP FUNCTION raw_materials_preserve_master();
            DROP FUNCTION raw_materials_revision_validate();
            DROP FUNCTION raw_materials_revision_immutable();
            DROP FUNCTION raw_materials_child_immutable();
            DROP FUNCTION raw_material_alias_unique_for_organization();
            DROP FUNCTION raw_materials_append_only();
            DROP FUNCTION raw_material_approval_validate();
            SQL);
    }
};
