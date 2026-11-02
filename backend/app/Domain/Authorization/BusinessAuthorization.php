<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\PermissionGrant;
use App\Models\RoleAssignment;
use App\Models\User;

class BusinessAuthorization
{
    public function eligible(User $actor, Location $context, ?Location $target = null): bool
    {
        $actor = $actor->fresh();
        $context = $context->fresh();
        $target = $target === null ? $context : $target->fresh();

        return $actor && $context && $target && $actor->is_active && $actor->email_verified_at !== null
            && $actor->organization->is_active && $context->is_active && $target->is_active
            && $context->organization_id === $actor->organization_id && $target->organization_id === $actor->organization_id
            && app(MembershipService::class)->available($actor)->where('location_id', $context->id)->exists();
    }

    public function hasRole(User $user, BusinessRole $role, ?Location $location = null): bool
    {
        return RoleAssignment::effective()->where('user_id', $user->id)->where('organization_id', $user->organization_id)
            ->where('role', $role)->where('location_id', $location?->id)->exists();
    }

    public function manages(User $actor, Location $context, Location $target): bool
    {
        if ($target->type === LocationType::CentralKitchen) {
            return $context->is($target) && $this->hasRole($actor, BusinessRole::CentralKitchenChef, $target);
        }
        $kitchen = $target->managementAssignments()->current()->with('managingKitchen')->first()?->managingKitchen;

        return $kitchen && $kitchen->is_active && ($context->is($kitchen) || $context->is($target))
            && $this->hasRole($actor, BusinessRole::CentralKitchenChef, $kitchen);
    }

    public function allows(User $actor, Location $context, Location $target, Permission $permission): bool
    {
        if (! $this->eligible($actor, $context, $target)) {
            return false;
        }
        if ($permission->module() !== null && ($target->type !== LocationType::Restaurant
            || ! app(ModuleAvailability::class)->enabled($target, $permission->module()))) {
            return false;
        }
        if ($this->hasRole($actor, BusinessRole::Administrator)) {
            return true;
        }
        if ($this->manages($actor, $context, $target)) {
            return $permission !== Permission::StructureManage;
        }
        if ($target->type !== LocationType::Restaurant) {
            return false;
        }
        if ($this->hasRole($actor, BusinessRole::Boss)
            && in_array($permission, [Permission::LocationView, Permission::ReportsView, Permission::ReportsExport], true)) {
            return true;
        }
        if (! $context->is($target)) {
            return false;
        }
        $manager = $this->hasRole($actor, BusinessRole::RestaurantManager, $target);
        $chef = $this->hasRole($actor, BusinessRole::RestaurantChef, $target);
        $employee = $this->hasRole($actor, BusinessRole::Employee, $target);

        return match ($permission) {
            Permission::LocationView => $manager || $chef || $employee,
            Permission::PersonnelManage => $manager,
            Permission::RecipesView, Permission::QuantityCalculate => $chef,
            default => ($permission->special() || ($employee && $permission->module() !== null))
                && PermissionGrant::where('user_id', $actor->id)->where('location_id', $target->id)->where('permission', $permission)
                    ->whereNull('revoked_at')->whereRaw('valid_from <= statement_timestamp()')
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhereRaw('valid_until > statement_timestamp()'))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('location_memberships as m')
                        ->whereColumn('m.id', 'permission_grants.membership_id')->where('m.is_active', true))->exists(),
        };
    }

    public function canExport(User $actor, Location $context, Location $target, Permission $source): bool
    {
        return $this->allows($actor, $context, $target, Permission::ReportsView)
            && $this->allows($actor, $context, $target, Permission::ReportsExport)
            && $this->allows($actor, $context, $target, $source);
    }
}
