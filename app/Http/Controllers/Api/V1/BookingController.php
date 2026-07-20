<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListBookingsRequest;
use App\Http\Requests\Api\V1\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\PlanSession;
use App\Services\Bookings\BookingService;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    public function index(ListBookingsRequest $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 20);

        $paginator = $request->user()
            ->bookings()
            ->with('planSession')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'bookings' => BookingResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'message' => 'Bookings retrieved successfully.',
        ]);
    }

    public function store(StoreBookingRequest $request, PlanSession $planSession, BookingService $bookingService): JsonResponse
    {
        $booking = $bookingService->createForSession($request->user(), $planSession);

        return response()->json([
            'data' => [
                'booking' => new BookingResource($booking),
            ],
            'message' => 'Booking confirmed.',
        ], 201);
    }
}
