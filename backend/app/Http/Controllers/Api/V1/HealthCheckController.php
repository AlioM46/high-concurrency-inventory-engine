<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $startTime = microtime(true);
        $dbStatus = 'disconnected';
        $redisStatus = 'disconnected';

        // Check PostgreSQL
        try {
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        // Check Redis
        try {
            Redis::ping();
            $redisStatus = 'connected';
        } catch (\Throwable $e) {
            $redisStatus = 'error: ' . $e->getMessage();
        }

        $isHealthy = $dbStatus === 'connected' && $redisStatus === 'connected';
        $latencyMs = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'status' => $isHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'latency_ms' => $latencyMs,
            'services' => [
                'database' => [
                    'driver' => 'postgresql',
                    'status' => $dbStatus,
                ],
                'cache_and_queues' => [
                    'driver' => 'redis',
                    'status' => $redisStatus,
                ],
            ],
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ], $isHealthy ? 200 : 503);
    }
}
