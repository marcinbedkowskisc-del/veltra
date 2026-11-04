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
use Tests\AuthorizationTestCase;

class BusinessRoleDefinitionTest extends AuthorizationTestCase
{
    public function test_exact_roles_scopes_and_database_validation(): void
    {
        $this->assertCount(6, BusinessRole::cases());
        $this->assertNull(BusinessRole::tryFrom('OPERATOR'));
        $user = $this->user();
        $kitchen = $this->kitchen();
        $role = $this->role($user, BusinessRole::CentralKitchenChef, $kitchen);
        $this->assertDatabaseRejects('23505', fn (Connection $connection) => RoleAssignment::on('pgsql_setup')->create($role->getAttributes()));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($role)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($role)->update(['role' => BusinessRole::Employee]));

        $other = $this->user('other@example.test');
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
        $user = $this->user();
        $user = $this->updateFixture($user, ['email_verified_at' => null]);
        $location = $this->kitchen();
        app(MembershipService::class)->assign($user, $location);
        $authorization = app(BusinessAuthorization::class);
        $this->assertFalse($authorization->eligible($user, $location));
        $user = $this->updateFixture($user, ['email_verified_at' => now()]);
        $this->assertTrue($authorization->eligible($user, $location));
        foreach (Permission::cases() as $permission) {
            $this->assertFalse($authorization->allows($user, $location, $location, $permission));
        }
    }

    public function test_chef_scope_follows_management_never_supply_and_revocation_is_immediate(): void
    {
        $user = $this->user();
        $user = $this->updateFixture($user, ['email_verified_at' => now()]);
        $restaurant = $this->activeRestaurant();
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $other = $this->kitchen('CK2');
        $this->role($user, BusinessRole::CentralKitchenChef, $kitchen);
        $this->service->establishSupply($restaurant, $other);
        $authorization = app(BusinessAuthorization::class);
        $this->assertTrue($authorization->allows($user, $kitchen, $restaurant, Permission::PersonnelManage));
        $this->assertFalse($authorization->allows($user, $kitchen, $other, Permission::LocationView));
        $this->assertFalse($authorization->allows($user, $kitchen, $restaurant, Permission::StructureManage));
        $this->service->assignManagingKitchen($restaurant, $other, replace: true);
        $this->assertFalse($authorization->allows($user, $kitchen, $restaurant, Permission::PersonnelManage));
    }

    public function test_boss_sees_future_restaurants_but_not_central_sources_or_financial_permissions(): void
    {
        $user = $this->user();
        $user = $this->updateFixture($user, ['email_verified_at' => now()]);
        $first = $this->activeRestaurant();
        app(MembershipService::class)->assign($user, $first);
        $this->role($user, BusinessRole::Boss);
        $future = $this->activeRestaurant('R2');
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $authorization = app(BusinessAuthorization::class);
        $this->assertTrue($authorization->canExport($user, $first, $future, Permission::LocationView));
        $this->assertFalse($authorization->allows($user, $first, $kitchen, Permission::ReportsView));
        $this->assertFalse($authorization->canExport($user, $first, $future, Permission::PurchasePricesView));
        $this->assertFalse($authorization->allows($user, $first, $future, Permission::PersonnelManage));
    }

    public function test_multiple_explicit_kitchen_roles_require_matching_operating_context(): void
    {
        $chef = $this->user();
        $first = $this->kitchen();
        $second = $this->kitchen('CK2');
        $unrelated = $this->kitchen('CK3');
        $this->role($chef, BusinessRole::CentralKitchenChef, $first);
        $this->role($chef, BusinessRole::CentralKitchenChef, $second);
        $restaurant = $this->activeRestaurant();
        $this->service->assignManagingKitchen($restaurant, $second, replace: true);
        $authorization = app(BusinessAuthorization::class);
        $this->assertFalse($authorization->allows($chef, $first, $restaurant, Permission::PersonnelManage));
        $this->assertTrue($authorization->allows($chef, $second, $restaurant, Permission::PersonnelManage));
        $this->assertFalse($authorization->eligible($chef, $restaurant));
        $this->assertFalse($authorization->allows($chef, $first, $unrelated, Permission::LocationView));
        $membership = $chef->memberships()->where('location_id', $second->id)->firstOrFail();
        app(MembershipService::class)->deactivate($membership, 'Second kitchen assignment ended.');
        $this->assertFalse($authorization->allows($chef, $second, $restaurant, Permission::PersonnelManage));
    }

    public function test_restaurant_roles_have_no_implicit_financial_access_and_modules_are_closed(): void
    {
        $location = $this->activeRestaurant();
        $authorization = app(BusinessAuthorization::class);
        foreach ([BusinessRole::RestaurantManager, BusinessRole::RestaurantChef, BusinessRole::Employee] as $i => $role) {
            $user = $this->user("role{$i}@example.test");
            $user = $this->updateFixture($user, ['email_verified_at' => now()]);
            $this->role($user, $role, $location);
            $this->assertTrue($authorization->allows($user, $location, $location, Permission::LocationView));
            $this->assertFalse($authorization->allows($user, $location, $location, Permission::FoodcostView));
            $this->assertFalse($authorization->allows($user, $location, $location, Permission::PurchasePricesView));
            $this->assertSame($role === BusinessRole::RestaurantManager, $authorization->allows($user, $location, $location, Permission::PersonnelManage));
            $this->assertSame($role === BusinessRole::RestaurantChef, $authorization->allows($user, $location, $location, Permission::RecipesView));
        }
        $this->assertFalse(app(ModuleAvailability::class)->enabled($location, 'TEMPERATURES'));
    }
}
