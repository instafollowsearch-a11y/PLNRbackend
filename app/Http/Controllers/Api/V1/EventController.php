<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEventsRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function index(ListEventsRequest $request): JsonResponse
    {
        $city = $request->resolvedCity();

        if ($city === null || $city === '') {
            return response()->json([
                'message' => 'City is required. Set your profile city or pass ?city=',
                'errors' => [
                    'city' => ['City is required.'],
                ],
            ], 422);
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        $paginator = Event::query()
            ->forCity($city)
            ->upcoming()
            ->orderBy('starts_at')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'events' => EventResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'message' => 'Events retrieved successfully.',
        ]);
    }
}
