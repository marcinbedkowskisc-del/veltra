<?php

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossRawCatalogIntent;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Domain\RawMaterials\BossRawMaterialCatalogService;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing' || DB::scalar('SELECT current_user') !== 'veltra_app') {
    throw new RuntimeException('The fixture requires the isolated database and restricted runtime role.');
}

[
    $script,
    $kind,
    $action,
    $actorId,
    $contextId,
    $bossId,
    $membershipId,
    $identityVersion,
    $assignmentId,
    $expectedVersion,
    $applicationName,
    $barrier,
    $hold,
] = $argv;
if (! in_array($kind, ['read', 'write'], true)
    || ! preg_match('/^[a-z0-9-]{1,63}$/D', $applicationName)
    || ! ctype_digit($barrier)) {
    throw new InvalidArgumentException('Invalid BOSS raw catalog concurrency fixture arguments.');
}
DB::select('SELECT set_config(?, ?, false)', ['application_name', $applicationName]);
$app->instance(
    OrganizationCapabilityCatalog::class,
    OrganizationCapabilityCatalogFixtures::ready(),
);

$actor = User::findOrFail($actorId);
$context = Location::findOrFail($contextId);
$result = ['status' => 200];
$outerTransaction = $hold === '1' || $kind === 'read';

try {
    if ($outerTransaction) {
        DB::beginTransaction();
    }

    if ($kind === 'read') {
        $catalog = app(BossRawMaterialCatalogService::class);
        $result['data'] = $catalog->withAuthorizedRead(
            $actor,
            $context,
            $membershipId,
            (int) $identityVersion,
            BossRawCatalogIntent::List,
            function (User $freshActor, Location $freshContext) use (
                $catalog,
                $membershipId,
                $identityVersion,
                $barrier,
            ): array {
                DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$barrier]);

                return $catalog->listing(
                    $freshActor,
                    $freshContext,
                    $membershipId,
                    (int) $identityVersion,
                    [],
                );
            },
        );
    } else {
        switch ($action) {
            case 'role':
                app(RoleAdministration::class)->replace(
                    $actor,
                    $context,
                    User::findOrFail($bossId),
                    BusinessRole::CentralKitchenChef,
                    [$context->id],
                );
                break;
            case 'membership':
                app(MembershipService::class)->deactivate(
                    LocationMembership::findOrFail($membershipId),
                    'BOSS raw catalog concurrency test',
                    $actor->id,
                );
                break;
            case 'restriction':
                app(BossLocationRestrictions::class)->replace(
                    $actor,
                    $context,
                    $bossId,
                    (int) $expectedVersion,
                    [],
                    ['RAW_SUPPLIERS'],
                    [],
                );
                break;
            case 'capability':
                app(OrganizationCapabilityService::class)->configure(
                    $actor,
                    $context,
                    CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
                    Str::uuid()->toString(),
                    [
                        'enabled' => false,
                        'expected_state_version' => (int) $expectedVersion,
                        'current_password' => 'Test-Only-Password!42',
                    ],
                );
                break;
            default:
                throw new InvalidArgumentException('Unknown BOSS raw catalog writer action.');
        }
        if ($hold === '1') {
            DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$barrier]);
        }
    }

    if ($outerTransaction) {
        DB::commit();
    }
} catch (HttpExceptionInterface $exception) {
    $result = ['status' => $exception->getStatusCode()];
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
} catch (AuthorizationException) {
    $result = ['status' => 403];
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
} catch (QueryException $exception) {
    $result = ['status' => 503, 'sqlstate' => $exception->errorInfo[0] ?? null];
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
