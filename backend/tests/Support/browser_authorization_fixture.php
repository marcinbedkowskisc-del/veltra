<?php

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\PersonnelAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_browser_stage1d' || ! $app->environment('local')) {
    throw new RuntimeException('This fixture requires the disposable Stage 1D browser database.');
}
if (($argv[1] ?? '') === 'setup') {
    DB::transaction(function (): void {
        $installation = app(InstallationService::class);
        $organization = $installation->initializeOrganization(['name' => 'Browser fixture', 'code' => 'BROWSER']);
        $kitchen = $installation->createLocation(['name' => 'Browser kitchen', 'code' => 'CK', 'type' => 'CENTRAL_KITCHEN']);
        $restaurant = $installation->createLocation(['name' => 'Browser restaurant', 'code' => 'R', 'type' => 'RESTAURANT']);
        $installation->assignManagingKitchen($restaurant, $kitchen);
        $restaurant = $installation->updateLocation($restaurant, ['is_active' => true]);
        $admin = User::create(['organization_id' => $organization->id, 'first_name' => 'Browser', 'last_name' => 'Administrator', 'email' => 'admin.stage1d@example.test', 'password' => 'Browser-Fixture-Password!42']);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $memberships = app(MembershipService::class);
        $memberships->assign($admin, $kitchen);
        RoleAssignment::create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => BusinessRole::Administrator]);
        $organization->forceFill(['authorization_initialized' => true])->save();
        $employee = User::create(['organization_id' => $organization->id, 'first_name' => 'Browser', 'last_name' => 'Employee', 'email' => 'employee.stage1d@example.test', 'password' => Str::password(48)]);
        $membership = $memberships->assign($employee, $restaurant);
        RoleAssignment::create(['organization_id' => $organization->id, 'user_id' => $employee->id, 'role' => BusinessRole::Employee, 'location_id' => $restaurant->id, 'membership_id' => $membership->id]);
    });
    app(AccountLinks::class)->issue(User::where('email', 'employee.stage1d@example.test')->firstOrFail(), 'activation', 'browser-fixture',
        User::where('email', 'admin.stage1d@example.test')->firstOrFail(), Location::where('code', 'CK')->firstOrFail());
    echo "Disposable browser fixture created; activation delivered via SMTP.\n";
} elseif (($argv[1] ?? '') === 'revoke') {
    $admin = User::where('email', 'admin.stage1d@example.test')->firstOrFail();
    $employee = User::where('email', 'employee.stage1d@example.test')->firstOrFail();
    app(PersonnelAdministration::class)->removeMembership($admin, Location::where('code', 'CK')->firstOrFail(), $employee->memberships()->where('is_active', true)->firstOrFail());
    echo "Employee membership revoked.\n";
} else {
    throw new InvalidArgumentException('Expected setup or revoke.');
}
