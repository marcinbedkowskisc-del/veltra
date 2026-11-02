<?php

use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleConfigurationService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModuleCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $actorId, $contextId, $locationId, $applicationName] = $argv;
if (! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid PostgreSQL application name.');
}

$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);
$actor = User::findOrFail($actorId);
$context = Location::findOrFail($contextId);
$location = Location::findOrFail($locationId);
$result = app(ModuleConfigurationService::class)->configure($actor, $context, [
    'module_ids' => ['TEMPERATURES'],
    'location_ids' => [$location->id],
    'enabled' => true,
    'current_password' => 'Test-Only-Password!42',
]);

fwrite(STDOUT, json_encode(['outcome' => 'accepted', 'result' => $result], JSON_THROW_ON_ERROR));
