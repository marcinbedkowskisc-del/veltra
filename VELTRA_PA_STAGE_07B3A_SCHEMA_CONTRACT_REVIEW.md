# VELTRA — PA-07B.3A — Organizational Capability Schema and API Contract

**Status: CORRECTED CONTRACT FOR FINAL OWNER APPROVAL — proposal only.**

**Baseline:** `09a8cd982f1496a3d6cb80843212a2770540dd70`
**Branch/worktree:** `pa-07b3a-readiness-contract` / `/home/veltra/projekty/veltra-pa-07b3a`

## 1. Decision requested

Approve or amend the additive Stage 1E contract below for the single closed-code capability `BOSS_RAW_CATALOG_READ`. This proposal covers code readiness, explicit organization activation, its audit trail, and administrator API only. It does not implement the BOSS material read, change runtime authorization, or mark anything ready or enabled.

The accepted invariants remain:

- `RAW_MATERIALS` and all existing ordinary RAW availability, permissions, routes, and delegation rules remain unchanged and `NOT_READY`.
- Code readiness and organization activation are separate gates; both default unavailable. Code readiness is a code-defined catalog property, not a database value. Missing persisted activation state means `DISABLED`.
- There is no automatic activation, location fan-out, inheritance from RAW module state, new RBAC role/grant, or BOSS-to-RAW permission implication.
- Any later data read must still use the real authenticated identity and organization membership/context, independent authorization sources, BOSS restrictions, and source-aware visibility. This activation contract grants no data permission.

## 2. PA-07B.3A worktree and scope

The separate worktree was created from the accepted commit on branch `pa-07b3a-readiness-contract`. It does not contain the eight pre-existing dirty paths or the prior untracked architecture plan. The original `/home/veltra/projekty/veltra` worktree has not been edited by this checkpoint.

The only proposed deliverable at this gate is this review document. No application code, tests, migration, configuration, database, activation, commit, push, deployment, STAGING, or production state has been changed. PA-07B.3B and 3C remain unauthorized.

## 3. Existing Stage 1E mechanisms and reuse

| Existing mechanism | Reuse in 3A | Boundary |
|---|---|---|
| Closed-code `ModuleCatalog` / `ModuleDefinition`, with `ready=false` by default | Reuse the invariant: only code may declare a capability ready; unknown keys deny. Add a distinct organization-scoped capability definition with key, scope, readiness, and allowed operations. | Do not add this as a `ModuleDefinition`: that type requires location types and dependencies and feeds location-only `ModuleAvailability`. Do not change `DefinedModuleCatalog` or `ConfiguredModuleAvailability`. |
| `ModuleConfigurationService` | Reuse the transaction shape: lock the organization first, reload the actor/context, recheck current administrator eligibility, serialize competing changes, and reject enabling a not-ready definition. | Do not extend `location_module_states`, bulk location configuration, or `all_compatible_locations`. One organization row represents this capability. |
| Fresh-password confirmation and rate limiting | Reuse the `current_password` control and five-failure/60-second behavior from module configuration. Give the capability flow a separate limiter key. | Password is never stored, returned, or written to audit. |
| `AuthorizationMutationContext` and PostgreSQL signed mutation tickets | Add two narrow signed scopes, one for state changes and one for append-only capability audit inserts. Use the existing transaction-bound ticket/trigger enforcement. | No direct runtime DML or unscoped trigger bypass. The new tables must be explicitly added to the signed-mutation boundary and runtime grants by an approved migration. |
| Organization-row locking | Serialize activation changes on the same `organizations` row, as module configuration and installation mutations already do. This also coordinates version checks. | No location-wide locks or fan-out. |
| `security_events` append-only audit and `AuditSecurityRejection` | Retain middleware rejection auditing; reuse the append-only, actor-attributed audit principle. | Prefer a dedicated capability event table. `security_events`' module-specific shape and `module.configure` history must not be overloaded with organization capability transitions. |
| Security API identity/context middleware and `BusinessAuthorization` | Reuse authenticated SPA session, `ValidateIdentityContext`, `RequireOperatingContext`, throttle, current membership/context, and fresh `eligible` + organization-scoped `ADMINISTRATOR` checks in the service. | Do not trust a submitted organization ID or a stale role/context. No route accepts a target location. |

