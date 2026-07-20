<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function setupIntent(Request $request, PaymentGateway $paymentGateway): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $paymentGateway->ensureCustomer($user);
        $intent = $paymentGateway->createSetupIntent($customerId);

        return response()->json([
            'data' => $intent,
            'message' => 'Setup intent created.',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $methods = $request->user()
            ->paymentMethods()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'payment_methods' => PaymentMethodResource::collection($methods)->resolve(),
            ],
            'message' => 'Payment methods retrieved successfully.',
        ]);
    }

    public function store(StorePaymentMethodRequest $request, PaymentGateway $paymentGateway): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $customerId = $paymentGateway->ensureCustomer($user);
        $paymentMethodId = $request->string('payment_method_id')->toString();

        $details = $paymentGateway->attachPaymentMethod($customerId, $paymentMethodId);

        $user->paymentMethods()->update(['is_default' => false]);

        $method = $user->paymentMethods()->create([
            'stripe_payment_method_id' => $paymentMethodId,
            'brand' => $details['brand'],
            'last4' => $details['last4'],
            'exp_month' => $details['exp_month'],
            'exp_year' => $details['exp_year'],
            'is_default' => true,
        ]);

        return response()->json([
            'data' => [
                'payment_method' => new PaymentMethodResource($method),
            ],
            'message' => 'Payment method saved.',
        ], 201);
    }

    public function setDefault(Request $request, PaymentMethod $paymentMethod, PaymentGateway $paymentGateway): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()?->id) {
            abort(403);
        }

        /** @var User $user */
        $user = $request->user();
        $customerId = $paymentGateway->ensureCustomer($user);

        $paymentGateway->attachPaymentMethod($customerId, $paymentMethod->stripe_payment_method_id);

        $user->paymentMethods()->update(['is_default' => false]);
        $paymentMethod->update(['is_default' => true]);

        return response()->json([
            'data' => [
                'payment_method' => new PaymentMethodResource($paymentMethod->fresh()),
            ],
            'message' => 'Default payment method updated.',
        ]);
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod, PaymentGateway $paymentGateway): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()?->id) {
            abort(403);
        }

        $wasDefault = $paymentMethod->is_default;
        $paymentGateway->detachPaymentMethod($paymentMethod->stripe_payment_method_id);
        $paymentMethod->delete();

        if ($wasDefault) {
            $next = $request->user()?->paymentMethods()->orderByDesc('id')->first();
            $next?->update(['is_default' => true]);
        }

        return response()->json([
            'data' => null,
            'message' => 'Payment method removed.',
        ]);
    }
}
