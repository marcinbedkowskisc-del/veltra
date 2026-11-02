<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleConfigurationService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ModuleCatalogFixtures;
use Tests\TestCase;

class ModuleConfigurationConcurrencyTest extends TestCase
{
    public function test_overlapping_postgresql_updates_serialize_and_retry_idempotently(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        DB::statement('TRUNCATE organizations CASCADE');
        $processes = [];

        try {
            [$organization, $kitchen, $restaurants, $actor] = $this->createFixture(['R1']);
            $restaurant = $restaurants[0];

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $first = $this->configure($actor, $kitchen, [$restaurant], true);
            [$process, $applicationName] = $this->startProcess($actor, $kitchen, [$restaurant], true);
            $processes[] = $process;
            $this->assertWaitingOnOrganizationLock($process, $applicationName);

            DB::commit();
            $second = $this->finishProcess($process);

            $this->assertSame('accepted', $second['outcome']);
            $this->assertSame(1, $first['changed_count']);
            $this->assertSame(0, $second['result']['changed_count']);
            $this->assertSame(1, DB::table('location_module_states')->where('module_key', 'TEMPERATURES')->count());
            $this->assertSame(1, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_transition')->count());
            $this->assertSame(2, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_attempt')->where('outcome', 'accepted')->count());
        } finally {
            $this->cleanup($processes);
        }
    }

    public function test_simultaneous_enable_and_disable_operations_preserve_serial_state_and_audit(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        DB::statement('TRUNCATE organizations CASCADE');
        $processes = [];

        try {
            [$organization, $kitchen, $restaurants, $actor] = $this->createFixture(['R1']);
            $restaurant = $restaurants[0];
            $this->configure($actor, $kitchen, [$restaurant], true);

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$disableProcess, $disableName] = $this->startProcess($actor, $kitchen, [$restaurant], false);
            $processes[] = $disableProcess;
            $this->assertWaitingOnOrganizationLock($disableProcess, $disableName);
            [$enableProcess, $enableName] = $this->startProcess($actor, $kitchen, [$restaurant], true);
            $processes[] = $enableProcess;
            $this->assertWaitingOnOrganizationLock($enableProcess, $enableName);

            DB::commit();
            $disable = $this->finishProcess($disableProcess);
            $enable = $this->finishProcess($enableProcess);

            $this->assertSame('accepted', $disable['outcome']);
            $this->assertSame('accepted', $enable['outcome']);
            $this->assertSame(1, $disable['result']['changed_count']);
            $this->assertSame(1, $enable['result']['changed_count']);
            $this->assertTrue((bool) DB::table('location_module_states')
                ->where('location_id', $restaurant->id)->where('module_key', 'TEMPERATURES')->value('enabled'));
            $this->assertSame(3, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_transition')->count());
            $this->assertSame(3, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_attempt')->where('outcome', 'accepted')->count());
        } finally {
            $this->cleanup($processes);
        }
    }

    public function test_partially_overlapping_bulk_requests_write_each_transition_once(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        DB::statement('TRUNCATE organizations CASCADE');
        $processes = [];

        try {
            [$organization, $kitchen, $restaurants, $actor] = $this->createFixture(['R1', 'R2', 'R3']);
            [$firstOnly, $overlap, $secondOnly] = $restaurants;

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$firstProcess, $firstName] = $this->startProcess($actor, $kitchen, [$firstOnly, $overlap], true);
            $processes[] = $firstProcess;
            $this->assertWaitingOnOrganizationLock($firstProcess, $firstName);
            [$secondProcess, $secondName] = $this->startProcess($actor, $kitchen, [$overlap, $secondOnly], true);
            $processes[] = $secondProcess;
            $this->assertWaitingOnOrganizationLock($secondProcess, $secondName);

            DB::commit();
            $first = $this->finishProcess($firstProcess);
            $second = $this->finishProcess($secondProcess);

            $this->assertSame(2, $first['result']['changed_count']);
            $this->assertSame(1, $second['result']['changed_count']);
            $this->assertSame(1, $second['result']['unchanged_count']);
            $this->assertSame(3, DB::table('location_module_states')->where('module_key', 'TEMPERATURES')->count());
            $this->assertSame(3, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_transition')->count());
            $this->assertSame(4, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_attempt')->where('outcome', 'accepted')->count());
            $this->assertSame(1, DB::table('security_events')->where('location_id', $overlap->id)
                ->where('event_kind', 'module_transition')->count());
        } finally {
            $this->cleanup($processes);
        }
    }

    /**
     * @param  list<string>  $restaurantCodes
     * @return array{Organization, Location, list<Location>, User}
     */
    private function createFixture(array $restaurantCodes): array
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $installation = app(InstallationService::class);
        $organization = $installation->initializeOrganization(['name' => 'Modules', 'code' => 'MODULES']);
        $kitchen = $installation->createLocation(['name' => 'Kitchen', 'code' => 'CK', 'type' => 'CENTRAL_KITCHEN']);
        $restaurants = [];
        foreach ($restaurantCodes as $code) {
            $restaurant = $installation->createLocation(['name' => $code, 'code' => $code, 'type' => 'RESTAURANT']);
            $installation->assignManagingKitchen($restaurant, $kitchen);
            $restaurants[] = $installation->updateLocation($restaurant, ['is_active' => true]);
        }

        $actor = User::create([
            'organization_id' => $organization->id,
            'first_name' => 'Concurrency',
            'last_name' => 'Administrator',
            'email' => 'module-admin@example.test',
            'password' => 'Test-Only-Password!42',
        ]);
        $actor->forceFill(['email_verified_at' => now()])->save();
        app(MembershipService::class)->assign($actor, $kitchen);
        RoleAssignment::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'role' => BusinessRole::Administrator,
            'location_id' => null,
            'membership_id' => null,
        ]);

        return [$organization, $kitchen, $restaurants, $actor];
    }

    /**
     * @param  list<Location>  $locations
     * @return array<string, mixed>
     */
    private function configure(User $actor, Location $context, array $locations, bool $enabled): array
    {
        return app(ModuleConfigurationService::class)->configure($actor, $context, [
            'module_ids' => ['TEMPERATURES'],
            'location_ids' => array_map(fn (Location $location): string => $location->id, $locations),
            'enabled' => $enabled,
            'current_password' => 'Test-Only-Password!42',
        ]);
    }

    /**
     * @param  list<Location>  $locations
     * @return array{0: Process, 1: string}
     */
    private function startProcess(User $actor, Location $context, array $locations, bool $enabled): array
    {
        $applicationName = 'module-'.Str::lower(Str::random(10));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_module_configuration.php'),
            $actor->id,
            $context->id,
            json_encode(array_map(fn (Location $location): string => $location->id, $locations), JSON_THROW_ON_ERROR),
            $enabled ? 'true' : 'false',
            $applicationName,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
        $process->setTimeout(30);
        $process->start();

        return [$process, $applicationName];
    }

    private function assertWaitingOnOrganizationLock(Process $process, string $applicationName): void
    {
        $deadline = microtime(true) + 10;
        $waiting = false;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (bool) DB::scalar(
                "SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')",
                [$applicationName],
            );
            if ($waiting || ! $process->isRunning()) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->assertTrue($waiting, $process->getErrorOutput().$process->getOutput());
    }

    /**
     * @return array<string, mixed>
     */
    private function finishProcess(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<Process>  $processes
     */
    private function cleanup(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::statement('TRUNCATE organizations CASCADE');
    }
}
