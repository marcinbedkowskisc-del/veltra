<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\RoleAssignment;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationTestCase;

class BusinessRoleDefinitionTest extends AuthorizationTestCase
{
    public function test_exact_roles_scopes_and_database_validation(): void
    {
        $this->assertCount(6, BusinessRole::cases());
        $this->assertNull(BusinessRole::tryFrom('OPERATOR'));
        $kitchen = $this->kitchen();
        $user = $this->provisionedUser('person@example.test', BusinessRole::CentralKitchenChef, $kitchen);
        $role = RoleAssignment::where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseRejects('23505', fn (Connection $connection) => RoleAssignment::on('pgsql_setup')->create($role->getAttributes()));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($role)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($role)->update(['role' => BusinessRole::Employee]));

        $other = $this->pendingUser('other@example.test');
        $membership = app(MembershipService::class)->assign($other, $kitchen);
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => RoleAssignment::on('pgsql_setup')->create([
            'organization_id' => $other->organization_id,
            'user_id' => $other->id,
            'role' => BusinessRole::RestaurantManager,
            'location_id' => $kitchen->id,
            'membership_id' => $membership->id,
        ]));
    }

    public function test_authorization_denies_unverified_identity_and_membership_without_role(): void
    {
        $user = $this->pendingUser();
        $location = $this->kitchen();
        app(MembershipService::class)->assign($user, $location);
        $authorization = app(BusinessAuthorization::class);
        $this->assertFalse($authorization->eligible($user, $location));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($user)->forceFill(['email_verified_at' => now()])->save());
        foreach (Permission::cases() as $permission) {
            $this->assertFalse($authorization->allows($user, $location, $location, $permission));
        }
    }

    public function test_chef_scope_follows_management_and_membership_revocation_is_immediate(): void
    {
        $restaurant = $this->activeRestaurant();
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $user = $this->provisionedUser('person@example.test', BusinessRole::CentralKitchenChef, $kitchen);
        $this->service->establishSupply($restaurant, $kitchen);
        $authorization = app(BusinessAuthorization::class);
        $this->assertTrue($authorization->allows($user, $kitchen, $restaurant, Permission::PersonnelManage));
        $this->assertFalse($authorization->allows($user, $kitchen, $restaurant, Permission::StructureManage));
        $membership = $user->memberships()->where('location_id', $kitchen->id)->firstOrFail();
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('location_memberships')
            ->where('id', $membership->id)->update([
                'is_active' => false, 'deactivated_at' => DB::raw('clock_timestamp()'), 'deactivation_reason' => 'Kitchen assignment ended.',
            ]));
        $user = $this->updateFixture($user, ['is_active' => false]);
        $this->assertFalse($authorization->allows($user, $kitchen, $restaurant, Permission::PersonnelManage));
    }

    public function test_boss_sees_future_restaurants_central_location_and_default_financial_permissions(): void
    {
        $first = $this->activeRestaurant();
        $user = $this->provisionedUser('person@example.test', BusinessRole::Boss);
        app(MembershipService::class)->assign($user, $first);
        $future = $this->activeRestaurant('R2');
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $authorization = app(BusinessAuthorization::class);
        $this->assertTrue($authorization->canExport($user, $first, $future, Permission::LocationView));
        $this->assertTrue($authorization->allows($user, $first, $kitchen, Permission::LocationView));
        $this->assertTrue($authorization->canExport($user, $first, $kitchen, Permission::LocationView));
        $this->assertTrue($authorization->canExport($user, $first, $future, Permission::PurchasePricesView));
        $this->assertFalse($authorization->allows($user, $first, $future, Permission::PersonnelManage));
    }

    public function test_multiple_explicit_restaurant_roles_require_matching_operating_context(): void
    {
        $first = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $unrelated = $this->activeRestaurant('R3');
        $chef = $this->provisionedUser('person@example.test', BusinessRole::RestaurantChef, $first);
        $this->role($chef, BusinessRole::RestaurantChef, $second);
        $authorization = app(BusinessAuthorization::class);
        $this->assertFalse($authorization->allows($chef, $first, $second, Permission::RecipesView));
        $this->assertTrue($authorization->allows($chef, $second, $second, Permission::RecipesView));
        $this->assertFalse($authorization->eligible($chef, $unrelated));
        $this->assertFalse($authorization->allows($chef, $first, $unrelated, Permission::LocationView));
        $membership = $chef->memberships()->where('location_id', $second->id)->firstOrFail();
        app(MembershipService::class)->deactivate($membership, 'Second restaurant assignment ended.');
        $this->assertFalse($authorization->allows($chef, $second, $second, Permission::RecipesView));
    }

    public function test_restaurant_roles_have_no_implicit_financial_access_and_modules_are_closed(): void
    {
        $location = $this->activeRestaurant();
        $authorization = app(BusinessAuthorization::class);
        foreach ([BusinessRole::RestaurantManager, BusinessRole::RestaurantChef, BusinessRole::Employee] as $i => $role) {
            $user = $this->provisionedUser("role{$i}@example.test", $role, $location);
            $this->assertTrue($authorization->allows($user, $location, $location, Permission::LocationView));
            $this->assertFalse($authorization->allows($user, $location, $location, Permission::FoodcostView));
            $this->assertFalse($authorization->allows($user, $location, $location, Permission::PurchasePricesView));
            $this->assertSame($role === BusinessRole::RestaurantManager, $authorization->allows($user, $location, $location, Permission::PersonnelManage));
            $this->assertSame($role === BusinessRole::RestaurantChef, $authorization->allows($user, $location, $location, Permission::RecipesView));
        }
        $this->assertFalse(app(ModuleAvailability::class)->enabled($location, 'TEMPERATURES'));
    }
}
