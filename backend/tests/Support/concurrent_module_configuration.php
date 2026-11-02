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

[$script, $actorId, $contextId, $locationIdsJson, $enabledValue, $applicationName, $targetMode] = array_pad($argv, 7, 'explicit');
if (! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid PostgreSQL application name.');
}
$locationIds = json_decode($locationIdsJson, true, flags: JSON_THROW_ON_ERROR);
if (! is_array($locationIds) || ($targetMode === 'explicit' && $locationIds === [])
    || array_filter($locationIds, fn (mixed $id): bool => ! is_string($id)) !== []) {
    throw new InvalidArgumentException('Invalid target location list.');
}
if (! in_array($targetMode, ['explicit', 'all-compatible'], true)) {
    throw new InvalidArgumentException('Invalid target selection mode.');
}
$enabled = match ($enabledValue) {
    'true' => true,
    'false' => false,
    default => throw new InvalidArgumentException('Invalid requested state.'),
};

$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);
$actor = User::findOrFail($actorId);
$context = Location::findOrFail($contextId);
$input = [
    'module_ids' => ['TEMPERATURES'],
    'enabled' => $enabled,
    'current_password' => 'Test-Only-Password!42',
];
if ($targetMode === 'explicit') {
    $input['location_ids'] = $locationIds;
} else {
    $input['all_compatible_locations'] = true;
}
$result = app(ModuleConfigurationService::class)->configure($actor, $context, $input);

fwrite(STDOUT, json_encode(['outcome' => 'accepted', 'result' => $result], JSON_THROW_ON_ERROR));
