<?php

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\InstallationService;
use App\Models\Location;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $locationId, $applicationName] = array_pad($argv, 3, null);
if (DB::connection()->getDatabaseName() !== 'veltra_testing'
    || ! is_string($locationId)
    || ! Str::isUuid($locationId)
    || ! is_string($applicationName)
    || ! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid primary kitchen concurrency arguments.');
}

DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

try {
    app(InstallationService::class)->updateLocation(Location::findOrFail($locationId), ['is_active' => false]);
    $outcome = 'accepted';
} catch (BusinessRuleViolation) {
    $outcome = 'rejected';
}

fwrite(STDOUT, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR));
