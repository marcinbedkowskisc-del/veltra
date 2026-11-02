<?php

namespace App\Domain\RawMaterials;

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
        $freshActor = User::with('organization')->find($actor->id);
        $freshContext = Location::find($context->id);

        if (! $freshActor || ! $freshContext || ! $freshActor->is_active || ! $freshContext->is_active
            || $freshActor->email_verified_at === null || ! $freshActor->organization->is_active
            || $freshContext->organization_id !== $freshActor->organization_id
            || ! app(MembershipService::class)->available($freshActor)->where('location_id', $freshContext->id)->exists()) {
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
    }

    public function hasEffectiveGrant(User $user, RawMaterialPermission $permission): bool
    {
        return $this->effectiveGrantIds($user, $permission) !== [];
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
                    SELECT grants.id, grants.organization_id, grants.user_id, grants.permission, grants.delegation_source_id
                    FROM organization_permission_grants grants
                    WHERE grants.organization_id = ?
                        AND grants.delegation_source_id IS NULL
                        AND grants.valid_from <= statement_timestamp()
                        AND (grants.valid_until IS NULL OR grants.valid_until > statement_timestamp())
                        AND NOT EXISTS (
                            SELECT 1 FROM organization_permission_grant_events events
                            WHERE events.grant_id = grants.id AND events.event_type = 'REVOKED'
                        )
                    UNION ALL
                    SELECT child.id, child.organization_id, child.user_id, child.permission, child.delegation_source_id
                    FROM organization_permission_grants child
                    JOIN effective_grants parent ON child.delegation_source_id = parent.id
                    WHERE child.valid_from <= statement_timestamp()
                        AND (child.valid_until IS NULL OR child.valid_until > statement_timestamp())
                        AND NOT EXISTS (
                            SELECT 1 FROM organization_permission_grant_events events
                            WHERE events.grant_id = child.id AND events.event_type = 'REVOKED'
                        )
                )
                SELECT id FROM effective_grants
                WHERE user_id = ? AND permission = ?
                ORDER BY id
                SQL, [$user->organization_id, $user->id, $permission->value]),
        );
    }
}
