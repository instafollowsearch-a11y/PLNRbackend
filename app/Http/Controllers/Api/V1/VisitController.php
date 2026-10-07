<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePageVisitRequest;
use App\Http\Resources\PageVisitResource;
use App\Services\Visits\VisitRecorder;
use Illuminate\Http\JsonResponse;

class VisitController extends Controller
{
    public function store(StorePageVisitRequest $request, VisitRecorder $recorder): JsonResponse
    {
        $visit = $recorder->record($request);

        if ($visit === null) {
            return response()->json([
                'data' => null,
                'message' => 'Visit already recorded.',
            ]);
        }

        $visit->load('user');

        return response()->json([
            'data' => [
                'visit' => new PageVisitResource($visit),
            ],
            'message' => 'Visit recorded.',
        ], 201);
    }
}
