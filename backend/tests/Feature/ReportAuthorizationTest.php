<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\ReportAuthorization;
use App\Domain\Authorization\ReportCatalog;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;

class ReportAuthorizationTest extends AuthorizationTestCase
{
    public function test_report_share_is_still_required_with_access_to_central_location(): void
    {
        $restaurant = $this->activeRestaurant();
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $chef = $this->provisionedUser('chef@example.test', BusinessRole::CentralKitchenChef, $kitchen);
        $boss = $this->provisionedUser('person@example.test', BusinessRole::Boss);
        app(MembershipService::class)->assign($boss, $restaurant);
        $report = Str::uuid()->toString();
        $this->assertFalse(app(ReportAuthorization::class)->canViewShared($boss, $restaurant, $report));
        $this->app->bind(ReportCatalog::class, fn () => new class($report, $kitchen) implements ReportCatalog
        {
            public function __construct(private string $report, private Location $kitchen) {}

            public function sourceKitchen(string $reportId): ?Location
            {
                return $reportId === $this->report ? $this->kitchen : null;
            }
        });
        $service = app(ReportAuthorization::class);
        $share = $service->share($chef, $kitchen, $boss, $report);
        $this->assertTrue($service->canViewShared($boss, $restaurant, $report));
        $this->assertFalse($service->canViewShared($boss, $restaurant, Str::uuid()->toString()));
        $this->assertTrue(app(BusinessAuthorization::class)->allows($boss, $restaurant, $kitchen, Permission::LocationView));

        $admin = $this->provisionedUser('admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $restaurant);
        $restrictions = app(BossLocationRestrictions::class);
        $restrictions->replace(
            $admin, $restaurant, $boss->id, 0, [], [BossVisibilityCategory::ReportsAnalytics->value], [],
        );
        $this->assertFalse($service->canViewShared($boss, $restaurant, $report));
        $restrictions->replace($admin, $restaurant, $boss->id, 1, [], [], [[
            'category' => BossVisibilityCategory::ReportsAnalytics->value,
            'location_id' => $kitchen->id,
        ]]);
        $this->assertFalse($service->canViewShared($boss, $restaurant, $report));
        $restrictions->replace($admin, $restaurant, $boss->id, 2, [$kitchen->id], [], []);
        $this->assertFalse($service->canViewShared($boss, $restaurant, $report));
        $restrictions->replace($admin, $restaurant, $boss->id, 3, [], [], []);
        $this->assertTrue($service->canViewShared($boss, $restaurant, $report));

        $service->revoke($chef, $kitchen, $share);
        $this->assertFalse($service->canViewShared($boss, $restaurant, $report));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($share)->fresh()->delete());
        $service->authorizeExport($boss, $restaurant, $restaurant, Permission::LocationView);
        $restrictions->replace(
            $admin, $restaurant, $boss->id, 4, [], [BossVisibilityCategory::PurchaseFinancials->value], [],
        );
        $this->expectException(AuthorizationException::class);
        $service->authorizeExport($boss, $restaurant, $restaurant, Permission::PurchasePricesView);
    }
}
