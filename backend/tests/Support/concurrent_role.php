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
    throw new RuntimeException('Concurrency worker must use the dedicated test database.');
}

DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
try {
    app(RoleAdministration::class)->grant(
        User::findOrFail($argv[1]),
        Location::findOrFail($argv[3]),
        User::findOrFail($argv[2]),
        BusinessRole::RestaurantChef,
        Location::findOrFail($argv[3]),
    );
    echo json_encode(['outcome' => 'granted'], JSON_THROW_ON_ERROR);
} catch (BusinessRuleViolation) {
    echo json_encode(['outcome' => 'conflict'], JSON_THROW_ON_ERROR);
}
