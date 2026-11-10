<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RawMaterialAuthorization
{
    public function assertAllowed(User $actor, Location $context, RawMaterialPermission $permission): void
    {
        [$freshActor, $freshContext] = $this->resolveActorContext($actor, $context);

        if ($permission->requiresPrimaryCatalogContext()
            && ! app(RawMaterialLocationSharePolicy::class)->isPrimaryContext($freshContext)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        if (! app(ModuleAvailability::class)->enabled($freshContext, 'RAW_MATERIALS')) {
            throw new RawMaterialException(
                'RAW_MATERIALS_MODULE_UNAVAILABLE',
                __('messages.raw_materials_module_unavailable'),
                503,
            );
        }

        if (! $this->effectiveGrantIds($freshActor, $permission)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
        if ($permission === RawMaterialPermission::ReferencePriceManage
            && (! app(BusinessAuthorization::class)->hasRole($freshActor, BusinessRole::Administrator)
                || ! app(RawMaterialLocationSharePolicy::class)->isPrimaryContext($freshContext))) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
    }

    /**
     * @return array{permission: bool, context: bool}
     */
    public function evaluateCreateCapability(User $actor, Location $context): array
    {
        [$freshActor, $freshContext] = $this->resolveActorContext($actor, $context);

        return [
            'permission' => $this->hasEffectiveGrant($freshActor, RawMaterialPermission::Create),
            'context' => ! RawMaterialPermission::Create->requiresPrimaryCatalogContext()
                || app(RawMaterialLocationSharePolicy::class)->isPrimaryContext($freshContext),
        ];
    }

    public function hasEffectiveGrant(User $user, RawMaterialPermission $permission): bool
    {
        return $this->effectiveGrantIds($user, $permission) !== [];
    }

    /**
     * @return array{User, Location}
     */
    private function resolveActorContext(User $actor, Location $context): array
    {
        $freshActor = User::with('organization')->find($actor->id);
        $freshContext = Location::find($context->id);

        if (! $freshActor || ! $freshContext || ! $freshActor->is_active || ! $freshContext->is_active
            || $freshActor->email_verified_at === null || $freshActor->organization?->is_active !== true
            || $freshContext->organization_id !== $freshActor->organization_id
            || ! app(MembershipService::class)->available($freshActor)->where('location_id', $freshContext->id)->exists()) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        return [$freshActor, $freshContext];
    }

    /**
     * @return list<string>
     */
    public function effectiveGrantIds(User $user, RawMaterialPermission $permission): array
    {
        return array_map(
            fn (object $grant): string => $grant->id,
            DB::select(<<<'SQL'
                WITH RECURSIVE effective_grants AS (
                    SELECT grants.id, grants.organization_id, grants.user_id, grants.permission,
                        grants.delegation_source_id, grants.valid_from, grants.valid_until
                    FROM organization_permission_grants grants
                    JOIN organizations ON organizations.id = grants.organization_id
                    JOIN users recipient ON recipient.id = grants.user_id
                        AND recipient.organization_id = grants.organization_id
                    WHERE grants.organization_id = ?
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
                SELECT id FROM effective_grants
                WHERE user_id = ? AND permission = ?
                ORDER BY id
                SQL, [$user->organization_id, $user->id, $permission->value]),
        );
    }
}
