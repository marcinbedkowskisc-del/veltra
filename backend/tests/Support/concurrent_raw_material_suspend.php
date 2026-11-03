<?php

use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Models\LocationMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'veltra_testing') {
    throw new RuntimeException('Concurrency fixture requires veltra_testing.');
}

[$script, $subjectType, $subjectId, $applicationName] = $argv;
if (! in_array($subjectType, ['user', 'membership'], true)
    || ! preg_match('/^[a-z0-9_-]{1,63}$/D', $applicationName)) {
    throw new InvalidArgumentException('Invalid suspension fixture arguments.');
}

DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);

if ($subjectType === 'user') {
    app(IdentityService::class)->disable(User::findOrFail($subjectId));
} else {
    app(MembershipService::class)->deactivate(
        LocationMembership::findOrFail($subjectId),
        'Test równoległego zawieszenia członkostwa',
    );
}

fwrite(STDOUT, json_encode(['completed' => true], JSON_THROW_ON_ERROR));
