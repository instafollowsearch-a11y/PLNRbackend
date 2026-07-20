<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BookingConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'fee_cents' => (int) config('services.booking.fee_cents', 999),
                'currency' => (string) config('services.booking.currency', 'usd'),
            ],
            'message' => 'Booking configuration retrieved successfully.',
        ]);
    }
}
