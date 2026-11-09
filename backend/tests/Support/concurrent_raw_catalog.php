<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialService;
use App\Domain\RawMaterials\RawMaterialSkuReservationService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModuleCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing' || DB::scalar('SELECT current_user') !== 'veltra_app') {
    throw new RuntimeException('Fixture requires the restricted isolated test database.');
}
[$script, $actorId, $locationId, $categoryId, $operation, $key, $application] = $argv;
if (! preg_match('/^[a-z0-9-]{1,63}$/D', $application)) {
    throw new InvalidArgumentException('Invalid application name.');
}
DB::select('SELECT set_config(?, ?, false)', ['application_name', $application]);
$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
$actor = User::findOrFail($actorId);
$location = Location::findOrFail($locationId);
$result = match ($operation) {
    'reserve' => app(RawMaterialSkuReservationService::class)->reserve($actor, $location, $key),
    'create', 'create-distinct' => app(RawMaterialService::class)->create($actor, $location, [
        'category_id' => $categoryId, 'name' => 'Równoległy surowiec '.$key, 'base_unit_code' => 'g', 'sku_reservation_id' => $key,
    ]),
    default => throw new InvalidArgumentException('Invalid operation.'),
};
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
