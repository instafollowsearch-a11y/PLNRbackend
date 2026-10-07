<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateBillingCheckoutRequest;
use App\Http\Requests\Api\V1\CreateBillingPortalRequest;
use App\Http\Requests\Api\V1\VerifyAppleSubscriptionRequest;
use App\Http\Requests\Api\V1\VerifyPlaySubscriptionRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Billing\AppleSubscriptionService;
use App\Services\Billing\PlaySubscriptionService;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlaySubscriptionService $playSubscriptions,
        private readonly AppleSubscriptionService $appleSubscriptions,
    ) {}

    public function checkout(CreateBillingCheckoutRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = $this->subscriptions->createCheckoutSession(
            $user,
            (string) $request->validated('success_url'),
            (string) $request->validated('cancel_url'),
        );

        return response()->json([
            'data' => [
                'checkout_url' => $session['url'],
                'session_id' => $session['id'],
            ],
            'message' => 'Checkout session created.',
        ]);
    }

    public function portal(CreateBillingPortalRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = $this->subscriptions->createPortalSession(
            $user,
            (string) $request->validated('return_url'),
        );

        return response()->json([
            'data' => [
                'portal_url' => $session['url'],
            ],
            'message' => 'Billing portal session created.',
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $this->subscriptions->cancelSubscription($user);

        return response()->json([
            'data' => [
                'user' => new UserResource($updated),
            ],
            'message' => 'Pro subscription canceled.',
        ]);
    }

    public function play(VerifyPlaySubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $this->playSubscriptions->grant(
            $user,
            (string) $request->validated('product_id'),
            (string) $request->validated('purchase_token'),
        );

        return response()->json([
            'data' => [
                'user' => new UserResource($updated),
            ],
            'message' => 'Google Play subscription verified.',
        ]);
    }

    public function apple(VerifyAppleSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $this->appleSubscriptions->grant(
            $user,
            (string) $request->validated('product_id'),
            (string) $request->validated('transaction_id'),
        );

        return response()->json([
            'data' => [
                'user' => new UserResource($updated),
            ],
            'message' => 'App Store subscription verified.',
        ]);
    }
}
