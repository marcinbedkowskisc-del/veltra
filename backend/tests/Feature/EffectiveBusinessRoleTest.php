<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\PersonnelAdministration;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;
use Tests\InstallationTestCase;

class EffectiveBusinessRoleTest extends InstallationTestCase
{
    private Location $location;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialize();
        $this->kitchen();
        $restaurant = $this->restaurant();
        $this->service->assignManagingKitchen($restaurant, Location::where('type', 'CENTRAL_KITCHEN')->firstOrFail());
        $this->location = $this->service->updateLocation($restaurant, ['is_active' => true]);
        $this->admin = $this->provision('admin@example.test', BusinessRole::Administrator);
        $this->employee = $this->provision('employee@example.test', BusinessRole::Employee);
    }

    private function provision(string $email, BusinessRole $role): User
    {
        $id = $this->withSetupTransaction(function () use ($email, $role): string {
            $user = User::on('pgsql_setup')->create([
                'organization_id' => $this->location->organization_id, 'first_name' => 'Test', 'last_name' => 'User',
                'email' => $email, 'password' => 'Test-Only-Password!42', 'is_active' => true,
            ]);
            $membership = LocationMembership::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id,
                'location_id' => $this->location->id, 'is_active' => true,
            ]);
            RoleAssignment::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id, 'role' => $role,
                'location_id' => $role->organizationWide() ? null : $this->location->id,
                'membership_id' => $role->organizationWide() ? null : $membership->id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->id;
        });

        return User::on('pgsql')->findOrFail($id);
    }

    public function test_last_role_and_membership_are_protected_and_replacement_is_atomic(): void
    {
        $assignment = RoleAssignment::where('user_id', $this->employee->id)->firstOrFail();
        $membership = $this->employee->memberships()->firstOrFail();
        try {
            app(RoleAdministration::class)->revoke($this->admin, $this->location, $assignment);
            $this->fail('Last role was revoked.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame(__('messages.business_role_must_remain'), $exception->getMessage());
        }
        try {
            app(PersonnelAdministration::class)->removeMembership($this->admin, $this->location, $membership);
            $this->fail('Last role membership was removed.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame(__('messages.business_role_must_remain'), $exception->getMessage());
        }
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($assignment)->update(['revoked_at' => now()]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($membership)->update([
            'is_active' => false, 'deactivated_at' => now(), 'deactivation_reason' => 'Test',
        ]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($this->location)->update(['is_active' => false]));
        $replacement = app(RoleAdministration::class)->replace(
            $this->admin, $this->location, $this->employee, BusinessRole::RestaurantChef, [$this->location->id],
        );
        $this->assertCount(1, $replacement);
        $this->assertNotNull($assignment->fresh()->revoked_at);
        $this->assertSame(BusinessRole::RestaurantChef, $replacement[0]->role);
    }

    public function test_pending_and_inactive_accounts_cannot_be_activated_without_a_role(): void
    {
        $pending = User::on('pgsql_setup')->create([
            'organization_id' => $this->admin->organization_id, 'first_name' => 'Pending', 'last_name' => 'User',
            'email' => 'pending@example.test', 'password' => 'Test-Only-Password!42', 'is_active' => true,
        ]);
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($pending)->forceFill(['email_verified_at' => now()])->save());
        app(MembershipService::class)->assign($pending, $this->location);
        app(RoleAdministration::class)->grant($this->admin, $this->location, $pending, BusinessRole::Employee, $this->location);
        $this->updateFixture($pending, ['email_verified_at' => now()]);
        $this->assertNotNull($pending->fresh()->email_verified_at);

        app(RoleAdministration::class)->setAccountActive($this->admin, $this->location, $this->employee, false);
        $assignment = RoleAssignment::where('user_id', $this->employee->id)->firstOrFail();
        app(RoleAdministration::class)->revoke($this->admin, $this->location, $assignment);
        try {
            app(RoleAdministration::class)->setAccountActive($this->admin, $this->location, $this->employee, true);
            $this->fail('A roleless account was reactivated.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame(__('messages.business_role_must_remain'), $exception->getMessage());
        }
        $membership = $this->employee->memberships()->firstOrFail();
        app(PersonnelAdministration::class)->removeMembership($this->admin, $this->location, $membership);
        app(PersonnelAdministration::class)->assignMembership($this->admin, $this->location, $this->employee, $this->location);
        app(RoleAdministration::class)->grant($this->admin, $this->location, $this->employee, BusinessRole::Employee, $this->location);
        $this->assertFalse(app(BusinessAuthorization::class)->eligible($this->employee, $this->location));
        app(RoleAdministration::class)->setAccountActive($this->admin, $this->location, $this->employee, true);
        $this->assertTrue(app(BusinessAuthorization::class)->eligible($this->employee, $this->location));
    }

    public function test_new_personnel_requires_an_explicit_authorized_role(): void
    {
        $service = app(PersonnelAdministration::class);
        $profile = ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.test'];
        try {
            $service->requestPersonnel($this->admin, $this->location, $this->location, $profile);
            $this->fail('A new user was provisioned without an explicit role.');
        } catch (ValidationException) {
            $this->assertFalse(User::where('email', $profile['email'])->exists());
        }
        $pending = $service->requestPersonnel($this->admin, $this->location, $this->location, [
            ...$profile, 'role' => BusinessRole::RestaurantChef->value,
        ]);
        $this->assertNotNull($pending);
        $this->assertNull($pending->email_verified_at);
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($pending, BusinessRole::RestaurantChef, $this->location));
        $this->assertFalse(app(BusinessAuthorization::class)->eligible($pending, $this->location));
    }

    public function test_one_role_can_span_locations_and_only_the_last_effective_assignment_is_protected(): void
    {
        $other = $this->restaurant('R2');
        $this->service->assignManagingKitchen($other, Location::where('type', 'CENTRAL_KITCHEN')->firstOrFail());
        $other = $this->service->updateLocation($other, ['is_active' => true]);
        app(MembershipService::class)->assign($this->employee, $other);
        $roles = app(RoleAdministration::class);
        $roles->grant($this->admin, $this->location, $this->employee, BusinessRole::Employee, $other);
        $first = $this->employee->memberships()->where('location_id', $this->location->id)->firstOrFail();
        app(PersonnelAdministration::class)->removeMembership($this->admin, $this->location, $first);
        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($this->employee, BusinessRole::Employee, $other));
    }
}
