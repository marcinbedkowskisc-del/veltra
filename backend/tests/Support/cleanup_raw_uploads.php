<?php

use App\Domain\RawMaterials\Documents\RawMaterialUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing' || DB::scalar('SELECT current_user') !== 'veltra_app') {
    throw new RuntimeException('Fixture wymaga izolowanej bazy i ograniczonej roli.');
}
fwrite(STDOUT, (string) app(RawMaterialUploadService::class)->cleanupExpired());
