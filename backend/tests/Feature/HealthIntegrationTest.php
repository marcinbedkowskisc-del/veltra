<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthIntegrationTest extends TestCase
{
    public function test_health_with_real_postgresql_and_redis_connections(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson([
            'app' => 'VELTRA 2.0',
            'status' => 'ok',
            'checks' => ['postgresql' => 'up', 'redis' => 'up'],
        ]);
    }

    public function test_redis_cache_and_queue_are_available_without_infrastructure_tables(): void
    {
        $key = 'health-integration:'.bin2hex(random_bytes(8));

        try {
            $this->assertTrue(Cache::store('redis')->put($key, 'available', 30));
            $this->assertSame('available', Cache::store('redis')->get($key));
            $this->assertSame(0, Queue::connection('redis')->size($key));
            $this->assertContains(Redis::connection('default')->ping(), [true, 'PONG', '+PONG']);
            $tables = DB::connection('pgsql')->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename IN ('cache', 'jobs', 'sessions')",
            );
            $this->assertSame([], $tables);
        } finally {
            Cache::store('redis')->forget($key);
        }
    }
}
