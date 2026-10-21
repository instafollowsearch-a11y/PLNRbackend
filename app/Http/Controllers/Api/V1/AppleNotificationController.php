<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\AppleBillingException;
use App\Services\Billing\AppleNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppleNotificationController extends Controller
{
    public function __construct(
        private readonly AppleNotificationService $notifications,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $this->notifications->handle($request->all());
        } catch (AppleBillingException $exception) {
            Log::warning('apple.iap.notification.rejected', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid App Store notification.'], 400);
        }

        return response()->json(['received' => true]);
    }
}
