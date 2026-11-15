<?php

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\SystemPermissionManifest;
use App\Domain\RawMaterials\RawMaterialPermission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPermissionProvisioningFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || env('DB_DATABASE') !== 'veltra_testing'
    || env('TEST_DB_SETUP_USERNAME') !== 'veltra_migrator' || count($argv) !== 6) {
    throw new RuntimeException('This worker is restricted to the disposable test database.');
}
config([
    'database.connections.pgsql.username' => env('TEST_DB_SETUP_USERNAME'),
    'database.connections.pgsql.password' => env('TEST_DB_SETUP_PASSWORD'),
]);
DB::purge('pgsql');
DB::statement("SELECT set_config('application_name', ?, false)", [$argv[5]]);

$manifest = new SystemPermissionManifest(
    id: $argv[1], version: 1, module: 'RAW_MATERIALS',
    permissions: [RawMaterialPermission::View, RawMaterialPermission::Create],
    eligibleRole: BusinessRole::Administrator, grantScope: 'ORGANIZATION', applicability: 'UPGRADE',
    databaseName: 'veltra_testing', organizationId: $argv[2], administratorId: $argv[3], locationId: $argv[4],
);
$provisioner = SystemPermissionProvisioningFixture::provisioner($manifest);
$approval = $provisioner->releaseDigest($manifest->id, $manifest->version);
echo $provisioner->execute($manifest->id, $manifest->version, $approval), PHP_EOL;