Relevant source: [Stage 1E implementation report](./VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md), [ModuleConfigurationService](./backend/app/Domain/Modules/ModuleConfigurationService.php), [ModuleDefinition](./backend/app/Domain/Modules/ModuleDefinition.php), [ConfiguredModuleAvailability](./backend/app/Domain/Authorization/ConfiguredModuleAvailability.php), [signed-mutation migration](./backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php), [module-state migration](./backend/database/migrations/2026_11_02_000010_create_location_module_configuration.php), [SecurityMutation](./backend/app/Domain/Authorization/SecurityMutation.php), [API routes](./backend/routes/api.php), and [BusinessAuthorization](./backend/app/Domain/Authorization/BusinessAuthorization.php).

## 4. Proposed code contract

Introduce a separate closed-code `OrganizationCapabilityCatalog` (name provisional) and `OrganizationCapabilityDefinition`, not a second role or permission system. Its sole production entry at this checkpoint is:

| Property | Proposed value |
|---|---|
| Key | `BOSS_RAW_CATALOG_READ` |
| Scope | `ORGANIZATION` |
| Code readiness in 3A | `NOT_READY` |
| Default persisted activation | Missing row = `DISABLED` |
| Permitted future operations | `list`, `detail` only |
| BOSS visibility category | `RAW_SUPPLIERS` |
| Real operating-context types | `CENTRAL_KITCHEN`, `RESTAURANT`; context must be active and have a current membership in the actor's organization |
| Dependency on `RAW_MATERIALS` | None; do not query or change its readiness/configuration |

The effective capability state is `READY AND explicitly ENABLED`. Readiness is descriptive, code-owned metadata. It cannot be set by an administrator endpoint or database row. The capability is not ready in 3A; enabling it must be rejected. A later code release may mark it ready only after the separate 3B read API is accepted and delivered; even then each organization remains disabled until an administrator explicitly enables it.

This definition is a Stage 1E readiness contract, not authorization. Any later data operation must independently enforce its actual actor, organization, membership/context, BOSS role, restriction, source-aware visibility, and approved data provenance. BOSS does not gain `RAW_MATERIALS_VIEW` or any `RawMaterialPermission`.

## 5. Proposed additive PostgreSQL schema

The following is a review specification, **not a migration to run**. Use UUIDs and PostgreSQL constraints consistently with the current schema.

### 5.1 Organization activation state

```sql
CREATE TABLE organization_capability_states (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL
        REFERENCES organizations(id) ON DELETE RESTRICT,
    capability_key varchar(80) NOT NULL
        CHECK (capability_key = 'BOSS_RAW_CATALOG_READ'),
    enabled boolean NOT NULL,
    state_version bigint NOT NULL CHECK (state_version >= 1),
    last_transition_event_id uuid NOT NULL,
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (created_by, organization_id)
        REFERENCES users(id, organization_id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by, organization_id)
        REFERENCES users(id, organization_id) ON DELETE RESTRICT,
    UNIQUE (organization_id, capability_key)
);
```

Rules:

- No row means disabled and reports version `0`; do not prepopulate or auto-enable organizations.
- Persist a row on the first successful enable. Retain it when disabled so its version and provenance are not lost. Disabling an absent capability is an audited no-op and need not create a state row.
- Only `enabled`, `updated_by`, `updated_at`, `state_version`, and `last_transition_event_id` may change on an existing row; `id`, organization, capability key, creator, and creation time are immutable. The PostgreSQL guard requires the enabled value to toggle, the event ID to change, and `state_version` to advance exactly once; idempotent requests do not update the row.
- Every insert/update serializes on the owning organization row. Delete is prohibited. Enabling is rejected unless the code definition is `READY`; disabling remains allowed if code readiness later becomes unavailable.
- No location ID or `RAW_MATERIALS` state is stored in this table.

### 5.2 Append-only capability audit

