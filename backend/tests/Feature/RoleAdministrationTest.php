<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\RoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationTestCase;

class RoleAdministrationTest extends AuthorizationTestCase
{
    public function test_admin_grants_require_fresh_password_and_rejections_are_durable(): void
    {
        $actor = app(IdentityService::class)->provisionInitial(['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'admin@example.test'], self::PASSWORD);
        $actor->forceFill(['email_verified_at' => now()])->save();
        $roles = app(RoleAdministration::class);
        $roles->bootstrapInitial($actor);
        $context = $this->kitchen();
        app(MembershipService::class)->assign($actor, $context);
        $other = $this->user();
        $other->forceFill(['email_verified_at' => now()])->save();
        try {
            $roles->grant($actor, $context, $other, BusinessRole::Administrator, null, 'wrong');
            $this->fail('Fresh password confirmation was bypassed.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('security_events')->where('outcome', 'rejected')->count());
        }
        $second = $roles->grant($actor, $context, $other, BusinessRole::Administrator, null, self::PASSWORD);
        app(MembershipService::class)->assign($other, $context);
        $roles->revoke($actor, $context, RoleAssignment::where('user_id', $actor->id)->firstOrFail(), self::PASSWORD);
        $this->assertNotNull(RoleAssignment::where('user_id', $actor->id)->firstOrFail()->revoked_at);
        $this->assertNull($second->fresh()->revoked_at);
        $this->assertDatabaseRejects('23514', fn () => DB::table('security_events')->update(['outcome' => 'accepted']));
    }

    public function test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql(): void
    {
        $actor = $this->user();
        $actor->forceFill(['email_verified_at' => now()])->save();
        $role = $this->role($actor, BusinessRole::Administrator);
        $actor->organization->forceFill(['authorization_initialized' => true])->save();
        $context = $this->kitchen();
        app(MembershipService::class)->assign($actor, $context);
        $roles = app(RoleAdministration::class);
        foreach ([
            fn () => $roles->revoke($actor, $context, $role, self::PASSWORD),
            fn () => $roles->setAccountActive($actor, $context, $actor, false),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('The last administrator was removed.');
            } catch (BusinessRuleViolation) {
                $this->assertTrue($actor->fresh()->is_active);
                $this->assertNull($role->fresh()->revoked_at);
            }
        }
        $this->assertDatabaseRejects('23514', fn () => $actor->update(['is_active' => false]));
        $this->assertDatabaseRejects('23514', fn () => $role->update(['revoked_at' => now()]));
        $this->assertDatabaseRejects('23514', fn () => $actor->organization->forceFill(['authorization_initialized' => false])->save());
        $membership = $actor->memberships()->firstOrFail();
        $this->assertDatabaseRejects('23514', fn () => app(MembershipService::class)->deactivate($membership, 'Attempted context removal'));
        $this->assertDatabaseRejects('23514', fn () => $context->update(['is_active' => false]));
    }

    public function test_manager_and_kitchen_hierarchy_and_protected_roles_across_locations(): void
    {
        $location = $this->activeRestaurant();
        $other = $this->activeRestaurant('R2');
        $manager = $this->user('manager@example.test');
        $manager->forceFill(['email_verified_at' => now()])->save();
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $worker = $this->user();
        app(MembershipService::class)->assign($worker, $location);
        $service = app(RoleAdministration::class);
        $employee = $service->grant($manager, $location, $worker, BusinessRole::Employee, $location);
        $this->assertSame(BusinessRole::Employee, $employee->role);
        $service->revoke($manager, $location, $employee);
        foreach ([BusinessRole::Administrator, BusinessRole::Boss, BusinessRole::CentralKitchenChef, BusinessRole::RestaurantManager] as $role) {
            $this->assertFalse($service->canAdminister($manager, $location, $worker, $role, $role->organizationWide() ? null : $location));
        }
        $this->role($worker, BusinessRole::RestaurantManager, $other);
        $this->assertFalse($service->canAdminister($manager, $location, $worker, BusinessRole::Employee, $location));
    }

    public function test_non_administrator_cannot_grant_global_administrator_through_domain_service(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->user('role-escalation-manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user('role-escalation-target@example.test');
        $before = RoleAssignment::where('organization_id', $manager->organization_id)
            ->where('role', BusinessRole::Administrator)
            ->whereNull('location_id')
            ->whereNull('revoked_at')
            ->count();

        try {
            app(RoleAdministration::class)->grant(
                $manager,
                $location,
                $target,
                BusinessRole::Administrator,
                null,
                self::PASSWORD,
            );
            $this->fail('A location manager received a global administrator assignment.');
        } catch (AuthorizationException) {
            $this->assertSame($before, RoleAssignment::where('organization_id', $manager->organization_id)
                ->where('role', BusinessRole::Administrator)
                ->whereNull('location_id')
                ->whereNull('revoked_at')
                ->count());
        }
    }
}
