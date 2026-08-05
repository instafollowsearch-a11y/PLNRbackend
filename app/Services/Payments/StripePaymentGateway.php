<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripePaymentGateway implements PaymentGateway
{
    private StripeClient $client;

    public function __construct(?AppSettings $settings = null)
    {
        $secret = $settings?->stripeSecret() ?? (string) config('services.stripe.secret');
        $this->client = new StripeClient((string) $secret);
    }

    public function ensureCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    public function createSetupIntent(string $customerId): array
    {
        $intent = $this->client->setupIntents->create([
            'customer' => $customerId,
            'payment_method_types' => ['card'],
        ]);

        return [
            'client_secret' => (string) $intent->client_secret,
        ];
    }

    public function attachPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        $this->client->paymentMethods->attach($paymentMethodId, [
            'customer' => $customerId,
        ]);

        $this->client->customers->update($customerId, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);

        $paymentMethod = $this->client->paymentMethods->retrieve($paymentMethodId);
        $card = $paymentMethod->card;

        return [
            'brand' => (string) ($card?->brand ?? 'card'),
            'last4' => (string) ($card?->last4 ?? ''),
            'exp_month' => (int) ($card?->exp_month ?? 0),
            'exp_year' => (int) ($card?->exp_year ?? 0),
        ];
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        try {
            $this->client->paymentMethods->detach($paymentMethodId);
        } catch (ApiErrorException $exception) {
            Log::warning('stripe.detach_payment_method_failed', [
                'payment_method_id' => $paymentMethodId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function charge(
        string $customerId,
        string $paymentMethodId,
        int $amountCents,
        string $currency,
        array $metadata = [],
    ): array {
        $intent = $this->client->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => $currency,
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session' => true,
            'confirm' => true,
            'metadata' => $metadata,
        ]);

        return [
            'payment_intent_id' => $intent->id,
            'status' => (string) $intent->status,
        ];
    }
}
