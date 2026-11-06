<?php

use App\Domain\Sku\SkuAllocationService;
use App\Domain\Sku\SkuPrefix;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}

[$script, $actorId, $sku, $subjectId, $applicationName] = $argv;
if (! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid PostgreSQL application name.');
}
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

$actor = User::findOrFail($actorId);
$reservedSku = DB::transaction(
    fn (): string => app(SkuAllocationService::class)->reserveImported(
        $actor,
        SkuPrefix::Raw,
        $sku,
        $subjectId,
    ),
);

fwrite(STDOUT, json_encode(['status' => 'reserved', 'sku' => $reservedSku], JSON_THROW_ON_ERROR));
