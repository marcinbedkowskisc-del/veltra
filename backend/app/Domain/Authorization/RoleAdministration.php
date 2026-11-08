<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

class RoleAdministration
{
    public function __construct(private BusinessAuthorization $authorization, private SecurityMutation $mutations) {}

    public function canAdminister(User $actor, Location $context, User $target, BusinessRole $role, ?Location $location): bool
    {
        if (! $this->authorization->eligible($actor, $context, $location)
            || $target->organization_id !== $actor->organization_id
            || ! $this->authorization->canManageAccount($actor, $target)) {
            return false;
        }
        if ($this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            return $role !== BusinessRole::Administrator || $this->authorization->isSuperAdmin($actor);
        }
        if ($role->organizationWide() || $role === BusinessRole::CentralKitchenChef
            || ! $location || $location->type !== LocationType::Restaurant) {
            return false;
        }
        $protected = RoleAssignment::effective()->where('user_id', $target->id)->whereIn('role', [
            BusinessRole::Administrator, BusinessRole::Boss, BusinessRole::CentralKitchenChef,
        ])->exists();
        if ($protected) {
            return false;
        }
        if ($this->authorization->manages($actor, $context, $location)) {
            return true;
        }

        return $context->is($location) && $this->authorization->hasRole($actor, BusinessRole::RestaurantManager, $location)
            && in_array($role, [BusinessRole::Employee, BusinessRole::RestaurantChef], true)
            && ! RoleAssignment::effective()->where('user_id', $target->id)->where('role', BusinessRole::RestaurantManager)->exists();
    }

    public function grant(User $actor, Location $context, User $target, BusinessRole $role, ?Location $location, #[SensitiveParameter] ?string $password = null): RoleAssignment
    {
        return $this->mutations->run($actor, $target, $location, 'role.grant.'.$role->value, function () use ($actor, $context, $target, $role, $location, $password): RoleAssignment {
            $this->authorize($actor, $context, $target, $role, $location, $password);
            if (! $target->fresh()->is_active) {
                throw new BusinessRuleViolation(__('messages.active_target_account_required'));
            }
            $membership = $location ? app(MembershipService::class)->available($target)->where('location_id', $location->id)->first() : null;
            if ($location && ! $membership) {
                throw new BusinessRuleViolation(__('messages.active_target_membership_required'));
            }
            if (RoleAssignment::where('user_id', $target->id)->where('role', $role)->where('location_id', $location?->id)->whereNull('revoked_at')->exists()) {
                throw new BusinessRuleViolation(__('messages.already_role_assigned'));
            }

            return RoleAssignment::create([
                'organization_id' => $actor->organization_id, 'user_id' => $target->id, 'role' => $role,
                'location_id' => $location?->id, 'membership_id' => $membership?->id, 'granted_by' => $actor->id,
            ]);
        });
    }

    public function revoke(User $actor, Location $context, RoleAssignment $assignment, #[SensitiveParameter] ?string $password = null): void
    {
        $target = User::findOrFail($assignment->user_id);
        $location = $assignment->location_id ? Location::findOrFail($assignment->location_id) : null;
        $this->mutations->run($actor, $target, $location, 'role.revoke.'.$assignment->role->value, function () use ($actor, $context, $target, $location, $assignment, $password): void {
            $this->authorize($actor, $context, $target, $assignment->role, $location, $password);
            $assignment = $assignment->fresh();
            if ($assignment->revoked_at !== null) {
                throw new BusinessRuleViolation(__('messages.already_revoked'));
            }
            if ($target->is_initial_identity && $assignment->role === BusinessRole::Administrator) {
                throw new AuthorizationException;
            }
            if ($assignment->role === BusinessRole::Administrator) {
                $this->requireOtherAdministrator($target);
            }
            $assignment->update(['revoked_at' => DB::scalar('SELECT clock_timestamp()'), 'revoked_by' => $actor->id]);
        });
    }

    public function setAccountActive(User $actor, Location $context, User $target, bool $active): void
    {
        $this->mutations->run($actor, $target, null, $active ? 'account.reactivate' : 'account.deactivate', function () use ($actor, $context, $target, $active): void {
            if (! $this->authorization->eligible($actor, $context) || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)
                || $actor->organization_id !== $target->organization_id
                || ! $this->authorization->canManageAccount($actor, $target)
                || ($target->is_initial_identity && ! $active)) {
                throw new AuthorizationException;
            }
            if (! $active) {
                $this->requireOtherAdministrator($target);
            }
            $target->update(['is_active' => $active]);
        });
    }

    public function changeEmail(User $actor, Location $context, User $target, string $email): void
    {
        $email = User::normalizeEmail($email);
        validator(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254']])->validate();
        $this->mutations->run($actor, $target, null, 'account.email_change', function () use ($actor, $context, $target, $email): void {
            if (! $this->authorization->eligible($actor, $context) || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)
                || $actor->organization_id !== $target->organization_id || $actor->is($target)
                || ! $this->authorization->canManageAccount($actor, $target)) {
                throw new AuthorizationException(__('messages.account_email_change_approval'));
            }
            if ($target->fresh()->email !== $email) {
                $this->requireOtherAdministrator($target);
                $target->update(['email' => $email]);
            }
        });
    }

    public function bootstrapInitial(User $user): RoleAssignment
    {
        return app(AuthorizationMutationContext::class)->run(AuthorizationMutationScope::administratorBootstrap(), $user->id, fn (): RoleAssignment => DB::transaction(function () use ($user): RoleAssignment {
            $organization = Organization::whereKey($user->organization_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $user->is_initial_identity || ! $user->is_active || $organization->authorization_initialized
                || RoleAssignment::where('role', BusinessRole::Administrator)->exists()) {
                throw new BusinessRuleViolation(__('messages.initial_authorization_unavailable'));
            }
            if ($user->email_verified_at !== null
                && ! app(MembershipService::class)->available($user)->exists()) {
                throw new BusinessRuleViolation(__('messages.initial_administrator_membership_required'));
            }
            $role = RoleAssignment::create(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'role' => BusinessRole::Administrator]);
            if ($user->email_verified_at !== null) {
                $organization->forceFill(['authorization_initialized' => true])->save();
            }
            $this->mutations->record(null, $user, null, 'administrator.bootstrap', 'accepted', $role->id);

            return $role;
        }));
    }

