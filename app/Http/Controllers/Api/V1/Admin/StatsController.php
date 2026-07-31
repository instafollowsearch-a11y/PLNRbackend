<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'users_total' => User::query()->count(),
                'admins_total' => User::query()->where('role', User::ROLE_ADMIN)->count(),
                'plan_sessions_today' => PlanSession::query()
                    ->where('created_at', '>=', now()->startOfDay())
                    ->count(),
                'plan_sessions_total' => PlanSession::query()->count(),
            ],
            'message' => 'Admin stats retrieved.',
        ]);
    }
}