```sql
CREATE TABLE organization_capability_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL
        REFERENCES organizations(id) ON DELETE RESTRICT,
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
    FOREIGN KEY (actor_id, organization_id)
        REFERENCES users(id, organization_id) ON DELETE RESTRICT,
    FOREIGN KEY (operating_location_id, organization_id)
        REFERENCES locations(id, organization_id) ON DELETE RESTRICT,
    UNIQUE (operation_id, event_kind),
    CHECK (
        (event_kind = 'activation_attempt'
            AND (
                (state_changed
                    AND outcome = 'accepted'
                    AND expected_state_version = previous_state_version
                    AND previous_enabled IS DISTINCT FROM requested_enabled
                    AND result_state_version = previous_state_version + 1)
                OR
                (NOT state_changed
                    AND result_state_version = previous_state_version
                    AND (outcome = 'rejected' OR previous_enabled = requested_enabled))
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
    CHECK (outcome <> 'accepted'
        OR expected_state_version = previous_state_version)
);

CREATE INDEX organization_capability_events_history_idx
    ON organization_capability_events
       (organization_id, capability_key, occurred_at DESC, id DESC);

CREATE INDEX organization_capability_events_operation_idx
    ON organization_capability_events
       (organization_id, operation_id, event_kind);

ALTER TABLE organization_capability_states
    ADD CONSTRAINT organization_capability_states_last_event_fk
    FOREIGN KEY (last_transition_event_id)
    REFERENCES organization_capability_events(id)
    DEFERRABLE INITIALLY DEFERRED;
```

`operation_id` is generated by the server, never accepted from the request. `UNIQUE (operation_id, event_kind)` permits at most one attempt and at most one transition for an operation. The deferred constraint triggers in §5.3 enforce the cross-row rules that ordinary `CHECK`/`UNIQUE` constraints cannot express.

### 5.3 Database-enforced state and audit invariants

The state guard runs `BEFORE INSERT OR UPDATE OR DELETE`, under the signed-mutation trigger:

```sql
CREATE OR REPLACE FUNCTION guard_organization_capability_state()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Organization capability state cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    PERFORM id FROM organizations
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
END $$;

CREATE TRIGGER organization_capability_state_guard
    BEFORE INSERT OR UPDATE OR DELETE ON organization_capability_states
    FOR EACH ROW EXECUTE FUNCTION guard_organization_capability_state();

CREATE OR REPLACE FUNCTION reject_organization_capability_event_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Organization capability audit is append-only'
        USING ERRCODE = '23514';
END $$;

CREATE TRIGGER organization_capability_events_append_only
    BEFORE UPDATE OR DELETE ON organization_capability_events
    FOR EACH ROW EXECUTE FUNCTION reject_organization_capability_event_mutation();
```

In addition, install the following deferred pair check and two `DEFERRABLE INITIALLY DEFERRED` constraint triggers, evaluated at transaction commit:

```sql
CREATE OR REPLACE FUNCTION require_organization_capability_event_pair()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    counterpart organization_capability_events%ROWTYPE;
    counterpart_count integer;
BEGIN
    IF NEW.event_kind = 'activation_attempt' THEN
        SELECT count(*) INTO counterpart_count
        FROM organization_capability_events
        WHERE operation_id = NEW.operation_id
            AND event_kind = 'activation_transition';

        IF NEW.state_changed THEN
            IF counterpart_count <> 1 THEN
                RAISE EXCEPTION 'A changed activation attempt requires exactly one transition'
                    USING ERRCODE = '23514';
            END IF;

            SELECT * INTO counterpart
            FROM organization_capability_events
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
        FROM organization_capability_events
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
                FROM organization_capability_states state
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
END $$;

CREATE CONSTRAINT TRIGGER organization_capability_events_pair_deferred
    AFTER INSERT ON organization_capability_events
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION require_organization_capability_event_pair();

CREATE OR REPLACE FUNCTION require_organization_capability_state_audit()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM organization_capability_events event
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
END $$;

CREATE CONSTRAINT TRIGGER organization_capability_state_audit_deferred
    AFTER INSERT OR UPDATE ON organization_capability_states
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION require_organization_capability_state_audit();
```

