<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleConfigurationService;
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
        $process = null;

        try {
            $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
            $installation = app(InstallationService::class);
            $organization = $installation->initializeOrganization(['name' => 'Modules', 'code' => 'MODULES']);
            $kitchen = $installation->createLocation(['name' => 'Kitchen', 'code' => 'CK', 'type' => 'CENTRAL_KITCHEN']);
            $restaurant = $installation->createLocation(['name' => 'Restaurant', 'code' => 'R1', 'type' => 'RESTAURANT']);
            $installation->assignManagingKitchen($restaurant, $kitchen);
            $installation->updateLocation($restaurant, ['is_active' => true]);
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

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $first = app(ModuleConfigurationService::class)->configure($actor, $kitchen, [
                'module_ids' => ['TEMPERATURES'],
                'location_ids' => [$restaurant->id],
                'enabled' => true,
                'current_password' => 'Test-Only-Password!42',
            ]);
            $applicationName = 'module-'.Str::lower(Str::random(10));
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_module_configuration.php'),
                $actor->id,
                $kitchen->id,
                $restaurant->id,
                $applicationName,
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $process->setTimeout(20);
            $process->start();

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

            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $second = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('accepted', $second['outcome']);
            $this->assertSame(1, $first['changed_count']);
            $this->assertSame(0, $second['result']['changed_count']);
            $this->assertSame(1, DB::table('location_module_states')->where('module_key', 'TEMPERATURES')->count());
            $this->assertSame(1, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_transition')->count());
            $this->assertSame(2, DB::table('security_events')->where('module_key', 'TEMPERATURES')
                ->where('event_kind', 'module_attempt')->where('outcome', 'accepted')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            DB::statement('TRUNCATE organizations CASCADE');
        }
    }
}
