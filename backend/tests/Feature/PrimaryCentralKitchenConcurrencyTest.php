<?php

namespace Tests\Feature;

use App\Domain\Installation\InstallationService;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PrimaryCentralKitchenConcurrencyTest extends TestCase
{
    #[DataProvider('firstTransactionOutcomes')]
    public function test_concurrent_creation_uses_the_first_committed_central_kitchen(bool $commitFirst): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $process = null;

        try {
            $service = app(InstallationService::class);
            $service->initializeOrganization(['name' => 'Primary Kitchen Test', 'code' => 'PRIMARY']);

            DB::beginTransaction();
            $first = $service->createLocation([
                'name' => 'First Kitchen',
                'code' => 'CK1',
                'type' => 'CENTRAL_KITCHEN',
            ]);

            $applicationName = 'primary-kitchen-'.bin2hex(random_bytes(6));
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_create_location.php'),
                'CK2',
                $applicationName,
                'immediate',
                'true',
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $process->setTimeout(30);
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

            $this->assertTrue($waiting, 'The independent PostgreSQL session did not wait for the organization lock. '.$process->getErrorOutput());
            $commitFirst ? DB::commit() : DB::rollBack();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            $second = Location::where('code', 'CK2')->firstOrFail();
            $this->assertSame($commitFirst, (bool) $first->fresh()?->is_primary_central_kitchen);
            $this->assertSame(! $commitFirst, $second->is_primary_central_kitchen);
            $this->assertSame(1, Location::where('is_primary_central_kitchen', true)->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            $this->resetTestDatabase();
        }
    }

    public function test_concurrent_primary_deactivation_is_rejected_after_an_authorized_location_update(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $process = null;

        try {
            $service = app(InstallationService::class);
            $service->initializeOrganization(['name' => 'Primary Kitchen Test', 'code' => 'PRIMARY']);
            $primary = $service->createLocation([
                'name' => 'Primary Kitchen',
                'code' => 'CK1',
                'type' => 'CENTRAL_KITCHEN',
            ]);

            DB::beginTransaction();
            $service->updateLocation($primary, ['name' => 'Authorized rename']);
            $applicationName = 'primary-disable-'.bin2hex(random_bytes(6));
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_deactivate_primary_kitchen.php'),
                $primary->id,
                $applicationName,
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $process->setTimeout(30);
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

            $this->assertTrue($waiting, 'The independent PostgreSQL session did not wait for the organization lock. '.$process->getErrorOutput());
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('rejected', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $this->assertTrue($primary->fresh()->is_active);
            $this->assertSame('Authorized rename', $primary->fresh()->name);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            $this->resetTestDatabase();
        }
    }

    public static function firstTransactionOutcomes(): array
    {
        return [
            'first kitchen commits' => [true],
            'first kitchen rolls back' => [false],
        ];
    }
}
