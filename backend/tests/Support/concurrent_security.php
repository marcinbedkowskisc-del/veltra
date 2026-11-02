<?php

use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
$actor = User::findOrFail($argv[1]);
$target = User::findOrFail($argv[2]);
$location = Location::findOrFail($argv[3]);
Mail::fake();
$app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
{
    public function enabled(Location $location, string $module): bool
    {
        return true;
    }
});
try {
    if ($argv[5] === 'email') {
        app(AccountLinks::class)->issue($target, 'reset', '127.0.0.1', $actor, $location);
    } elseif ($argv[5] === 'grant') {
        app(PermissionAdministration::class)->grant($actor, $location, $target, $location, Permission::TemperatureRegister);
    } else {
        app(PermissionAdministration::class)->revokeEffective($actor, $location, $target, $location, Permission::TemperatureRegister);
    }
    echo json_encode(['outcome' => 'accepted']);
} catch (BusinessRuleViolation) {
    echo json_encode(['outcome' => 'rejected']);
}
