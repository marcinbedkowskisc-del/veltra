<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\MembershipService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationTestCase;

class BossFinancialPermissionsTest extends AuthorizationTestCase
{
    #[DataProvider('financialConfigurations')]
    public function test_boss_financial_restrictions_override_grants_across_current_and_future_locations(bool $foodcost, bool $prices): void
    {
        $restaurant = $this->activeRestaurant();
        $kitchen = $this->primaryCentralKitchen();
        $admin = $this->provisionedUser('financial-admin@example.test', BusinessRole::Administrator);
        $boss = $this->provisionedUser('financial-boss@example.test', BusinessRole::Boss);
        app(MembershipService::class)->assign($admin, $restaurant);
        app(MembershipService::class)->assign($boss, $restaurant);
        $authorization = app(BusinessAuthorization::class);
        $this->assertTrue($authorization->allows($boss, $restaurant, $kitchen, Permission::FoodcostView));
        $this->assertTrue($authorization->allows($boss, $restaurant, $kitchen, Permission::PurchasePricesView));
        $permissions = app(PermissionAdministration::class);
        $permissions->grant($admin, $restaurant, $boss, $restaurant, Permission::FoodcostView);
        $permissions->grant($admin, $restaurant, $boss, $restaurant, Permission::PurchasePricesView);
        $this->assertFalse($authorization->allowsIndependent($boss, $restaurant, $restaurant, Permission::FoodcostView));
        $this->assertFalse($authorization->allowsIndependent($boss, $restaurant, $restaurant, Permission::PurchasePricesView));
        $excluded = [];
        if (! $foodcost) {
            $excluded[] = BossVisibilityCategory::Foodcost->value;
        }
        if (! $prices) {
            $excluded[] = BossVisibilityCategory::PurchaseFinancials->value;
        }
        app(BossLocationRestrictions::class)->replace($admin, $restaurant, $boss->id, 0, [], $excluded, []);
        $future = $this->activeRestaurant('FUTURE');
        foreach ([$restaurant, $kitchen, $future] as $location) {
            $this->assertSame($foodcost, $authorization->allows($boss, $restaurant, $location, Permission::FoodcostView));
            $this->assertSame($prices, $authorization->allows($boss, $restaurant, $location, Permission::PurchasePricesView));
            $this->assertSame($foodcost, $authorization->canExport($boss, $restaurant, $location, Permission::FoodcostView));
            $this->assertSame($prices, $authorization->canExport($boss, $restaurant, $location, Permission::PurchasePricesView));
        }
        $this->actingAs($boss, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $restaurant->id])->assertOk();
        $this->getJson("/api/v1/security/access?location_id={$kitchen->id}&permission=FOODCOST_VIEW")
            ->assertOk()->assertJsonPath('data.allowed', $foodcost);
        $this->getJson("/api/v1/security/access?location_id={$future->id}&permission=PURCHASE_PRICES_VIEW")
            ->assertOk()->assertJsonPath('data.allowed', $prices);
    }

    public static function financialConfigurations(): array
    {
        return [
            'both enabled' => [true, true],
            'both disabled' => [false, false],
            'only foodcost' => [true, false],
            'only purchase prices' => [false, true],
        ];
    }
}
