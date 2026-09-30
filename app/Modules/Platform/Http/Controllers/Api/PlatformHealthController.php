<?php

namespace App\Modules\Platform\Http\Controllers\Api;

use App\Core\Health\HealthChecker;
use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\PlatformMetrics;
use Illuminate\Http\JsonResponse;

class PlatformHealthController extends Controller
{
    public function health(HealthChecker $checker): JsonResponse
    {
        $results = $checker->run();

        return response()->json([
            'status' => $checker->healthy($results) ? 'ok' : 'degraded',
            'stage' => config('aivexa.stage'),
            'checks' => $results,
            'php' => PHP_VERSION,
            'time' => now()->toIso8601String(),
        ], $checker->healthy($results) ? 200 : 503);
    }

    public function metrics(PlatformMetrics $metrics): JsonResponse
    {
        return response()->json(['data' => $metrics->summary()]);
    }
}
