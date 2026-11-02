<?php

use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_browser_stage1c') {
    throw new RuntimeException('This fixture requires the disposable browser-test database.');
}

$mode = $argv[1] ?? '';
$memberships = app(MembershipService::class);
if ($mode === 'prepare') {
    $installation = app(InstallationService::class);
    $installation->initializeOrganization(['name' => 'Disposable browser fixture', 'code' => 'BROWSER']);
    $user = app(IdentityService::class)->provisionInitial([
        'first_name' => 'Browser', 'last_name' => 'Fixture', 'email' => 'browser@example.test',
    ], 'Disposable-Fixture!42');
    $kitchen = $installation->createLocation(['name' => 'Kitchen Test', 'code' => 'CK1', 'type' => 'CENTRAL_KITCHEN']);
    $memberships->assign($user, $kitchen);
    foreach (['R1', 'R2'] as $code) {
        $restaurant = $installation->createLocation(['name' => "Restaurant {$code}", 'code' => $code, 'type' => 'RESTAURANT']);
        $installation->assignManagingKitchen($restaurant, $kitchen);
        $installation->updateLocation($restaurant, ['is_active' => true]);
        $memberships->assign($user, $restaurant);
    }
    $unassigned = $installation->createLocation(['name' => 'Unassigned Restaurant', 'code' => 'R3', 'type' => 'RESTAURANT']);
    $installation->assignManagingKitchen($unassigned, $kitchen);
    $installation->updateLocation($unassigned, ['is_active' => true]);
    $installation->establishSupply($unassigned, $kitchen);
} elseif ($mode === 'revoke-r1') {
    $user = User::where('email', 'browser@example.test')->firstOrFail();
    $location = Location::where('code', 'R1')->firstOrFail();
    $memberships->deactivate($user->memberships()->where('location_id', $location->id)->where('is_active', true)->firstOrFail(), 'Browser test revocation');
} elseif ($mode === 'disable') {
    app(IdentityService::class)->disable(User::where('email', 'browser@example.test')->firstOrFail());
} else {
    throw new RuntimeException('Unknown browser-fixture operation.');
}
echo "Disposable browser fixture operation completed. No credentials printed.\n";
