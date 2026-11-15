<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\RoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationTestCase;

class RoleAdministrationTest extends AuthorizationTestCase
{
    public function test_admin_grants_require_fresh_password_and_rejections_are_durable(): void
    {
        $actor = app(IdentityService::class)->provisionInitial(['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'admin@example.test'], self::PASSWORD);
        $actor = $this->updateFixture($actor, ['email_verified_at' => now()]);
        $roles = app(RoleAdministration::class);
        try {
            $roles->bootstrapInitial($actor);
            $this->fail('A verified initial administrator was activated without an operating membership.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame(__('messages.initial_administrator_membership_required'), $exception->getMessage());
        }
        $this->assertFalse($actor->organization->fresh()->authorization_initialized);
        $this->assertFalse(RoleAssignment::where('user_id', $actor->id)->where('role', BusinessRole::Administrator)->exists());

        $context = $this->kitchen();
        app(MembershipService::class)->assign($actor, $context);
        $roles->bootstrapInitial($actor);
        $this->assertTrue($actor->organization->fresh()->authorization_initialized);

        $other = $this->user();
        $other = $this->updateFixture($other, ['email_verified_at' => now()]);
        try {
            $roles->grant($actor, $context, $other, BusinessRole::Administrator, null, 'wrong');
            $this->fail('Fresh password confirmation was bypassed.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('security_events')->where('outcome', 'rejected')->count());
        }
        $second = $roles->grant($actor, $context, $other, BusinessRole::Administrator, null, self::PASSWORD);
        app(MembershipService::class)->assign($other, $context, $actor->id);
        $roles->revoke($actor, $context, $second, self::PASSWORD);
        $this->assertNull(RoleAssignment::where('user_id', $actor->id)->firstOrFail()->revoked_at);
        $this->assertNotNull($second->fresh()->revoked_at);
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('security_events')->update(['outcome' => 'accepted']));
    }

    public function test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql(): void
    {
        $actor = $this->user();
        $actor = $this->updateFixture($actor, ['email_verified_at' => now()]);
        $role = $this->role($actor, BusinessRole::Administrator);
        $context = $this->kitchen();
        app(MembershipService::class)->assign($actor, $context, $actor->id);
        $organization = $this->updateFixture($actor->organization, ['authorization_initialized' => true]);
        $this->assertTrue($organization->authorization_initialized);
        $roles = app(RoleAdministration::class);
        try {
            $roles->revoke($actor, $context, $role, self::PASSWORD);
            $this->fail('An ordinary administrator revoked their own administrator role.');
        } catch (BusinessRuleViolation) {
            $this->assertNull($role->fresh()->revoked_at);
        }
        try {
            $roles->setAccountActive($actor, $context, $actor, false);
            $this->fail('The last administrator was disabled.');
        } catch (BusinessRuleViolation) {
            $this->assertTrue($actor->fresh()->is_active);
        }
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($actor)->update(['is_active' => false]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($role)->update(['revoked_at' => now()]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($organization)->forceFill(['authorization_initialized' => false])->save());
        $membership = $actor->memberships()->firstOrFail();
        $this->assertRuntimeDatabaseRejects('23514', fn () => app(MembershipService::class)->deactivate($membership, 'Attempted context removal'));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($context)->update(['is_active' => false]));
    }

    public function test_manager_and_kitchen_hierarchy_and_protected_roles_across_locations(): void
    {
        $location = $this->activeRestaurant();
        $other = $this->activeRestaurant('R2');
        $manager = $this->user('manager@example.test');
        $manager = $this->updateFixture($manager, ['email_verified_at' => now()]);
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
