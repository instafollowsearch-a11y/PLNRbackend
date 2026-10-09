<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\HotelSuggestionService;
use App\Support\Http\RunAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotelSuggestionController extends Controller
{
    public function store(Request $request, HotelSuggestionService $service): JsonResponse
    {
        $destination = $request->validate([
            'destination' => ['required', 'string', 'min:2', 'max:255'],
        ])['destination'];

        $cacheKey = 'hotel-suggestions.'.sha1(mb_strtolower($destination));
        $cached = Cache::get($cacheKey);

        if ($cached === 'generating') {
            return response()->json([
                'data' => ['status' => 'generating'],
                'message' => 'Still working.',
            ], 202);
        }

        if (is_array($cached)) {
            Cache::forget($cacheKey);

            return response()->json([
                'data' => $cached,
            ]);
        }

        Cache::put($cacheKey, 'generating', now()->addMinutes(5));

        try {
            $accepted = RunAfterResponse::defer(function () use ($service, $destination, $cacheKey): void {
                try {
                    $hotels = $service->suggest($destination);
                } catch (Throwable $exception) {
                    Log::warning('hotel_suggestions.failed', [
                        'destination' => $destination,
                        'message' => $exception->getMessage(),
                    ]);
                    $hotels = [];
                }

                Cache::put($cacheKey, $hotels, now()->addMinutes(5));
            });
        } catch (Throwable $exception) {
            Log::warning('hotel_suggestions.failed', [
                'destination' => $destination,
                'message' => $exception->getMessage(),
            ]);
            Cache::put($cacheKey, [], now()->addMinutes(5));

            return response()->json([
                'data' => [],
            ]);
        }

        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $hotels = Cache::pull($cacheKey);

        return response()->json([
            'data' => is_array($hotels) ? $hotels : [],
        ]);
    }
}
