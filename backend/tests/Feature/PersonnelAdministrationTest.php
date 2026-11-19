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
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Tests\AuthorizationTestCase;

class PersonnelAdministrationTest extends AuthorizationTestCase
{
    public function test_manager_creates_new_identity_but_existing_identity_requires_approval(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $manager = $this->updateFixture($manager, ['email_verified_at' => now()]);
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $service = app(PersonnelAdministration::class);
        $new = $service->requestPersonnel($manager, $location, $location, ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.test']);
        $this->assertNotNull($new);
        $this->assertSame(2, User::count());
        $this->assertSame(1, $new->memberships()->where('is_active', true)->count());
        $existing = $this->user('existing@example.test');
        $this->assertNull($service->requestPersonnel($manager, $location, $location, ['first_name' => 'Other', 'last_name' => 'Person', 'email' => 'EXISTING@EXAMPLE.TEST']));
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
        $chef = $this->user('central@example.test');
        $chef = $this->updateFixture($chef, ['email_verified_at' => now()]);
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $this->role($chef, BusinessRole::CentralKitchenChef, $kitchen);
        $service->decide($chef, $kitchen, $request, true);
        $this->assertSame('approved', $request->fresh()->decision);
        $this->assertSame(1, $existing->memberships()->where('is_active', true)->count());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($request)->fresh()->delete());
    }

    public function test_manager_removes_only_unprotected_local_memberships_and_keeps_other_locations(): void
    {
        $first = $this->activeRestaurant();
        $other = $this->activeRestaurant('R2');
        $manager = $this->user('manager@example.test');
        $manager = $this->updateFixture($manager, ['email_verified_at' => now()]);
        $this->role($manager, BusinessRole::RestaurantManager, $first);
        $target = $this->user();
        $role = $this->role($target, BusinessRole::RestaurantChef, $first);
        $otherMembership = app(MembershipService::class)->assign($target, $other);
        $service = app(PersonnelAdministration::class);
        $service->removeMembership($manager, $first, $target->memberships()->whereKey($role->membership_id)->firstOrFail());
        $this->assertNotNull($role->fresh()->revoked_at);
        $this->assertTrue($otherMembership->fresh()->is_active);
        app(MembershipService::class)->assign($target, $first);
        $this->role($target, BusinessRole::RestaurantManager, $other);
        $this->expectException(AuthorizationException::class);
        $service->removeMembership($manager, $first, $target->memberships()->where('location_id', $first->id)->where('is_active', true)->firstOrFail());
    }

    public function test_administrator_removing_membership_revokes_attached_permissions_with_the_signed_actor(): void
    {
        $restaurant = $this->activeRestaurant();
        $admin = $this->user('admin@example.test');
        $admin = $this->updateFixture($admin, ['email_verified_at' => now()]);
        app(MembershipService::class)->assign($admin, $restaurant);
        $this->role($admin, BusinessRole::Administrator);
        $target = $this->user('target@example.test');
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
