<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runtimeRole = (string) env('APP_DB_USERNAME', 'veltra_app');
        if ($runtimeRole === '') {
            throw new RuntimeException('The runtime database role name is invalid.');
        }
        $quotedRuntimeRole = DB::selectOne('SELECT quote_ident(?) AS role_name', [$runtimeRole])->role_name;

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.organization_capability_events (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL
                    REFERENCES public.organizations(id) ON DELETE RESTRICT,
                capability_key varchar(80) NOT NULL
                    CHECK (capability_key = 'BOSS_RAW_CATALOG_READ'),
                operation_id uuid NOT NULL,
                event_kind varchar(24) NOT NULL
                    CHECK (event_kind IN ('activation_attempt', 'activation_transition')),
                outcome varchar(20) NOT NULL CHECK (outcome IN ('accepted', 'rejected')),
                actor_id uuid NOT NULL,
                operating_location_id uuid NOT NULL,
                previous_enabled boolean NOT NULL,
                requested_enabled boolean NOT NULL,
                effective_enabled boolean NOT NULL,
                expected_state_version bigint NOT NULL CHECK (expected_state_version >= 0),
                previous_state_version bigint NOT NULL CHECK (previous_state_version >= 0),
                result_state_version bigint NOT NULL CHECK (result_state_version >= 0),
                state_changed boolean NOT NULL,
                detail_code varchar(80) NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT organization_capability_events_actor_org_fk
                    FOREIGN KEY (actor_id, organization_id)
                    REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                CONSTRAINT organization_capability_events_context_org_fk
                    FOREIGN KEY (operating_location_id, organization_id)
                    REFERENCES public.locations(id, organization_id) ON DELETE RESTRICT,
                CONSTRAINT organization_capability_events_operation_kind_unique
                    UNIQUE (operation_id, event_kind),
                CONSTRAINT organization_capability_events_attempt_transition_check
                    CHECK (
                        (event_kind = 'activation_attempt'
                            AND (
                                (state_changed
                                    AND outcome = 'accepted'
                                    AND expected_state_version = previous_state_version
                                    AND previous_enabled IS DISTINCT FROM requested_enabled
                                    AND effective_enabled = requested_enabled
                                    AND result_state_version = previous_state_version + 1)
                                OR
                                (NOT state_changed
                                    AND result_state_version = previous_state_version
                                    AND effective_enabled = previous_enabled
                                    AND (
                                        (outcome = 'rejected')
                                        OR (outcome = 'accepted' AND previous_enabled = requested_enabled)
                                    ))
                            ))
                        OR
                        (event_kind = 'activation_transition'
                            AND state_changed
                            AND outcome = 'accepted'
                            AND expected_state_version = previous_state_version
                            AND previous_enabled IS DISTINCT FROM requested_enabled
                            AND effective_enabled = requested_enabled
                            AND result_state_version = previous_state_version + 1)
                    ),
                CONSTRAINT organization_capability_events_accepted_version_check
                    CHECK (outcome <> 'accepted' OR expected_state_version = previous_state_version)
            );

            CREATE INDEX organization_capability_events_history_idx
                ON public.organization_capability_events
                   (organization_id, capability_key, occurred_at DESC, id DESC);
            CREATE INDEX organization_capability_events_operation_idx
                ON public.organization_capability_events
                   (organization_id, operation_id, event_kind);

            CREATE TABLE public.organization_capability_states (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL
                    REFERENCES public.organizations(id) ON DELETE RESTRICT,
                capability_key varchar(80) NOT NULL
                    CHECK (capability_key = 'BOSS_RAW_CATALOG_READ'),
                enabled boolean NOT NULL,
                state_version bigint NOT NULL CHECK (state_version >= 1),
                last_transition_event_id uuid NOT NULL,
                created_by uuid NOT NULL,
                updated_by uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT organization_capability_states_created_by_org_fk
                    FOREIGN KEY (created_by, organization_id)
                    REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                CONSTRAINT organization_capability_states_updated_by_org_fk
                    FOREIGN KEY (updated_by, organization_id)
                    REFERENCES public.users(id, organization_id) ON DELETE RESTRICT,
                CONSTRAINT organization_capability_states_org_key_unique
                    UNIQUE (organization_id, capability_key),
                CONSTRAINT organization_capability_states_last_event_fk
                    FOREIGN KEY (last_transition_event_id)
                    REFERENCES public.organization_capability_events(id)
                    DEFERRABLE INITIALLY DEFERRED
            );

            CREATE TRIGGER organization_capability_states_authorization_ticket
                BEFORE INSERT OR UPDATE OR DELETE ON public.organization_capability_states
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();

            CREATE OR REPLACE FUNCTION veltra_private.guard_organization_capability_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Organization capability state cannot be deleted'
                        USING ERRCODE = '23514';
                END IF;

                PERFORM id FROM public.organizations
                    WHERE id = NEW.organization_id
                    FOR UPDATE;

                IF TG_OP = 'INSERT' THEN
                    IF NOT NEW.enabled OR NEW.state_version <> 1
                        OR NEW.updated_by IS DISTINCT FROM NEW.created_by THEN
                        RAISE EXCEPTION 'Initial organization capability state must be enabled at version 1'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.capability_key IS DISTINCT FROM OLD.capability_key
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Organization capability state identity is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.enabled IS NOT DISTINCT FROM OLD.enabled
                    OR NEW.state_version IS DISTINCT FROM OLD.state_version + 1
                    OR NEW.last_transition_event_id IS NOT DISTINCT FROM OLD.last_transition_event_id THEN
                    RAISE EXCEPTION 'Organization capability state requires exactly one versioned transition'
                        USING ERRCODE = '23514';
                END IF;

                NEW.updated_at := clock_timestamp();
                RETURN NEW;
            END;
            $function$;

            CREATE TRIGGER organization_capability_states_guard
                BEFORE INSERT OR UPDATE OR DELETE ON public.organization_capability_states
                FOR EACH ROW EXECUTE FUNCTION veltra_private.guard_organization_capability_state();

            CREATE OR REPLACE FUNCTION veltra_private.reject_organization_capability_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            BEGIN
                RAISE EXCEPTION 'Organization capability audit is append-only'
                    USING ERRCODE = '23514';
            END;
            $function$;

            CREATE TRIGGER organization_capability_events_append_only
                BEFORE UPDATE OR DELETE ON public.organization_capability_events
                FOR EACH ROW EXECUTE FUNCTION veltra_private.reject_organization_capability_event_mutation();

            CREATE TRIGGER organization_capability_events_authorization_ticket
                BEFORE INSERT OR UPDATE OR DELETE ON public.organization_capability_events
                FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation();

            CREATE OR REPLACE FUNCTION veltra_private.require_organization_capability_event_pair()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            DECLARE
                counterpart public.organization_capability_events%ROWTYPE;
                counterpart_count integer;
            BEGIN
                IF NEW.event_kind = 'activation_attempt' THEN
                    SELECT count(*) INTO counterpart_count
                    FROM public.organization_capability_events
                    WHERE operation_id = NEW.operation_id
                        AND event_kind = 'activation_transition';

                    IF NEW.state_changed THEN
                        IF counterpart_count <> 1 THEN
                            RAISE EXCEPTION 'A changed activation attempt requires exactly one transition'
                                USING ERRCODE = '23514';
                        END IF;

                        SELECT * INTO counterpart
                        FROM public.organization_capability_events
                        WHERE operation_id = NEW.operation_id
                            AND event_kind = 'activation_transition';

                        IF ROW(counterpart.organization_id, counterpart.capability_key,
                                counterpart.actor_id, counterpart.operating_location_id,
                                counterpart.outcome, counterpart.previous_enabled,
                                counterpart.requested_enabled, counterpart.effective_enabled,
                                counterpart.expected_state_version, counterpart.previous_state_version,
                                counterpart.result_state_version, counterpart.state_changed,
                                counterpart.detail_code)
                            IS DISTINCT FROM
                            ROW(NEW.organization_id, NEW.capability_key, NEW.actor_id,
                                NEW.operating_location_id, NEW.outcome, NEW.previous_enabled,
                                NEW.requested_enabled, NEW.effective_enabled,
                                NEW.expected_state_version, NEW.previous_state_version,
                                NEW.result_state_version, NEW.state_changed, NEW.detail_code) THEN
                            RAISE EXCEPTION 'Activation transition does not match its attempt'
                                USING ERRCODE = '23514';
                        END IF;
                    ELSIF counterpart_count <> 0 THEN
                        RAISE EXCEPTION 'An unchanged activation attempt cannot have a transition'
                            USING ERRCODE = '23514';
                    END IF;
                ELSE
                    SELECT * INTO counterpart
                    FROM public.organization_capability_events
                    WHERE operation_id = NEW.operation_id
                        AND event_kind = 'activation_attempt';

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Activation transition has no matching attempt'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NOT counterpart.state_changed
                        OR ROW(counterpart.organization_id, counterpart.capability_key,
                                counterpart.actor_id, counterpart.operating_location_id,
                                counterpart.outcome, counterpart.previous_enabled,
                                counterpart.requested_enabled, counterpart.effective_enabled,
                                counterpart.expected_state_version, counterpart.previous_state_version,
                                counterpart.result_state_version, counterpart.state_changed,
                                counterpart.detail_code)
                            IS DISTINCT FROM
                            ROW(NEW.organization_id, NEW.capability_key, NEW.actor_id,
                                NEW.operating_location_id, NEW.outcome, NEW.previous_enabled,
                                NEW.requested_enabled, NEW.effective_enabled,
                                NEW.expected_state_version, NEW.previous_state_version,
                                NEW.result_state_version, NEW.state_changed, NEW.detail_code)
                        OR NOT EXISTS (
                            SELECT 1
                            FROM public.organization_capability_states state
                            WHERE state.last_transition_event_id = NEW.id
                                AND state.organization_id = NEW.organization_id
                                AND state.capability_key = NEW.capability_key
                                AND state.enabled = NEW.requested_enabled
                                AND state.state_version = NEW.result_state_version
                                AND state.updated_by = NEW.actor_id
                        ) THEN
                        RAISE EXCEPTION 'Activation transition requires its matching attempt and state'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $function$;

            CREATE CONSTRAINT TRIGGER organization_capability_events_pair_deferred
                AFTER INSERT ON public.organization_capability_events
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION veltra_private.require_organization_capability_event_pair();

            CREATE OR REPLACE FUNCTION veltra_private.require_organization_capability_state_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM public.organization_capability_events event
                    WHERE event.id = NEW.last_transition_event_id
                        AND event.organization_id = NEW.organization_id
                        AND event.capability_key = NEW.capability_key
                        AND event.event_kind = 'activation_transition'
                        AND event.outcome = 'accepted'
                        AND event.state_changed
                        AND event.actor_id = NEW.updated_by
                        AND event.previous_enabled IS DISTINCT FROM NEW.enabled
                        AND event.requested_enabled = NEW.enabled
                        AND event.effective_enabled = NEW.enabled
                        AND event.previous_state_version = NEW.state_version - 1
                        AND event.result_state_version = NEW.state_version
                ) THEN
                    RAISE EXCEPTION 'Organization capability state requires matching accepted audit'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $function$;

            CREATE CONSTRAINT TRIGGER organization_capability_state_audit_deferred
                AFTER INSERT OR UPDATE ON public.organization_capability_states
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION veltra_private.require_organization_capability_state_audit();

            REVOKE ALL ON FUNCTION veltra_private.guard_organization_capability_state() FROM PUBLIC;
            REVOKE ALL ON FUNCTION veltra_private.reject_organization_capability_event_mutation() FROM PUBLIC;
            REVOKE ALL ON FUNCTION veltra_private.require_organization_capability_event_pair() FROM PUBLIC;
            REVOKE ALL ON FUNCTION veltra_private.require_organization_capability_state_audit() FROM PUBLIC;
            SQL);

        DB::table('veltra_private.authorization_mutation_scopes')->insert([
            ['table_name' => 'organization_capability_states', 'operation' => 'INSERT', 'scope_prefix' => 'organization-capability.configure'],
            ['table_name' => 'organization_capability_states', 'operation' => 'UPDATE', 'scope_prefix' => 'organization-capability.configure'],
            ['table_name' => 'organization_capability_events', 'operation' => 'INSERT', 'scope_prefix' => 'organization-capability.audit'],
        ]);
        DB::table('veltra_private.runtime_table_allowlist')->insert([
            ['table_name' => 'organization_capability_states'],
            ['table_name' => 'organization_capability_events'],
        ]);

        DB::unprepared(sprintf(
            'REVOKE ALL ON TABLE public.organization_capability_states, public.organization_capability_events FROM PUBLIC, %s;
             GRANT SELECT, INSERT, UPDATE ON TABLE public.organization_capability_states TO %s;
             GRANT SELECT, INSERT ON TABLE public.organization_capability_events TO %s;',
            $quotedRuntimeRole,
            $quotedRuntimeRole,
            $quotedRuntimeRole,
        ));
    }

    public function down(): void
    {
        throw new RuntimeException('The organization capability schema is append-only and cannot be rolled back.');
    }
};
