<?php

use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityException;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing' || DB::scalar('SELECT current_user') !== 'veltra_app') {
    throw new RuntimeException('The fixture requires the isolated database and restricted runtime role.');
}

[$script, $actorId, $locationId, $expectedVersion, $applicationName] = $argv;
if (! preg_match('/^[a-z0-9-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid test process name.');
}
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);
$app->instance(
    OrganizationCapabilityCatalog::class,
    OrganizationCapabilityCatalogFixtures::ready(),
);

try {
    $result = app(OrganizationCapabilityService::class)->configure(
        User::findOrFail($actorId),
        Location::findOrFail($locationId),
        'BOSS_RAW_CATALOG_READ',
        Str::uuid()->toString(),
        [
            'enabled' => true,
            'expected_state_version' => (int) $expectedVersion,
            'current_password' => 'Test-Only-Password!42',
        ],
    );
    $result = ['status' => 200, 'data' => $result];
} catch (OrganizationCapabilityException $exception) {
    $result = ['status' => $exception->getStatusCode(), 'code' => $exception->errorCode];
} catch (QueryException $exception) {
    $result = ['status' => 500, 'code' => 'DATABASE_FAILURE'];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
