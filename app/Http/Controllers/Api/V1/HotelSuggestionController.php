<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\HotelSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotelSuggestionController extends Controller
{
    public function store(Request $request, HotelSuggestionService $service): JsonResponse
    {
        $destination = $request->validate([
            'destination' => ['required', 'string', 'min:2', 'max:255'],
        ])['destination'];

        try {
            $hotels = $service->suggest($destination);
        } catch (Throwable $exception) {
            Log::warning('hotel_suggestions.failed', [
                'destination' => $destination,
                'message' => $exception->getMessage(),
            ]);
            $hotels = [];
        }

        return response()->json([
            'data' => $hotels,
        ]);
    }
}
