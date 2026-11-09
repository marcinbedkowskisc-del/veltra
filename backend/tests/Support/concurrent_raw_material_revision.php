<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
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

[$script, $operation, $actorId, $contextId, $materialId, $targetLocationId, $applicationName] = $argv;
if (! in_array($operation, ['approve', 'revoke', 'read'], true)
    || ! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid concurrent RAW revision operation.');
}

$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

$actor = User::findOrFail($actorId);
$context = Location::findOrFail($contextId);

try {
    $result = match ($operation) {
        'approve' => [
            'status' => 'approved',
            'data' => app(RawMaterialService::class)->approve(
                $actor,
                $context,
                $materialId,
                'Concurrent approval test',
            ),
        ],
        'revoke' => [
            'status' => 'revoked',
            'data' => app(RawMaterialLocationShareAdministration::class)->revoke(
                $actor,
                $context,
                $materialId,
                $targetLocationId,
                'Concurrent revision test revocation',
            ),
        ],
        'read' => [
            'status' => 'read',
            'data' => app(RawMaterialService::class)->show($actor, $context, $materialId),
        ],
    };
} catch (RawMaterialException $exception) {
    $result = ['status' => 'denied', 'code' => $exception->errorCode];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