The constraints enforce both directions:

1. `organization_capability_state_audit_deferred` requires each state insert/update to reference the matching accepted transition, including organization, capability, actor, previous/requested/effective state, and consecutive versions.
2. `organization_capability_events_pair_deferred` enforces:
   - An `activation_attempt` with `state_changed=true` has exactly one matching transition; an attempt with `state_changed=false` has none.
   - Every `activation_transition` has exactly one matching attempt with the same operation, organization, capability, actor, context, outcome, requested/previous/effective states, expected/observed/result versions, and detail code; it also must be the state row's `last_transition_event_id`.

The unique key makes duplicate attempt/transition rows for one operation impossible. The deferred checks make a missing attempt, missing required transition, orphan transition, or mismatched transition fail the transaction. Rejected and accepted idempotent attempts have no transition. A state insert is allowed only as the first `DISABLED -> ENABLED` transition at version 1; later updates must toggle `enabled` and increment `state_version` by exactly one. This schema does not permit creating an unused disabled row.

### 5.4 Transaction boundaries and audit failures

The route creates one server-generated `operation_id` after request-shape validation and before invoking the activation service. The operation ID is never client-supplied. The service uses the same ID for its attempt and optional transition rows.

**Accepted operation**

1. Start one outer database transaction with the signed `organization-capability.configure` scope. Lock the actor's organization row, reload actor/context and capability state, and revalidate the administrator, organization, and current context membership inside this transaction.
2. Check fresh password, expected version, closed-code capability, and code readiness. For a state change, insert the accepted attempt and transition under a nested `organization-capability.audit` scope, then change the state under the configure scope. Nested scope changes must use the existing `AuthorizationMutationContext` on the same connection/outer transaction.
3. Commit state and both audit rows together. The deferred foreign key and constraint triggers run at commit. If either audit row, the state write, signed-ticket check, or deferred consistency check fails, the whole transaction rolls back and the request cannot report success.
4. An accepted idempotent request commits exactly one accepted attempt and no transition or state write.

**Rejected service operation**

1. Any authorization, current-password, stale-version, readiness, or other domain rejection rolls back the outer mutation transaction. No state change or accepted audit row survives.
2. After rollback, use a fresh audit-only transaction and `organization-capability.audit` signed scope to insert exactly one rejected attempt with the observed state/version captured under the organization lock. It has `state_changed=false`, `result_state_version=previous_state_version`, and no transition.
3. If this rejection-audit insert fails, surface that persistence failure (preserving the original rejection as its cause where supported). Never return success or silently claim the rejection was audited. The state mutation remains rolled back.

**Authentication/security rejection is a different audit stream**

- `AuditSecurityRejection` continues to write its generic `security_events` `security.request` rejection for non-GET failures downstream of that middleware when an actor is available. If a service exception is also recorded as an activation attempt, both rows are intentional: one is generic request-security evidence; the other is the operation-specific capability audit. Capability history reads only `organization_capability_events`.
- SPA/authentication and `ValidateIdentityContext` are earlier in the existing middleware chain; failures there may not reach `AuditSecurityRejection`. Such a rejected request never reaches the activation service, has no operation ID, and creates no capability-attempt row. Do not claim the service table is an audit of anonymous/unauthenticated requests.
- FormRequest/route-shape failures before controller dispatch are not service activation attempts. Existing rejection middleware may record a generic security event for them. The mutation route must not put an administrator middleware in front of the service: after authentication, identity, context, throttle, and shape validation, the service itself revalidates administrator authority so an authenticated non-administrator rejection can receive both the generic security event and the dedicated rejected attempt. Reads/history may use ordinary administrator middleware/service checks.

### 5.5 Signed mutation scopes, triggers, and grants

The additive migration must install the existing `veltra_private.enforce_authorization_mutation()` trigger on both tables, and add exactly these rows to `veltra_private.authorization_mutation_scopes`:

