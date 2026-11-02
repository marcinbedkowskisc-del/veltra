<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\PermissionGrant;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\AuthorizationTestCase;

class PermissionDelegationTest extends AuthorizationTestCase
{
    public function test_financial_and_report_permissions_are_independent_and_export_checks_sources(): void
    {
        $restaurant = $this->activeRestaurant();
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $chef = $this->user('central@example.test');
        $chef->forceFill(['email_verified_at' => now()])->save();
        $this->role($chef, BusinessRole::CentralKitchenChef, $kitchen);
        $target = $this->user();
        $target->forceFill(['email_verified_at' => now()])->save();
        $this->role($target, BusinessRole::RestaurantChef, $restaurant);
        $service = app(PermissionAdministration::class);
        $authorization = app(BusinessAuthorization::class);
        $service->grant($chef, $kitchen, $target, $restaurant, Permission::FoodcostView);
        $this->assertTrue($authorization->allows($target, $restaurant, $restaurant, Permission::FoodcostView));
        $this->assertFalse($authorization->allows($target, $restaurant, $restaurant, Permission::PurchasePricesView));
        $service->grant($chef, $kitchen, $target, $restaurant, Permission::ReportsExport);
        $this->assertFalse($authorization->allows($target, $restaurant, $restaurant, Permission::ReportsView));
        $this->assertFalse($authorization->canExport($target, $restaurant, $restaurant, Permission::FoodcostView));
        $service->grant($chef, $kitchen, $target, $restaurant, Permission::ReportsView);
        $this->assertTrue($authorization->canExport($target, $restaurant, $restaurant, Permission::FoodcostView));
        $this->assertFalse($authorization->canExport($target, $restaurant, $restaurant, Permission::PurchasePricesView));
    }

    public function test_overlapping_scheduled_delegations_merge_and_revocation_includes_future_grants(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $manager->forceFill(['email_verified_at' => now()])->save();
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user();
        $target->forceFill(['email_verified_at' => now()])->save();
        $this->role($target, BusinessRole::Employee, $location);
        $this->app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
        {
            public function enabled(Location $location, string $module): bool
            {
                return true;
            }
        });
        $service = app(PermissionAdministration::class);
        $authorization = app(BusinessAuthorization::class);
        $permission = Permission::TemperatureRegister;
        $from = now()->toImmutable();
        $service->grant($manager, $location, $target, $location, $permission, $from->addHour(), $from->addHours(3));
        $this->assertFalse($authorization->allows($target, $location, $location, $permission));
        $service->grant($manager, $location, $target, $location, $permission, $from->subMinute(), $from->addHours(2));
        $service->grant($manager, $location, $target, $location, $permission, $from->addHours(2), $from->addHours(4));
        $this->assertCount(1, $service->periods($target, $location, $permission));
        $this->assertSame(3, PermissionGrant::count());
        $this->assertTrue($authorization->allows($target, $location, $location, $permission));
        $service->revokeEffective($manager, $location, $target, $location, $permission);
        $this->assertFalse($authorization->allows($target, $location, $location, $permission));
        $this->assertSame(0, PermissionGrant::whereNull('revoked_at')->count());
        $service->grant($manager, $location, $target, $location, $permission, $from->subHours(2), $from->subHour());
        $this->assertFalse($authorization->allows($target, $location, $location, $permission));
        $service->grant($manager, $location, $target, $location, $permission);
        $this->assertTrue($authorization->allows($target, $location, $location, $permission));
        app(MembershipService::class)->deactivate($target->memberships()->where('is_active', true)->firstOrFail(), 'Assignment ended');
        app(MembershipService::class)->assign($target, $location);
        $this->role($target, BusinessRole::Employee, $location);
        $this->assertFalse($authorization->allows($target, $location, $location, $permission));
        $this->assertDatabaseRejects('23514', fn () => PermissionGrant::firstOrFail()->delete());
    }

    public function test_manager_cannot_grant_financial_permissions_or_delegate_unavailable_modules(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $manager->forceFill(['email_verified_at' => now()])->save();
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user();
        $this->role($target, BusinessRole::Employee, $location);
        foreach ([Permission::FoodcostView, Permission::PurchasePricesView, Permission::StructureManage, Permission::TemperatureRegister] as $permission) {
            try {
                app(PermissionAdministration::class)->grant($manager, $location, $target, $location, $permission);
                $this->fail('A prohibited delegation was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, PermissionGrant::count());
            }
        }
    }
}
