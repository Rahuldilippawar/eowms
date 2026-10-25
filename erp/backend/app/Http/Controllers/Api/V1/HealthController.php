<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Liveness + database reachability, for deploy smoke tests.
     * Reveals nothing beyond "ok" / "down".
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'down';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
        ], $database === 'ok' ? 200 : 503);
    }
}
