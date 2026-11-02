<?php

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\InstallationService;
use App\Models\Location;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency worker must use the dedicated test database.');
}

DB::select("SELECT set_config('application_name', ?, false)", [$argv[3]]);
try {
    $assignment = app(InstallationService::class)->assignManagingKitchen(
        Location::findOrFail($argv[1]),
        Location::findOrFail($argv[2]),
        replace: ($argv[4] ?? '') === 'change',
    );
    echo json_encode(['outcome' => 'assigned', 'id' => $assignment->id], JSON_THROW_ON_ERROR);
} catch (BusinessRuleViolation $exception) {
    echo json_encode(['outcome' => 'conflict', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
