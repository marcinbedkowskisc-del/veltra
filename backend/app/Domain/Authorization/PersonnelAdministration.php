<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\MembershipRequest;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonnelAdministration
{
    public function __construct(private BusinessAuthorization $authorization, private SecurityMutation $mutations, private RoleAdministration $roles) {}

    public function scopedUsers(User $actor, Location $context, Location $location): Builder
    {
        $this->authorizeLocation($actor, $context, $location);

        return User::where('organization_id', $actor->organization_id)
            ->whereHas('memberships', fn (Builder $q) => $q->where('location_id', $location->id)->where('is_active', true));
    }

    public function requestPersonnel(User $actor, Location $context, Location $location, array $profile): ?User
    {
        $profile['email'] = User::normalizeEmail($profile['email'] ?? '');
        validator($profile, [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
        ])->validate();

        return $this->mutations->run($actor, $actor, $location, 'personnel.request', function () use ($actor, $context, $location, $profile): ?User {
            $this->authorizeLocation($actor, $context, $location);
            $target = User::where('email', $profile['email'])->first();
            if ($target) {
                if ($target->organization_id !== $actor->organization_id) {
                    throw new AuthorizationException;
                }
                if (! app(MembershipService::class)->available($target)->where('location_id', $location->id)->exists()) {
                    $request = MembershipRequest::firstOrCreate(
                        ['user_id' => $target->id, 'location_id' => $location->id, 'decision' => null],
                        ['organization_id' => $actor->organization_id, 'requested_by' => $actor->id],
                    );
                    $this->mutations->record($actor, $target, $location, 'membership.request', 'accepted', $request->id);
                }

                return null;
            }
            $target = User::create([
                'organization_id' => $actor->organization_id,
                'first_name' => trim($profile['first_name']), 'last_name' => trim($profile['last_name']),
                'email' => $profile['email'], 'password' => Str::password(48), 'is_active' => true,
            ]);
            app(MembershipService::class)->assign($target, $location);
            if ($location->type === LocationType::Restaurant) {
                $this->roles->grant($actor, $context, $target, BusinessRole::Employee, $location);
            }
            $this->mutations->record($actor, $target, $location, 'account.create', 'accepted');

            return $target;
        });
    }

    public function decide(User $actor, Location $context, MembershipRequest $request, bool $approve): void
    {
        $target = User::findOrFail($request->user_id);
        $location = Location::findOrFail($request->location_id);
        $this->mutations->run($actor, $target, $location, 'membership.'.($approve ? 'approve' : 'reject'), function () use ($actor, $context, $target, $location, $request, $approve): void {
            if (! $this->authorization->eligible($actor, $context, $location)
                || ! ($this->authorization->hasRole($actor, BusinessRole::Administrator)
                    || ($location->type === LocationType::Restaurant && $this->authorization->manages($actor, $context, $location)))) {
                throw new AuthorizationException;
            }
            $request = $request->fresh();
            if ($request->decision !== null) {
                throw new BusinessRuleViolation('This membership request has already been decided.');
            }
            if ($approve) {
                if ($location->type === LocationType::Restaurant && ! $this->roles->canAdminister($actor, $context, $target, BusinessRole::Employee, $location)) {
                    throw new AuthorizationException;
                }
                if (! app(MembershipService::class)->available($target)->where('location_id', $location->id)->exists()) {
                    app(MembershipService::class)->assign($target, $location);
                }
                if ($location->type === LocationType::Restaurant) {
                    $this->roles->grant($actor, $context, $target, BusinessRole::Employee, $location);
                }
            }

            $request->update(['decision' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => DB::scalar('SELECT clock_timestamp()')]);
        });
    }

    public function assignMembership(User $actor, Location $context, User $target, Location $location): LocationMembership
    {
        return $this->mutations->run($actor, $target, $location, 'membership.assign', function () use ($actor, $context, $target, $location): LocationMembership {
            if (! $this->authorization->eligible($actor, $context, $location)
                || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)
                || $actor->organization_id !== $target->organization_id) {
                throw new AuthorizationException;
            }

            return app(MembershipService::class)->assign($target, $location);
        });
    }

    public function removeMembership(User $actor, Location $context, LocationMembership $membership): void
    {
        $target = User::findOrFail($membership->user_id);
        $location = Location::findOrFail($membership->location_id);
        $this->mutations->run($actor, $target, $location, 'membership.remove', function () use ($actor, $context, $target, $location, $membership): void {
            if (! $this->authorization->eligible($actor, $context, $location)) {
                throw new AuthorizationException;
            }
            $administrator = $this->authorization->hasRole($actor, BusinessRole::Administrator);
            if (! $administrator) {
                $this->authorizeLocation($actor, $context, $location);
                $protected = $this->authorization->manages($actor, $context, $location)
                    ? [BusinessRole::Administrator, BusinessRole::Boss, BusinessRole::CentralKitchenChef]
                    : array_filter(BusinessRole::cases(), fn (BusinessRole $role) => $role->protectedPersonnel());
                if (RoleAssignment::effective()->where('user_id', $target->id)->whereIn('role', $protected)->exists()) {
                    throw new AuthorizationException;
                }
            }
            $this->roles->requireUsableAdministrator($membership->id);
            $at = DB::scalar('SELECT clock_timestamp()');
            RoleAssignment::where('membership_id', $membership->id)->whereNull('revoked_at')->update(['revoked_at' => $at, 'revoked_by' => $actor->id]);
            app(MembershipService::class)->deactivate($membership, 'Access removed by an authorized supervisor.');
        });
    }

    private function authorizeLocation(User $actor, Location $context, Location $location): void
    {
        if ($location->type === LocationType::CentralKitchen && $this->authorization->eligible($actor, $context, $location)
            && $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            return;
        }
        if ($location->type !== LocationType::Restaurant
            || ! $this->authorization->allows($actor, $context, $location, Permission::PersonnelManage)) {
            throw new AuthorizationException;
        }
    }
}
