<?php

use App\Domain\Authorization\RoleAdministration;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[3]]);
$user = User::findOrFail($argv[1]);
$context = Location::findOrFail($argv[2]);
try {
    app(RoleAdministration::class)->setAccountActive($user, $context, $user, false);
    echo json_encode(['outcome' => 'accepted']);
} catch (AuthorizationException) {
    echo json_encode(['outcome' => 'rejected']);
}
