<?php

namespace Tests\Feature;

use App\Domain\Installation\InstallationService;
use App\Models\LocationManagementAssignment;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManagementConcurrencyTest extends TestCase
{
    #[DataProvider('transactionOutcomes')]
    public function test_a_second_real_connection_waits_for_the_transaction_and_rechecks_management(bool $commit, bool $change): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $process = null;
        try {
            $service = app(InstallationService::class);
            $service->initializeOrganization(['name' => 'Concurrency Test', 'code' => 'CONCURRENCY']);
            $restaurant = $service->createLocation(['name' => 'Restaurant', 'code' => 'R1', 'type' => 'RESTAURANT']);
            $firstKitchen = $service->createLocation(['name' => 'Kitchen', 'code' => 'CK1', 'type' => 'CENTRAL_KITCHEN']);
            if ($change) {
                $service->assignManagingKitchen($restaurant, $firstKitchen);
                $service->updateLocation($restaurant, ['is_active' => true]);
                $otherRestaurant = $service->createLocation(['name' => 'Other restaurant', 'code' => 'R2', 'type' => 'RESTAURANT']);
            }
            DB::beginTransaction();
            $service->assignManagingKitchen($change ? $otherRestaurant : $restaurant, $firstKitchen);

            $applicationName = 'veltra-concurrency-'.bin2hex(random_bytes(6));
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/concurrent_assignment.php'), $restaurant->id, $firstKitchen->id, $applicationName, $change ? 'change' : 'assign'],
                base_path(),
                ['DB_DATABASE' => 'veltra_testing', 'APP_ENV' => 'testing'],
            );
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
            $this->assertTrue($waiting, 'The independent PostgreSQL session did not block on the installation lock. '.$process->getErrorOutput());
            $commit ? DB::commit() : DB::rollBack();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($commit || $change ? 'conflict' : 'assigned', $result['outcome']);
            $this->assertSame(1 + (int) ($commit && $change), LocationManagementAssignment::current()->count());
            $this->assertSame($firstKitchen->id, $service->currentManagingKitchen($restaurant)->id);
            $this->assertSame(1 + (int) ($commit && $change), LocationManagementAssignment::count());
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

    public static function transactionOutcomes(): array
    {
        return [
            'assign: first transaction commits' => [true, false],
            'assign: first transaction rolls back' => [false, false],
            'change: first transaction commits' => [true, true],
            'change: first transaction rolls back' => [false, true],
        ];
    }
}
