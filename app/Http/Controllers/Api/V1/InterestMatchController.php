<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterestMatchResource;
use App\Models\InterestMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestMatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $matches = InterestMatch::query()
            ->with('event')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('score')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'matches' => InterestMatchResource::collection($matches)->resolve(),
            ],
            'message' => 'Interest matches retrieved.',
        ]);
    }
}
