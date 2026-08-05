<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateBillingCheckoutRequest;
use App\Http\Requests\Api\V1\CreateBillingPortalRequest;
use App\Http\Resources\UserResource;
use App\Services\Payments\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function checkout(CreateBillingCheckoutRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
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
        /** @var \App\Models\User $user */
        $user = $request->user();

        $session = $this->subscriptions->createPortalSession(
            $user,
            (string) $request->validated('return_url'),
        );

        return response()->json([
            'data' => [
                'portal_url' => $session['url'],
                'fake' => (bool) ($session['fake'] ?? false),
            ],
            'message' => 'Billing portal session created.',
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $updated = $this->subscriptions->cancelSubscription($user);

        return response()->json([
            'data' => [
                'user' => new UserResource($updated),
            ],
            'message' => 'Pro subscription canceled.',
        ]);
    }
}
