<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
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

[$script, $actorId, $contextId, $materialId, $targetLocationId, $operation, $applicationName] = $argv;
if (! in_array($operation, ['share', 'revoke', 'bulk-share'], true)
    || ! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid concurrent share operation.');
}

$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

try {
    $administration = app(RawMaterialLocationShareAdministration::class);
    $result = match ($operation) {
        'share' => $administration->share(
            User::findOrFail($actorId),
            Location::findOrFail($contextId),
            $materialId,
            $targetLocationId,
        ),
        'revoke' => $administration->revoke(
            User::findOrFail($actorId),
            Location::findOrFail($contextId),
            $materialId,
            $targetLocationId,
            'Concurrent test revocation',
        ),
        'bulk-share' => $administration->bulkShareToLocations(
            User::findOrFail($actorId),
            Location::findOrFail($contextId),
            $materialId,
            [$targetLocationId],
        ),
    };
    $resultCode = match ($operation) {
        'share' => 'created',
        'revoke' => 'revoked',
        'bulk-share' => strtolower($result['outcome']),
    };
    fwrite(STDOUT, json_encode(['result' => $resultCode, 'data' => $result], JSON_THROW_ON_ERROR));
} catch (RawMaterialException $exception) {
    if (! in_array($exception->errorCode, ['RAW_MATERIAL_SHARE_ALREADY_ACTIVE', 'RAW_MATERIAL_SHARE_NOT_FOUND'], true)) {
        throw $exception;
    }
    fwrite(STDOUT, json_encode(['result' => 'conflict', 'code' => $exception->errorCode], JSON_THROW_ON_ERROR));
}
