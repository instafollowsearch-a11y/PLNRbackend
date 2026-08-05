<?php

namespace App\Services\Payments;

use App\Models\Booking;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeWebhookService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(string $payload, ?string $signatureHeader): void
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            throw new SignatureVerificationException('Missing Stripe signature header.');
        }

        $event = Webhook::constructEvent($payload, $signatureHeader, $secret);

        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object),
            'customer.subscription.updated' => $this->subscriptions->syncSubscriptionFromStripeObject($event->data->object),
            'customer.subscription.deleted' => $this->subscriptions->markCanceledFromSubscription($event->data->object),
            default => null,
        };
    }

    private function handleCheckoutSessionCompleted(object $session): void
    {
        if (($session->mode ?? null) !== 'subscription') {
            return;
        }

        $subscriptionId = isset($session->subscription) ? (string) $session->subscription : '';
        if ($subscriptionId === '') {
            return;
        }

        try {
            $client = new StripeClient((string) config('services.stripe.secret'));
            $subscription = $client->subscriptions->retrieve($subscriptionId);
            $this->subscriptions->syncSubscriptionFromStripeObject($subscription);
        } catch (\Throwable $exception) {
            Log::warning('stripe.webhook.subscription_retrieve_failed', [
                'subscription_id' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        $this->updateBookingFromPaymentIntent($paymentIntent, Booking::STATUS_CONFIRMED);
    }

    private function handlePaymentIntentFailed(object $paymentIntent): void
    {
        $this->updateBookingFromPaymentIntent($paymentIntent, Booking::STATUS_FAILED);
    }

    private function updateBookingFromPaymentIntent(object $paymentIntent, string $status): void
    {
        $metadata = $paymentIntent->metadata ?? null;
        $bookingUuid = null;

        if (is_object($metadata) && isset($metadata->booking_uuid)) {
            $bookingUuid = (string) $metadata->booking_uuid;
        } elseif (is_array($metadata) && isset($metadata['booking_uuid'])) {
            $bookingUuid = (string) $metadata['booking_uuid'];
        }

        if ($bookingUuid === null || $bookingUuid === '') {
            Log::info('stripe.webhook.booking_metadata_missing', [
                'payment_intent_id' => $paymentIntent->id ?? null,
            ]);

            return;
        }

        $booking = Booking::query()->where('uuid', $bookingUuid)->first();

        if ($booking === null) {
            Log::warning('stripe.webhook.booking_not_found', [
                'booking_uuid' => $bookingUuid,
                'payment_intent_id' => $paymentIntent->id ?? null,
            ]);

            return;
        }

        if (in_array($booking->status, [Booking::STATUS_CONFIRMED, Booking::STATUS_FAILED], true)
            && $booking->stripe_payment_intent_id === ($paymentIntent->id ?? null)) {
            return;
        }

        $booking->update([
            'status' => $status,
            'stripe_payment_intent_id' => (string) ($paymentIntent->id ?? $booking->stripe_payment_intent_id),
        ]);
    }
}
