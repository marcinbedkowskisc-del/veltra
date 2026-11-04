<?php

namespace App\Domain\Identity;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function __construct(private AuthorizationMutationContext $contexts) {}

    public function assign(User $user, Location $location): LocationMembership
    {
        return $this->contexts->run(AuthorizationMutationScope::membershipAssign(), null, fn (): LocationMembership => DB::transaction(function () use ($user, $location): LocationMembership {
            $organization = Organization::lockForUpdate()->firstOrFail();
            $user = $user->fresh();
            $location = $location->fresh();
            if (! $user || ! $location || $user->organization_id !== $organization->id
                || $location->organization_id !== $organization->id) {
                throw new BusinessRuleViolation(__('messages.membership_must_belong_to_organization'));
            }
            if (! $user->is_active || ! $location->is_active || ! $organization->is_active) {
                throw new BusinessRuleViolation(__('messages.membership_requires_active_entities'));
            }
            if ($user->memberships()->where('location_id', $location->id)->where('is_active', true)->exists()) {
                throw new BusinessRuleViolation(__('messages.active_membership_already_exists'));
            }

            return LocationMembership::create([
                'organization_id' => $organization->id, 'user_id' => $user->id,
                'location_id' => $location->id, 'is_active' => true,
                'assigned_at' => DB::scalar('SELECT clock_timestamp()'),
            ]);
        }));
    }

    public function deactivate(LocationMembership $membership, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:250']])->validate();
        $this->contexts->run(AuthorizationMutationScope::membershipDeactivate(), null, function () use ($membership, $reason): void {
            DB::transaction(function () use ($membership, $reason): void {
                $membership = LocationMembership::findOrFail($membership->id);
                Organization::whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
                $membership->refresh();
                if ($membership->is_active) {
                    $membership->update([
                        'is_active' => false,
                        'deactivated_at' => DB::scalar('SELECT clock_timestamp()'),
                        'deactivation_reason' => $reason,
                    ]);
                }
            });
        });
    }

    public function available(User $user): Builder
    {
        return LocationMembership::query()->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)->where('is_active', true)
            ->whereHas('location', fn (Builder $query) => $query->where('is_active', true)->where('organization_id', $user->organization_id));
    }
}
