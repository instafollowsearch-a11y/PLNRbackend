<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\NominatimClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function search(Request $request, NominatimClient $client): JsonResponse
    {
        $query = $request->string('q')->trim()->toString();

        return response()->json([
            'data' => $client->search($query),
            'message' => 'Location search results.',
        ]);
    }

    public function reverse(Request $request, NominatimClient $client): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'data' => $client->reverse((float) $validated['lat'], (float) $validated['lon']),
            'message' => 'Location resolved.',
        ]);
    }
}
