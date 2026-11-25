<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Authorization\PersonnelAdministration;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\MembershipRequest;
use App\Models\PermissionGrant;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Tests\AuthorizationTestCase;

class PersonnelAdministrationTest extends AuthorizationTestCase
{
    public function test_manager_creates_new_identity_but_existing_identity_requires_approval(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->provisionedUser('manager@example.test', BusinessRole::RestaurantManager, $location);
        $service = app(PersonnelAdministration::class);
        $new = $service->requestPersonnel($manager, $location, $location, ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.test', 'role' => 'EMPLOYEE']);
        $this->assertNotNull($new);
        $this->assertSame(2, User::count());
        $this->assertSame(1, $new->memberships()->where('is_active', true)->count());
        $existing = $this->pendingUser('existing@example.test');
        $this->assertNull($service->requestPersonnel($manager, $location, $location, ['first_name' => 'Other', 'last_name' => 'Person', 'email' => 'EXISTING@EXAMPLE.TEST', 'role' => 'EMPLOYEE']));
        $this->assertSame(3, User::count());
        $this->assertSame(0, $existing->memberships()->count());
        $this->assertSame(2, $service->scopedUsers($manager, $location, $location)->count());
        $request = MembershipRequest::firstOrFail();
        try {
            $service->decide($manager, $location, $request, true);
            $this->fail('Manager approved existing personnel without higher authorization.');
        } catch (AuthorizationException) {
            $this->assertNull($request->fresh()->decision);
        }
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $chef = $this->provisionedUser('central@example.test', BusinessRole::CentralKitchenChef, $kitchen);
        $service->decide($chef, $kitchen, $request, true);
        $this->assertSame('approved', $request->fresh()->decision);
        $this->assertSame(1, $existing->memberships()->where('is_active', true)->count());
        $this->assertSame(0, RoleAssignment::where('user_id', $existing->id)->count());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($request)->fresh()->delete());
    }

    public function test_manager_removes_only_unprotected_local_memberships_and_keeps_other_locations(): void
    {
        $first = $this->activeRestaurant();
        $other = $this->activeRestaurant('R2');
        $manager = $this->provisionedUser('manager@example.test', BusinessRole::RestaurantManager, $first);
        $target = $this->provisionedUser('person@example.test', BusinessRole::RestaurantChef, $first);
        $role = RoleAssignment::where('user_id', $target->id)->firstOrFail();
        $otherMembership = app(MembershipService::class)->assign($target, $other);
        $this->role($target, BusinessRole::RestaurantChef, $other);
        $service = app(PersonnelAdministration::class);
        $service->removeMembership($manager, $first, $target->memberships()->whereKey($role->membership_id)->firstOrFail());
        $this->assertNotNull($role->fresh()->revoked_at);
        $this->assertTrue($otherMembership->fresh()->is_active);
        $protected = $this->provisionedUser('protected@example.test', BusinessRole::RestaurantManager, $other);
        app(MembershipService::class)->assign($protected, $first);
        $this->expectException(AuthorizationException::class);
        $service->removeMembership($manager, $first, $protected->memberships()->where('location_id', $first->id)->where('is_active', true)->firstOrFail());
    }

    public function test_administrator_removing_membership_revokes_attached_permissions_with_the_signed_actor(): void
    {
        $restaurant = $this->activeRestaurant();
        $admin = $this->provisionedUser('admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $restaurant);
        $target = $this->pendingUser('target@example.test');
        $membership = app(MembershipService::class)->assign($target, $restaurant);
        $grant = app(PermissionAdministration::class)->grant(
            $admin,
            $restaurant,
            $target,
            $restaurant,
            Permission::FoodcostView,
        );

        app(PersonnelAdministration::class)->removeMembership($admin, $restaurant, $membership);

        $revokedGrant = PermissionGrant::findOrFail($grant->id);
        $this->assertNotNull($revokedGrant->revoked_at);
        $this->assertSame($admin->id, $revokedGrant->revoked_by);
        $this->assertFalse($membership->fresh()->is_active);
    }
}
