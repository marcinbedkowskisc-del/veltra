<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModuleCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing' || DB::scalar('SELECT current_user') !== 'veltra_app') {
    throw new RuntimeException('Fixture wymaga izolowanej bazy i ograniczonej roli.');
}
[$script, $actorId, $locationId, $materialId, $version, $operation, $payload, $application] = $argv;
if (! preg_match('/^[a-z0-9-]{1,63}$/D', $application)) {
    throw new InvalidArgumentException('Nieprawidłowa nazwa procesu testowego.');
}
DB::select('SELECT set_config(?, ?, false)', ['application_name', $application]);
$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
$actor = User::findOrFail($actorId);
$context = Location::findOrFail($locationId);
$service = app(RawMaterialService::class);
try {
    $result = match ($operation) {
        'revise' => $service->revise($actor, $context, $materialId, json_decode($payload, true, flags: JSON_THROW_ON_ERROR), $version),
        'submit' => $service->submit($actor, $context, $materialId, $version),
        'archive' => $service->archive($actor, $context, $materialId, 'Test wyścigu', $version),
        'approve' => $service->approve($actor, $context, $materialId, 'Test wyścigu', $version),
        'return' => $service->returnForChanges($actor, $context, $materialId, 'Test wyścigu', $version),
        default => throw new InvalidArgumentException('Nieprawidłowa operacja testowa.'),
    };
    $result = ['status' => 200, 'data' => $result];
} catch (RawMaterialException $exception) {
    $result = ['status' => $exception->status, 'code' => $exception->errorCode];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
