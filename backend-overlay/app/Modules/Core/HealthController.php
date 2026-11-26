<?php

namespace App\Modules\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            return response()->json(['status' => 'degraded', 'database' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }
}
