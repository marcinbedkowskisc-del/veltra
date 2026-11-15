<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\PermissionGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PermissionAdministration
{
    public function __construct(private BusinessAuthorization $authorization, private SecurityMutation $mutations) {}

    public function grant(User $actor, Location $context, User $target, Location $location, Permission $permission, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): PermissionGrant
    {
        return $this->mutations->run($actor, $target, $location, 'permission.grant.'.$permission->value, function () use ($actor, $context, $target, $location, $permission, $from, $until): PermissionGrant {
            if ($actor->is($target)) {
                throw new AuthorizationException;
            }
            $this->authorize($actor, $context, $target, $location, $permission);
            $membership = app(MembershipService::class)->available($target)->where('location_id', $location->id)->first();
            if (! $membership || ! $target->fresh()->is_active) {
                throw new BusinessRuleViolation(__('messages.active_target_membership_required'));
            }
            $from ??= CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()'));
            if ($until !== null && $until->lessThanOrEqualTo($from)) {
                throw new BusinessRuleViolation(__('messages.business_end_after_start'));
            }
            $grant = PermissionGrant::create([
                'organization_id' => $actor->organization_id, 'user_id' => $target->id,
                'location_id' => $location->id, 'membership_id' => $membership->id,
                'permission' => $permission, 'granted_by' => $actor->id, 'valid_from' => $from, 'valid_until' => $until,
            ]);
            if (count($this->periods($target, $location, $permission)) < PermissionGrant::where('user_id', $target->id)->where('location_id', $location->id)->where('permission', $permission)->whereNull('revoked_at')->count()) {
                $this->mutations->record($actor, $target, $location, 'permission.merge.'.$permission->value, 'accepted', $grant->id);
            }

            return $grant;
        });
    }

    public function revokeEffective(User $actor, Location $context, User $target, Location $location, Permission $permission): void
    {
        $this->mutations->run($actor, $target, $location, 'permission.revoke.'.$permission->value, function () use ($actor, $context, $target, $location, $permission): void {
            $this->authorize($actor, $context, $target, $location, $permission);
            PermissionGrant::where('user_id', $target->id)->where('location_id', $location->id)->where('permission', $permission)
                ->whereNull('revoked_at')->update(['revoked_at' => DB::scalar('SELECT clock_timestamp()'), 'revoked_by' => $actor->id]);
        });
    }

    public function periods(User $target, Location $location, Permission $permission): array
    {
        return DB::select(<<<'SQL'
            SELECT lower(period) AS valid_from, upper(period) AS valid_until
            FROM unnest((SELECT range_agg(tstzrange(g.valid_from,g.valid_until,'[)'))
                FROM permission_grants g JOIN location_memberships m ON m.id=g.membership_id
                WHERE g.user_id=? AND g.location_id=? AND g.permission=? AND g.revoked_at IS NULL AND m.is_active)) AS period
            ORDER BY lower(period)
            SQL, [$target->id, $location->id, $permission->value]);
    }

    private function authorize(User $actor, Location $context, User $target, Location $location, Permission $permission): void
    {
        if (! $this->authorization->eligible($actor, $context, $location) || $target->organization_id !== $actor->organization_id
            || ! $this->authorization->canManageAccount($actor, $target)
            || $location->type !== LocationType::Restaurant || (! $permission->special() && $permission->module() === null)) {
            throw new AuthorizationException;
        }
        $supervisor = $this->authorization->hasRole($actor, BusinessRole::Administrator) || $this->authorization->manages($actor, $context, $location);
        if ($permission->special()) {
            if (! $supervisor) {
                throw new AuthorizationException;
            }

            return;
        }
        if (! $supervisor && (! $context->is($location) || ! $this->authorization->hasRole($actor, BusinessRole::RestaurantManager, $location))) {
            throw new AuthorizationException;
        }
        if (! $this->authorization->hasRole($target, BusinessRole::Employee, $location)
            || ! app(ModuleAvailability::class)->enabled($location, $permission->module())) {
            throw new AuthorizationException(__('messages.employee_role_module_required'));
        }
    }
}
