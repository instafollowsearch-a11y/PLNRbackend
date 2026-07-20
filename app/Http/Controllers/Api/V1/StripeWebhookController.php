<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeWebhookService $webhookService): JsonResponse
    {
        try {
            $webhookService->handle(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (SignatureVerificationException $exception) {
            Log::warning('stripe.webhook.invalid_signature', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\RuntimeException $exception) {
            Log::error('stripe.webhook.configuration_error', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook not configured.'], 500);
        }

        return response()->json(['received' => true]);
    }
}
