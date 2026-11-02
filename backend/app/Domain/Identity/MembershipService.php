<?php

namespace App\Domain\Identity;

use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function assign(User $user, Location $location): LocationMembership
    {
        return DB::transaction(function () use ($user, $location): LocationMembership {
            $organization = Organization::lockForUpdate()->firstOrFail();
            $user = $user->fresh();
            $location = $location->fresh();
            if (! $user || ! $location || $user->organization_id !== $organization->id
                || $location->organization_id !== $organization->id) {
                throw new BusinessRuleViolation('Membership must belong to the installation organization.');
            }
            if (! $user->is_active || ! $location->is_active || ! $organization->is_active) {
                throw new BusinessRuleViolation('An active membership requires an active user, location and organization.');
            }
            if ($user->memberships()->where('location_id', $location->id)->where('is_active', true)->exists()) {
                throw new BusinessRuleViolation('This user already has an active membership at this location.');
            }

            return LocationMembership::create([
                'organization_id' => $organization->id, 'user_id' => $user->id,
                'location_id' => $location->id, 'is_active' => true,
                'assigned_at' => DB::scalar('SELECT clock_timestamp()'),
            ]);
        });
    }

    public function deactivate(LocationMembership $membership, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:250']])->validate();
        DB::transaction(function () use ($membership, $reason): void {
            Organization::lockForUpdate()->firstOrFail();
            $membership = LocationMembership::findOrFail($membership->id);
            if ($membership->is_active) {
                $membership->update([
                    'is_active' => false,
                    'deactivated_at' => DB::scalar('SELECT clock_timestamp()'),
                    'deactivation_reason' => $reason,
                ]);
            }
        });
    }

    public function available(User $user): Builder
    {
        return LocationMembership::query()->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)->where('is_active', true)
            ->whereHas('location', fn (Builder $query) => $query->where('is_active', true)->where('organization_id', $user->organization_id));
    }
}
