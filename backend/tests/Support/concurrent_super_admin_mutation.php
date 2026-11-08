<?php

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
$actor = User::findOrFail($argv[1]);
$target = User::findOrFail($argv[2]);
$context = Location::findOrFail($argv[3]);
try {
    app(RoleAdministration::class)->grant($actor, $context, $target, BusinessRole::Boss, null);
    echo json_encode(['outcome' => 'accepted']);
} catch (BusinessRuleViolation) {
    echo json_encode(['outcome' => 'rejected']);
}