```sql
INSERT INTO veltra_private.authorization_mutation_scopes
    (table_name, operation, scope_prefix)
VALUES
    ('organization_capability_states', 'INSERT', 'organization-capability.configure'),
    ('organization_capability_states', 'UPDATE', 'organization-capability.configure'),
    ('organization_capability_events', 'INSERT', 'organization-capability.audit');
```

No `DELETE`/`TRUNCATE` permission or signed scope is added. The same migration must add both table names to `veltra_private.runtime_table_allowlist`; after Stage 1E's revoked default privileges it explicitly grants `SELECT, INSERT, UPDATE` on state and `SELECT, INSERT` on events. It must not grant schema creation, `TRUNCATE`, event update/delete, or unnecessary sequence privileges. The existing `module.` scopes remain limited to the existing location module state and security-event tables; these new prefixes do not widen them or alter existing scopes.

Do not modify or widen `location_module_states` or the existing `security_events` module check constraint. Do not add a capability row to location-level catalog/state tables.

## 6. Proposed API contract

Add these routes under the existing authenticated `/api/v1/security` middleware stack (`RequireSpaSession`, `auth:sanctum`, `ValidateIdentityContext`, `AuditSecurityRejection`, `RequireOperatingContext`, security throttle), with explicit administrator-only authorization. The service must repeat fresh identity, organization, context membership, and `ADMINISTRATOR` checks inside the organization-locked transaction for mutations.

Do not put `RequireAdministrator` ahead of the `PUT` service call: that would short-circuit the service-level rejected-attempt record. The authenticated request reaches the service, which independently rejects non-administrators inside the locked transaction and records the operation-level rejection. The endpoint must have no alternate code path that changes state. `GET` status/history can use the existing administrator middleware or an equivalent service-level check because they do not represent activation attempts.

No route takes an organization or target-location ID; organization is derived only from the reloaded actor. `operating_location` is a real active membership context and is recorded as audit context.

### Read current readiness and activation

`GET /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ`

```json
{
  "data": {
    "capability": "BOSS_RAW_CATALOG_READ",
    "scope": "ORGANIZATION",
    "readiness": "NOT_READY",
    "configured_state": "DISABLED",
    "available": false,
    "state_version": 0,
    "operations": ["list", "detail"]
  }
}
```

`readiness` comes only from the code catalog. `configured_state` is persisted organization state (absent means disabled). `available` is their conjunction; this status endpoint confers no data access.

### Explicit activation/deactivation

`PUT /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ`

Request:

```json
{
  "enabled": true,
  "expected_state_version": 0,
  "current_password": "..."
}
```

`enabled` is required boolean; `expected_state_version` is required integer `>= 0`; `current_password` is required, bounded, and never logged. A stale expected version returns `409 CAPABILITY_STATE_VERSION_CONFLICT`. Attempting to enable while code readiness is `NOT_READY` returns `409 CAPABILITY_NOT_READY` and leaves state disabled. Wrong password returns `403`; the existing fresh-password throttle behavior returns `429`. Invalid fields return `422`; unauthenticated/invalid identity returns `401`; missing context or non-administrator/foreign context returns `403`; unknown capability returns `404`.

Successful idempotent-disable response when no state row exists:

```json
{
  "data": {
    "operation_id": "server-generated-uuid",
    "capability": "BOSS_RAW_CATALOG_READ",
    "previous_state": "DISABLED",
    "requested_state": "DISABLED",
    "effective_state": "DISABLED",
    "available": false,
    "changed": false,
    "state_version": 0
  }
}
```

Attempting to enable while not ready returns the specified `409`, not a success body. On a ready capability, `effective_state` is `ENABLED` only after a successful explicit activation. An idempotent request with the current version is accepted and audited without a fake transition. Require a fresh `expected_state_version` after any concurrent change.

### Administrator audit history

`GET /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ/history?per_page=25`

Return a paginated, organization-filtered list of the audit fields in §5.2, newest first, with `per_page` bounded to 100. Only an authorized administrator with a valid current context can read it. No secrets or unrelated security-event history are included.

## 7. Authorization decision and invariant checks

