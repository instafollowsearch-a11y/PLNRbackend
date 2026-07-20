<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'version' => config('app.version', '0.1.0'),
            ],
            'message' => 'API is healthy.',
        ]);
    }
}
