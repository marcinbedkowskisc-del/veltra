<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModuleCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}

[$script, $actorId, $locationId, $categoryId, $name, $applicationName] = $argv;
if (! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid PostgreSQL application name.');
}
$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

$actor = User::findOrFail($actorId);
$location = Location::findOrFail($locationId);
$material = app(RawMaterialService::class)->create($actor, $location, [
    'category_id' => $categoryId,
    'name' => $name,
    'base_unit_code' => 'kg',
    'nominal_quantity' => '1000',
    'conversions' => [['unit_code' => 'g']],
]);

fwrite(STDOUT, json_encode(['id' => $material['id'], 'code' => $material['code']], JSON_THROW_ON_ERROR));