For activation mutation, in order:

1. Existing session/authentication/identity/context middleware runs; request shape validation runs before the controller. Failures before service entry do not create a service `operation_id` or capability-attempt row.
2. The service creates the server-side `operation_id`, starts the signed configure transaction, locks the organization row, reloads the actor and operating context, and reads the current state/version for the audit snapshot.
3. Inside that same locked transaction, revalidate active verified actor and organization, context type/activity/organization, current context membership, and effective organization-scoped `ADMINISTRATOR` role. BOSS is not sufficient. This application check is authoritative for admin authorization; the database role used for signed mutations is not a business administrator.
4. Check fresh password and rate limit, compare the submitted expected version with the observed version, resolve the exact closed-code capability, and require code readiness `READY` for `enabled=true`. Do not inspect or change `RAW_MATERIALS` readiness or its location state.
5. On acceptance, perform only the organization state transition and associated attempt/transition audit writes in the single outer transaction. On a service-level rejection, roll it back and persist only the rejected attempt in a new audit-only transaction as specified in §5.4.

For any future BOSS catalog request, this activation state is only one readiness gate. It does not replace BOSS role, actual context/membership, source-aware `BusinessVisibility`, `RAW_SUPPLIERS` category restriction, approved organizational provenance, or independent Stage 1D/RAW authorization. Independent RAW grants do not enable or widen BOSS visibility. A BOSS restriction does not revoke a separate valid RAW grant.

### Enforcement boundary: database versus application

| Invariant | Enforced by |
|---|---|
| Stable capability key, unique organization/capability row, valid FKs, and no cross-organization actor/context attribution | PostgreSQL `CHECK`, `UNIQUE`, and composite foreign keys |
| State identity immutability; insert only as enabled/version 1; each update toggles state, advances version by exactly one, and links a new transition event; no delete | PostgreSQL `BEFORE` state guard and denied runtime `DELETE` privilege |
| One attempt at most and one transition at most per server-generated operation ID; transition required iff an accepted state change occurs; attempt/transition values agree; state change cannot commit without accepted transition evidence | PostgreSQL unique constraint, deferred FK, and deferred constraint triggers |
| Audit append-only | PostgreSQL append-only trigger and no runtime `UPDATE`/`DELETE` privilege |
| Operation ID generation and uniqueness across logical requests | Application generates a fresh UUID and never accepts one from the client; PostgreSQL `UNIQUE (operation_id, event_kind)` prevents reusing it for another attempt/transition of the same kind |
| Each write has the correct narrow mutation scope | Existing signed-ticket trigger, plus only the three `authorization_mutation_scopes` entries in §5.5 |
| Runtime can read/write only the required new tables/operations | Explicit least-privilege grants in §5.5; default privileges remain revoked |
| Organization filtering on status/history reads | Application always derives the organization from the reloaded actor and includes it in every query; the schema's foreign keys enforce attribution but do not provide row-level SELECT isolation |
| Authenticated active identity, verified organization, valid current context/membership, effective administrator role, password freshness/rate limit, code readiness, and API error semantics | Application middleware and service, revalidated inside the locked transaction |
| Future BOSS data read authorization, BOSS restrictions, source-aware visibility, and approved RAW provenance | Not provided by 3A activation. Must be enforced independently in 3B; no new RBAC/RAW grant is created here. |

The database mutation ticket proves that application code invoked an allowed table/operation scope; it does **not** prove the actor is a business `ADMINISTRATOR`, that a password was fresh, or that code readiness is `READY`. Those checks stay in the application service. Conversely, application checks alone cannot bypass the PostgreSQL identity/version/audit/scope protections.

### Conflicts explicitly avoided

