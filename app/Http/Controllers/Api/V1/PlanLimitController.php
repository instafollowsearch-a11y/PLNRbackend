<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plans\PlanQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanLimitController extends Controller
{
    public function show(Request $request, PlanQuotaService $quota): JsonResponse
    {
        $user = $request->user();
        $ip = $request->ip();

        return response()->json([
            'data' => [
                'limit' => $quota->monthlyLimit(),
                'remaining' => $quota->remainingThisMonth($user, $ip),
                'window' => 'month',
            ],
            'message' => 'Plan limits retrieved.',
        ]);
    }
}
