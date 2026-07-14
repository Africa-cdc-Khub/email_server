<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => 'ok',
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'cache' => $this->checkCache(),
        ];

        $healthy = collect($checks)
            ->except('app')
            ->every(fn (array $check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, message?: string, driver?: string}
     */
    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection()->ping();
            $driver = config('database.redis.client', 'phpredis');

            if ($pong === false || $pong === null) {
                return ['status' => 'error', 'message' => 'Redis ping failed', 'driver' => $driver];
            }

            return ['status' => 'ok', 'driver' => $driver];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, connection?: string, message?: string}
     */
    private function checkQueue(): array
    {
        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync') {
            return ['status' => 'ok', 'connection' => 'sync', 'message' => 'In-process (dev only)'];
        }

        if ($connection !== 'redis') {
            return ['status' => 'ok', 'connection' => $connection];
        }

        try {
            $size = Queue::connection('redis')->size('emails');

            return [
                'status' => 'ok',
                'connection' => 'redis',
                'emails_queue_depth' => $size,
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'connection' => 'redis', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, store?: string, message?: string}
     */
    private function checkCache(): array
    {
        $store = (string) config('cache.default', 'file');

        try {
            $key = 'health:probe:'.uniqid('', true);
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                return ['status' => 'error', 'store' => $store, 'message' => 'Cache read/write failed'];
            }

            return ['status' => 'ok', 'store' => $store];
        } catch (Throwable $e) {
            return ['status' => 'error', 'store' => $store, 'message' => $e->getMessage()];
        }
    }
}