- `ModuleAvailability::enabled()` requires a `Location` and `ModuleDefinition`; the existing catalog is location-typed. Treating the organizational capability as a location module or setting `RAW_MATERIALS.ready=true` would violate Stage 1E scope and could expose ordinary RAW routes.
- The existing module configuration API is an administrator-only, password-confirmed mutation, not evidence that BOSS owns module configuration rights. The new endpoint has its own organization-scoped state and fixed key; it does not use a BOSS role check or a grant.
- `BusinessVisibility::decideBoss` currently accepts a restricted set of permission/source/target shapes and ordinary RAW permissions are checked by `RawMaterialAuthorization`. 3A must not widen either. A later 3B contract will require a dedicated BOSS-derived organizational catalog intent rather than disguising it as a RAW grant or location permission.
- Do not add an organization activation row to `location_module_states`, create one row for every location, use a fake central-kitchen target, alter `RequireRawMaterialsAvailability`, or weaken ordinary RAW authorization.

## 8. Required tests before any later activation deployment

- Code catalog contains only the approved key, organization scope and operations; production readiness remains `NOT_READY` in 3A; unknown keys fail closed.
- Missing state yields `DISABLED`, version `0`, unavailable; no seed, migration, module readiness, or BOSS grant auto-activates it.
- Enabling a not-ready capability is rejected and audited; disabling is safe; `RAW_MATERIALS` remains `NOT_READY` and ordinary RAW routes retain their existing response.
- Only a current verified administrator with a valid real context, active membership in the same organization, and fresh password can activate/deactivate. A BOSS-only actor is denied regardless of independent RAW grants; a dual-role user may act only by satisfying the independent administrator checks. No submitted organization/location can redirect the operation.
- Wrong/stale password, rate limit, stale expected version, unknown capability, invalid context, revoked role/membership, and inactive identity/org all fail closed and are auditable through the applicable rejection path.
- PostgreSQL rejects attempts to change state identity, delete state, skip/repeat a version, update without toggling, or change state without a matching accepted attempt and transition in the same commit.
- PostgreSQL rejects duplicate operation/event-kind rows, a changed attempt without exactly one transition, a transition without exactly one matching attempt/state row, mismatched actor/context/state/version fields, and any update/delete to audit.
- Two concurrent requests for one organization serialize; stale versions conflict; idempotent request adds one attempt but no transition; rejected state mutation rolls back and the separate rejection audit persists. Force deferred-trigger/FK failure and audit-only insert failure; neither may report success or leave state changed.
- State/audit direct DML without the exact signed scope fails; configure scope cannot write audit and audit scope cannot mutate state; existing `module.configure`/`module.audit` scopes cannot write the new tables; new scopes cannot widen writes to existing module/RBAC/RAW tables. Verify runtime grants and absence of `DELETE`/`TRUNCATE`.
- Authentication failures before service entry create no activation-attempt row; downstream middleware rejections create only their generic security event; validated service-level rejection creates the capability attempt and, when downstream exception handling applies, a separate generic security event.
- Independent RAW authorization behavior is unchanged by BOSS activation or restrictions; BOSS role never yields ordinary RAW permissions.

The final positive BOSS read test belongs to 3B and cannot pass until a separately approved read API exists. Tests must use isolated fixtures/test readiness only; no production or STAGING activation.

## 9. Review decisions required before migration

Please approve or amend:

1. The exact two-table schema, including `last_transition_event_id`, the PostgreSQL state guard and deferred pair/state audit triggers, and append-only audit enforcement.
2. The operation semantics: server-generated operation IDs unique by event kind, exactly one attempt, transition iff an accepted state change, no transition for rejection/idempotence, and atomic state/accepted-audit commit.
3. The rejection/audit-failure boundaries and the separation between generic `security_events` and service-level capability attempts.
4. The three narrow signed scopes, runtime-table allowlist additions, and exact least-privilege grants without changing existing Stage 1E scopes/permissions.
5. Route placement under `/api/v1/security`, no administrator middleware ahead of the mutation service, administrator-only read/history, fresh `current_password`, and `expected_state_version` concurrency contract.
6. The readiness rule: 3A stays `NOT_READY`; enabling is rejected until the separate 3B API is implemented, tested, and accepted. Code readiness never automatically sets organization activation.

**STOP:** No migrations, persistence, route, readiness provider, test fixture, activation, or runtime authorization changes will be made until this schema and API contract is formally reviewed and approved.
