<?php

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\InstallationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency worker must use the dedicated test database.');
}

DB::select("SELECT set_config('application_name', ?, false)", [$argv[1]]);
try {
    app(InstallationService::class)->createLocation(['name' => 'Second', 'code' => 'CK2', 'type' => 'CENTRAL_KITCHEN']);
    echo json_encode(['outcome' => 'created'], JSON_THROW_ON_ERROR);
} catch (BusinessRuleViolation) {
    echo json_encode(['outcome' => 'conflict'], JSON_THROW_ON_ERROR);
}
