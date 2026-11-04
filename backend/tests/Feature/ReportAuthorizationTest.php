<?php

namespace Tests\Feature;

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
    public function test_explicit_report_share_is_not_access_to_central_source_data_and_default_catalog_denies(): void
    {
        $restaurant = $this->activeRestaurant();
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $chef = $this->user('chef@example.test');
        $this->role($chef, BusinessRole::CentralKitchenChef, $kitchen);
        $boss = $this->user();
        $this->role($boss, BusinessRole::Boss);
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
        $this->assertFalse(app(BusinessAuthorization::class)->allows($boss, $restaurant, $kitchen, Permission::LocationView));
        $service->revoke($chef, $kitchen, $share);
        $this->assertFalse($service->canViewShared($boss, $restaurant, $report));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($share)->fresh()->delete());
        $service->authorizeExport($boss, $restaurant, $restaurant, Permission::LocationView);
        $this->expectException(AuthorizationException::class);
        $service->authorizeExport($boss, $restaurant, $restaurant, Permission::PurchasePricesView);
    }
}
