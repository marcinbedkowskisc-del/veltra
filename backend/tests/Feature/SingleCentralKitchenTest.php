<?php

namespace Tests\Feature;

use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\InstallationTestCase;

class SingleCentralKitchenTest extends InstallationTestCase
{
    public function test_first_kitchen_and_many_restaurants_are_allowed_but_a_second_kitchen_is_rejected(): void
    {
        $organization = $this->initialize();
        $first = $this->kitchen();
        $this->restaurant();
        $this->restaurant('R2');
        $this->assertSame(3, $organization->locations()->count());
        $this->assertTrue($first->fresh()->is_primary_central_kitchen);
        $this->assertDatabaseRejects('23505', fn (Connection $connection) => Location::on('pgsql_setup')->create([
            'organization_id' => $organization->id, 'name' => 'Second', 'code' => 'CK2',
            'type' => 'CENTRAL_KITCHEN', 'is_active' => true,
        ]));
        $this->expectException(BusinessRuleViolation::class);
        $this->kitchen('CK2');
    }

    public function test_primary_central_kitchen_cannot_be_deactivated_or_replaced(): void
    {
        $organization = $this->initialize();
        $primary = $this->kitchen();
        try {
            $this->service->updateLocation($primary, ['is_active' => false]);
            $this->fail('The primary central kitchen was deactivated.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame(__('messages.primary_central_kitchen_must_remain_active'), $exception->getMessage());
        }
        $this->assertTrue($primary->fresh()->is_active);
        $this->assertDatabaseRejects('23505', fn (Connection $connection) => Location::on('pgsql_setup')->create([
            'organization_id' => $organization->id, 'name' => 'Second', 'code' => 'CK2',
            'type' => 'CENTRAL_KITCHEN', 'is_active' => true,
        ]));
        $this->assertSame(1, Location::where('type', 'CENTRAL_KITCHEN')->count());
    }

    #[DataProvider('transactionOutcomes')]
    public function test_concurrent_kitchen_creation_is_serialized(bool $commit): void
    {
        $this->initialize();
        $process = null;
        try {
            DB::beginTransaction();
            $this->kitchen();
            $name = 'veltra-kitchen-'.bin2hex(random_bytes(6));
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/concurrent_kitchen.php'), $name],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing'],
            );
            $process->setTimeout(20);
            $process->start();
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')", [$name]);
                if ($waiting || ! $process->isRunning()) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, $process->getErrorOutput());
            $commit ? DB::commit() : DB::rollBack();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame($commit ? 'conflict' : 'created', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $this->assertSame(1, Location::where('type', 'CENTRAL_KITCHEN')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
        }
    }

    public static function transactionOutcomes(): array
    {
        return ['first commit' => [true], 'first rollback' => [false]];
    }
}
