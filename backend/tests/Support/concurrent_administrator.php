<?php

use App\Domain\Authorization\RoleAdministration;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[3]]);
$user = User::findOrFail($argv[1]);
$context = Location::findOrFail($argv[2]);
try {
    if ($argv[4] === 'deactivate') {
        app(RoleAdministration::class)->setAccountActive($user, $context, $user, false);
    } else {
        app(RoleAdministration::class)->revoke($user, $context, RoleAssignment::where('user_id', $user->id)->firstOrFail(), 'Test-Only-Password!42');
    }
    echo json_encode(['outcome' => 'accepted']);
} catch (BusinessRuleViolation) {
    echo json_encode(['outcome' => 'rejected']);
}