    public function requireOtherAdministrator(User $target): void
    {
        if (RoleAssignment::where('user_id', $target->id)->where('role', BusinessRole::Administrator)->whereNull('revoked_at')->exists()
            && ! RoleAssignment::where('organization_id', $target->organization_id)->where('role', BusinessRole::Administrator)
                ->whereNull('revoked_at')->where('user_id', '<>', $target->id)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('location_memberships as m')->join('locations as l', 'l.id', '=', 'm.location_id')
                    ->whereColumn('m.user_id', 'role_assignments.user_id')->where('m.is_active', true)->where('l.is_active', true))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.id', 'role_assignments.user_id')->where('is_active', true)->whereNotNull('email_verified_at'))->exists()) {
            throw new BusinessRuleViolation(__('messages.administrator_must_remain'));
        }
    }

    public function requireUsableAdministrator(?string $removedMembership = null, ?string $disabledLocation = null): void
    {
        if (! Organization::where('authorization_initialized', true)->exists()) {
            return;
        }
        $query = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('location_memberships as m', 'm.user_id', '=', 'u.id')->join('locations as l', 'l.id', '=', 'm.location_id')
            ->where('r.role', BusinessRole::Administrator->value)->whereNull('r.revoked_at')
            ->where('u.is_active', true)->whereNotNull('u.email_verified_at')->where('m.is_active', true)->where('l.is_active', true);
        if ($removedMembership) {
            $query->where('m.id', '<>', $removedMembership);
        }
        if ($disabledLocation) {
            $query->where('l.id', '<>', $disabledLocation);
        }
        if (! $query->exists()) {
            throw new BusinessRuleViolation(__('messages.administrator_operating_membership_must_remain'));
        }
    }

    private function authorize(User $actor, Location $context, User $target, BusinessRole $role, ?Location $location, #[SensitiveParameter] ?string $password): void
    {
        if ($role->organizationWide() !== ($location === null) || ! $this->canAdminister($actor, $context, $target, $role, $location)) {
            throw new AuthorizationException;
        }
        if ($role === BusinessRole::Administrator) {
            $key = 'administrator-confirm:'.$actor->id.'|'.request()->ip();
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw new AuthorizationException(__('messages.fresh_password_confirmation_rate_limited'));
            }
            RateLimiter::hit($key, 60);
            if (! is_string($password) || ! Hash::check($password, $actor->fresh()->password)) {
                throw new AuthorizationException(__('messages.fresh_password_confirmation_required'));
            }
        }
    }
}
