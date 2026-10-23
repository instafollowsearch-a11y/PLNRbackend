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
        $isPro = (bool) $user?->isPro();
        $limit = $quota->monthlyLimit();
        $used = $quota->usedThisMonth($user, $ip);

        return response()->json([
            'data' => [
                'limit' => $limit,
                'used' => $used,
                'remaining' => $isPro ? null : $quota->remainingThisMonth($user, $ip),
                'is_pro' => $isPro,
                'unlimited' => $isPro,
                'window' => 'month',
            ],
            'message' => 'Plan limits retrieved.',
        ]);
    }
}
