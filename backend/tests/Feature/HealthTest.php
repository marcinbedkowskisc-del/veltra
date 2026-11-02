<?php

namespace Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_it_reports_healthy_dependencies_without_authentication(): void
    {
        $this->mockDependencies(true, true);
        Log::shouldReceive('error')->never();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'app' => 'VELTRA 2.0',
                'status' => 'ok',
                'checks' => ['postgresql' => 'up', 'redis' => 'up'],
            ]);
    }

    #[DataProvider('dependencyFailures')]
    public function test_it_reports_and_logs_dependency_failures(
        bool $postgresUp,
        bool $redisUp,
        bool $invalidPong = false,
    ): void {
        $this->mockDependencies($postgresUp, $redisUp, $invalidPong);
        Log::shouldReceive('error')
            ->times((int) ! $postgresUp + (int) (! $redisUp || $invalidPong))
            ->with('Health check failed.', Mockery::on(
                fn (array $context): bool => in_array($context['service'], ['postgresql', 'redis'], true)
                    && $context['exception'] instanceof RuntimeException,
            ));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'app' => 'VELTRA 2.0',
                'status' => 'degraded',
                'checks' => [
                    'postgresql' => $postgresUp ? 'up' : 'down',
                    'redis' => $redisUp && ! $invalidPong ? 'up' : 'down',
                ],
            ]);
    }

    public static function dependencyFailures(): array
    {
        return [
            'PostgreSQL unavailable' => [false, true],
            'Redis unavailable' => [true, false],
            'both unavailable' => [false, false],
            'invalid Redis PING response' => [true, true, true],
        ];
    }

    private function mockDependencies(bool $postgresUp, bool $redisUp, bool $invalidPong = false): void
    {
        $database = Mockery::mock(ConnectionInterface::class);
        $query = $database->shouldReceive('select')->once()->with('SELECT 1');
        if ($postgresUp) {
            $query->andReturn([(object) ['result' => 1]]);
        } else {
            $query->andThrow(new RuntimeException('Private database error details.'));
        }
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($database);

        $redis = Mockery::mock(Connection::class);
        $ping = $redis->shouldReceive('ping')->once()->withNoArgs();
        if ($redisUp) {
            $ping->andReturn(! $invalidPong);
        } else {
            $ping->andThrow(new RuntimeException('Private Redis error details.'));
        }
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);
    }
}
