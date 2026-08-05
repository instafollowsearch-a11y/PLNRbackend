<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class SubscriptionService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AppSettings $settings,
    ) {}

    /**
     * @return array{url: string, id: string}
     */
    public function createCheckoutSession(User $user, string $successUrl, string $cancelUrl): array
    {
        $this->assertAllowedReturnUrl($successUrl);
        $this->assertAllowedReturnUrl($cancelUrl);

        $customerId = $this->gateway->ensureCustomer($user);

        if ($this->isFake()) {
            $user->forceFill([
                'pro_status' => User::PRO_STATUS_ACTIVE,
                'stripe_subscription_id' => 'sub_fake_'.Str::random(12),
                'pro_current_period_end' => now()->addMonth(),
            ])->save();

            $separator = str_contains($successUrl, '?') ? '&' : '?';

            return [
                'url' => $successUrl.$separator.'billing=success&fake=1',
                'id' => 'cs_fake_'.Str::random(12),
            ];
        }

        $client = $this->stripeClient();
        $session = $client->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'success_url' => $this->withBillingQuery($successUrl, 'success'),
            'cancel_url' => $this->withBillingQuery($cancelUrl, 'canceled'),
            'client_reference_id' => (string) $user->id,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user->id,
                ],
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->settings->proCurrency(),
                    'unit_amount' => $this->settings->proMonthlyPriceCents(),
                    'recurring' => [
                        'interval' => 'month',
                    ],
                    'product_data' => [
                        'name' => 'PLNR Pro',
                        'description' => 'Weekend recommendations and plan sharing',
                    ],
                ],
            ]],
        ]);

        return [
            'url' => (string) $session->url,
            'id' => (string) $session->id,
        ];
    }

    /**
     * @return array{url: string, fake?: bool}
     */
    public function createPortalSession(User $user, string $returnUrl): array
    {
        $this->assertAllowedReturnUrl($returnUrl);

        // Seeded / QA Pro users may be active without a customer id.
        $customerId = $this->gateway->ensureCustomer($user);

        if ($this->isFake()) {
            return [
                'url' => $this->withBillingQuery($returnUrl, 'portal').'&fake=1',
                'fake' => true,
            ];
        }

        $client = $this->stripeClient();
        $session = $client->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return [
            'url' => (string) $session->url,
            'fake' => false,
        ];
    }

    public function cancelSubscription(User $user): User
    {
        if (! $user->isPro() && $user->pro_status !== User::PRO_STATUS_PAST_DUE) {
            throw ValidationException::withMessages([
                'subscription' => ['No active Pro subscription to cancel.'],
            ]);
        }

        if ($this->isFake()) {
            $user->forceFill([
                'pro_status' => User::PRO_STATUS_CANCELED,
                'pro_current_period_end' => now(),
            ])->save();

            return $user->fresh() ?? $user;
        }

        if ($user->stripe_subscription_id === null || $user->stripe_subscription_id === '') {
            throw ValidationException::withMessages([
                'subscription' => ['No Stripe subscription found. Use Manage Pro to update billing.'],
            ]);
        }

        $client = $this->stripeClient();
        $subscription = $client->subscriptions->cancel($user->stripe_subscription_id);
        $this->markCanceledFromSubscription($subscription);

        return $user->fresh() ?? $user;
    }

    public function syncSubscriptionFromStripeObject(object $subscription): void
    {
        $userId = $this->metadataValue($subscription->metadata ?? null, 'user_id');
        $customerId = isset($subscription->customer) ? (string) $subscription->customer : null;

        $user = null;
        if ($userId !== null && $userId !== '') {
            $user = User::query()->find((int) $userId);
        }

        if ($user === null && $customerId !== null && $customerId !== '') {
            $user = User::query()->where('stripe_customer_id', $customerId)->first();
        }

        if ($user === null) {
            return;
        }

        $status = (string) ($subscription->status ?? 'canceled');
        $proStatus = match ($status) {
            'active', 'trialing' => User::PRO_STATUS_ACTIVE,
            'past_due', 'unpaid' => User::PRO_STATUS_PAST_DUE,
            default => User::PRO_STATUS_CANCELED,
        };

        $periodEnd = null;
        if (isset($subscription->current_period_end) && is_numeric($subscription->current_period_end)) {
            $periodEnd = now()->setTimestamp((int) $subscription->current_period_end);
        }

        $user->forceFill([
            'stripe_subscription_id' => (string) ($subscription->id ?? $user->stripe_subscription_id),
            'pro_status' => $proStatus,
            'pro_current_period_end' => $periodEnd,
        ])->save();
    }

    public function markCanceledFromSubscription(object $subscription): void
    {
        $userId = $this->metadataValue($subscription->metadata ?? null, 'user_id');
        $customerId = isset($subscription->customer) ? (string) $subscription->customer : null;

        $user = null;
        if ($userId !== null && $userId !== '') {
            $user = User::query()->find((int) $userId);
        }

        if ($user === null && $customerId !== null && $customerId !== '') {
            $user = User::query()->where('stripe_customer_id', $customerId)->first();
        }

        if ($user === null) {
            return;
        }

        $user->forceFill([
            'pro_status' => User::PRO_STATUS_CANCELED,
            'stripe_subscription_id' => (string) ($subscription->id ?? $user->stripe_subscription_id),
            'pro_current_period_end' => now(),
        ])->save();
    }

    private function assertAllowedReturnUrl(string $url): void
    {
        $origins = config('services.pro.checkout_success_origins', []);
        if (! is_array($origins) || $origins === []) {
            return;
        }

        foreach ($origins as $origin) {
            if (! is_string($origin) || $origin === '') {
                continue;
            }

            if (str_starts_with($url, $origin)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'success_url' => ['Return URL origin is not allowed.'],
        ]);
    }

    private function withBillingQuery(string $url, string $status): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'billing='.$status;
    }

    private function isFake(): bool
    {
        return $this->settings->stripeFake();
    }

    private function stripeClient(): StripeClient
    {
        $secret = $this->settings->stripeSecret();

        if ($secret === null || $secret === '') {
            throw ValidationException::withMessages([
                'subscription' => ['Stripe is not configured. Add a secret key in Admin → Settings.'],
            ]);
        }

        return new StripeClient($secret);
    }

    private function metadataValue(mixed $metadata, string $key): ?string
    {
        if (is_object($metadata) && isset($metadata->{$key})) {
            return (string) $metadata->{$key};
        }

        if (is_array($metadata) && isset($metadata[$key])) {
            return (string) $metadata[$key];
        }

        return null;
    }
}
