<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'postgresql' => $this->check('postgresql', function (): void {
                DB::connection('pgsql')->select('SELECT 1');
            }),
            'redis' => $this->check('redis', function (): void {
                $pong = Redis::connection('default')->ping();

                if (! in_array($pong, [true, 'PONG', '+PONG'], true)) {
                    throw new RuntimeException('Redis returned an unexpected PING response.');
                }
            }),
        ];
        $healthy = ! in_array('down', $checks, true);

        return response()->json([
            'app' => 'VELTRA 2.0',
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function check(string $service, Closure $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (Throwable $exception) {
            Log::error('Health check failed.', [
                'service' => $service,
                'exception' => $exception,
            ]);

            return 'down';
        }
    }
}
