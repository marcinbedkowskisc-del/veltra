<?php

use App\Domain\Installation\InstallationService;
use App\Models\Organization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $code, $applicationName, $mode, $commitValue, $readyFile, $releaseFile] = array_pad($argv, 7, null);
if (! preg_match('/^[A-Z][A-Z0-9]{0,9}$/D', $code)
    || ! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)
    || ! in_array($mode, ['immediate', 'paused'], true)) {
    throw new InvalidArgumentException('Invalid concurrent location creation arguments.');
}
$commit = match ($commitValue) {
    'true' => true,
    'false' => false,
    default => throw new InvalidArgumentException('Invalid transaction outcome.'),
};
if ($mode === 'paused') {
    $temporaryDirectory = realpath(sys_get_temp_dir());
    if (! is_string($temporaryDirectory)
        || ! is_string($readyFile)
        || ! is_string($releaseFile)
        || dirname($readyFile) !== $temporaryDirectory
        || dirname($releaseFile) !== $temporaryDirectory) {
        throw new InvalidArgumentException('Invalid transaction coordination paths.');
    }
}

DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);
DB::beginTransaction();
try {
    Organization::query()->lockForUpdate()->firstOrFail();
    $location = app(InstallationService::class)->createLocation([
        'name' => $code,
        'code' => $code,
        'type' => 'CENTRAL_KITCHEN',
    ]);

    if ($mode === 'paused') {
        file_put_contents($readyFile, $location->id, LOCK_EX);
        $deadline = microtime(true) + 15;
        while (! is_file($releaseFile) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (! is_file($releaseFile)) {
            throw new RuntimeException('Timed out waiting for the test transaction release.');
        }
    }

    if ($commit) {
        DB::commit();
    } else {
        DB::rollBack();
    }
    fwrite(STDOUT, json_encode([
        'location_id' => $location->id,
        'committed' => $commit,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    throw $exception;
}
