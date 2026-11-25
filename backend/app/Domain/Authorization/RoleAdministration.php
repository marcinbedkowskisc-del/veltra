<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocationType;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PermissionGrant;
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
            return true;
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
            if ($actor->is($target)) {
                throw new AuthorizationException;
            }
            $this->authorize($actor, $context, $target, $role, $location, $password);
            if (! $target->fresh()->is_active && ! $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
                throw new BusinessRuleViolation(__('messages.active_target_account_required'));
            }
            $membership = $location ? app(MembershipService::class)->available($target)->where('location_id', $location->id)->first() : null;
            if ($location && ! $membership) {
                throw new BusinessRuleViolation(__('messages.active_target_membership_required'));
            }
            if (RoleAssignment::where('user_id', $target->id)->whereNull('revoked_at')
                ->where('role', '<>', $role->value)->exists()) {
                throw new BusinessRuleViolation(__('messages.business_role_replacement_required'));
            }
            if (RoleAssignment::where('user_id', $target->id)->where('role', $role)->where('location_id', $location?->id)->whereNull('revoked_at')->exists()) {
                throw new BusinessRuleViolation(__('messages.already_role_assigned'));
            }

            $assignment = RoleAssignment::create([
                'organization_id' => $actor->organization_id, 'user_id' => $target->id, 'role' => $role,
                'location_id' => $location?->id, 'membership_id' => $membership?->id, 'granted_by' => $actor->id,
            ]);
            if ($role === BusinessRole::Boss) {
                app(OrganizationRawMaterialPermissionAdministration::class)
                    ->revokeWritesForBossRoleReplacement($actor, $context, $target);
            }

            return $assignment;
        });
    }

    public function revoke(User $actor, Location $context, RoleAssignment $assignment, #[SensitiveParameter] ?string $password = null): void
    {
        $this->revokeAssignment($actor, $context, $assignment, $password, false);
    }

    private function revokeAssignment(User $actor, Location $context, RoleAssignment $assignment, #[SensitiveParameter] ?string $password, bool $replacing): void
    {
        $target = User::findOrFail($assignment->user_id);
        $location = $assignment->location_id ? Location::findOrFail($assignment->location_id) : null;
        $this->mutations->run($actor, $target, $location, 'role.revoke.'.$assignment->role->value, function () use ($actor, $context, $target, $location, $assignment, $password, $replacing): void {
            $this->authorize($actor, $context, $target, $assignment->role, $location, $password);
            $assignment = $assignment->fresh();
            if ($assignment->revoked_at !== null) {
                throw new BusinessRuleViolation(__('messages.already_revoked'));
            }
            if ($assignment->role === BusinessRole::Administrator) {
                $this->requireOtherAdministrator($target);
            }
            if (! $replacing && $target->is_active && $target->email_verified_at !== null
                && ! RoleAssignment::effective()->where('user_id', $target->id)->where('id', '<>', $assignment->id)->exists()) {
                throw new BusinessRuleViolation(__('messages.business_role_must_remain'));
            }
            $assignment->update(['revoked_at' => DB::scalar('SELECT clock_timestamp()'), 'revoked_by' => $actor->id]);
        });
    }

    /**
     * @param  list<string>  $locationIds
     * @return list<RoleAssignment>
     */
    public function replace(User $actor, Location $context, User $target, BusinessRole $role, array $locationIds, #[SensitiveParameter] ?string $password = null): array
    {
        return $this->mutations->run($actor, $target, null, 'role.replace', function () use ($actor, $context, $target, $role, $locationIds, $password): array {
            if ($actor->is($target) || ! $this->authorization->eligible($actor, $context)
                || $actor->organization_id !== $target->organization_id
                || ! $this->authorization->canManageAccount($actor, $target)) {
                throw new AuthorizationException;
            }
            $assignments = RoleAssignment::where('user_id', $target->id)->whereNull('revoked_at')->get();
            $oldRoles = $assignments->map(fn (RoleAssignment $assignment): string => $assignment->role->value)->unique();
            if ($oldRoles->count() !== 1 || $oldRoles->first() === $role->value
                || count($locationIds) !== count(array_unique($locationIds))
                || ($role->organizationWide() ? $locationIds !== [] : $locationIds === [])) {
                throw new BusinessRuleViolation(__('messages.business_role_replacement_invalid'));
            }
            $locations = Location::where('organization_id', $target->organization_id)
                ->whereIn('id', $locationIds)->get()->keyBy('id');
            if ($locations->count() !== count($locationIds)) {
                throw new BusinessRuleViolation(__('messages.business_role_replacement_invalid'));
            }
            foreach ($locations as $location) {
                $expectedType = $role === BusinessRole::CentralKitchenChef ? LocationType::CentralKitchen : LocationType::Restaurant;
                if ($location->type !== $expectedType
                    || ! app(MembershipService::class)->available($target)->where('location_id', $location->id)->exists()) {
                    throw new BusinessRuleViolation(__('messages.business_role_replacement_invalid'));
                }
                if (! $this->canAdminister($actor, $context, $target, $role, $location)) {
                    throw new AuthorizationException;
                }
            }
            if ($role->organizationWide() && ! $this->canAdminister($actor, $context, $target, $role, null)) {
                throw new AuthorizationException;
            }

            foreach ($assignments as $assignment) {
                $this->revokeAssignment($actor, $context, $assignment, $password, true);
            }
            $created = [];
            if ($role->organizationWide()) {
                $created[] = $this->grant($actor, $context, $target, $role, null, $password);
            } else {
                foreach ($locationIds as $locationId) {
                    $created[] = $this->grant($actor, $context, $target, $role, $locations[$locationId], $password);
                }
            }

            $grants = PermissionGrant::where('user_id', $target->id)->whereNull('revoked_at')->get();
            foreach ($grants as $grant) {
                if ($grant->permission->special()
                    || ($role === BusinessRole::Employee && in_array($grant->location_id, $locationIds, true))) {
                    continue;
                }
                app(AuthorizationMutationContext::class)->run(
                    AuthorizationMutationScope::permissionRevoke($grant->permission),
                    $actor->id,
                    fn () => $grant->update(['revoked_at' => DB::scalar('SELECT clock_timestamp()'), 'revoked_by' => $actor->id]),
                );
            }

            return $created;
        });
    }

    public function setAccountActive(User $actor, Location $context, User $target, bool $active): void
    {
        $this->mutations->run($actor, $target, null, $active ? 'account.reactivate' : 'account.deactivate', function () use ($actor, $context, $target, $active): void {
            if (! $this->authorization->eligible($actor, $context) || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)
                || $actor->organization_id !== $target->organization_id
                || ! $this->authorization->canManageAccount($actor, $target)) {
                throw new AuthorizationException;
            }
            if (! $active) {
                $this->requireOtherAdministrator($target);
            } elseif ($target->email_verified_at !== null && ! $this->authorization->hasSingleEffectiveRole($target)) {
                throw new BusinessRuleViolation(__('messages.business_role_must_remain'));
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
