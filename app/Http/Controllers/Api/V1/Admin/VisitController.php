<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageVisitResource;
use App\Models\PageVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $query = PageVisit::query()->with('user')->orderByDesc('occurred_at')->orderByDesc('id');

        $audience = $request->string('audience')->toString();

        if ($audience === 'signed_in') {
            $query->whereNotNull('user_id');
        } elseif ($audience === 'guest') {
            $query->whereNull('user_id');
        }

        $plan = $request->string('plan')->toString();

        if (in_array($plan, [PageVisit::PLAN_FREE, PageVisit::PLAN_PRO, PageVisit::PLAN_GUEST], true)) {
            $query->where('plan', $plan);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $search = trim($request->string('search')->toString());

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('path', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($users) use ($search): void {
                        $users->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => [
                'visits' => PageVisitResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'message' => 'Visits retrieved.',
        ]);
    }
}
