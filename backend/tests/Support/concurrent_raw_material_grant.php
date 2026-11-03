<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModuleCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}

[$script, $actorId, $locationId, $recipientId, $applicationName] = $argv;
if (! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid PostgreSQL application name.');
}

$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

try {
    $grant = app(OrganizationRawMaterialPermissionAdministration::class)->grant(
        User::findOrFail($actorId),
        Location::findOrFail($locationId),
        User::findOrFail($recipientId),
        RawMaterialPermission::View,
        'Test współbieżnego nadania i zawieszenia',
    );
    fwrite(STDOUT, json_encode(['granted' => true, 'grant_id' => $grant['id']], JSON_THROW_ON_ERROR));
} catch (AuthorizationException) {
    fwrite(STDOUT, json_encode(['denied' => true], JSON_THROW_ON_ERROR));
}
