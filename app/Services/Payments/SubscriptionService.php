<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class SubscriptionService
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    /**
     * @return array{url: string, id: string}
     */
    public function createCheckoutSession(User $user, string $successUrl, string $cancelUrl): array
    {
        $this->assertAllowedReturnUrl($successUrl);
        $this->assertAllowedReturnUrl($cancelUrl);

        $client = $this->stripeClient();
        $customerId = (new StripePaymentGateway($this->settings))->ensureCustomer($user);
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
     * @return array{url: string}
     */
    public function createPortalSession(User $user, string $returnUrl): array
    {
        $this->assertAllowedReturnUrl($returnUrl);

        $client = $this->stripeClient();
        $customerId = (new StripePaymentGateway($this->settings))->ensureCustomer($user);
        $session = $client->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return [
            'url' => (string) $session->url,
        ];
    }

    public function cancelSubscription(User $user): User
    {
        $client = $this->stripeClient();

        if (! $user->isPro() && $user->pro_status !== User::PRO_STATUS_PAST_DUE) {
            throw ValidationException::withMessages([
                'subscription' => ['No active Pro subscription to cancel.'],
            ]);
        }

        if ($user->stripe_subscription_id === null || $user->stripe_subscription_id === '') {
            throw ValidationException::withMessages([
                'subscription' => ['No Stripe subscription found. Use Manage Pro to update billing.'],
            ]);
        }

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
        $candidate = $this->normalizeOrigin($url);

        if ($candidate !== null && in_array($candidate, $this->allowedReturnOrigins(), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'success_url' => ['Return URL origin is not allowed.'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedReturnOrigins(): array
    {
        $raw = [];
        $configured = config('services.pro.checkout_success_origins', []);

        if (is_array($configured)) {
            $raw = array_merge($raw, $configured);
        }

        $raw[] = (string) config('app.url');
        $raw[] = (string) request()->getSchemeAndHttpHost();
        $raw[] = (string) $this->settings->webAppUrl();

        $corsOrigins = config('cors.allowed_origins', []);

        if (is_array($corsOrigins)) {
            foreach ($corsOrigins as $origin) {
                if (! is_string($origin) || $origin === '') {
                    continue;
                }

                if ($origin === '*') {
                    $raw[] = (string) request()->headers->get('Origin', '');
                    $raw[] = (string) request()->headers->get('Referer', '');
                    continue;
                }

                $raw[] = $origin;
            }
        }

        $origins = [];

        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $origin = $this->normalizeOrigin($value);

            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }

        foreach ($this->corsPatternOrigins($origins) as $origin) {
            $origins[$origin] = true;
        }

        return array_keys($origins);
    }

    /**
     * Local Vite ports are allowed by the CORS patterns, not by a fixed domain.
     *
     * @param  array<string, true>  $knownOrigins
     * @return list<string>
     */
    private function corsPatternOrigins(array $knownOrigins): array
    {
        $patterns = config('cors.allowed_origins_patterns', []);

        if (! is_array($patterns)) {
            return [];
        }

        $matched = [];

        foreach ([$this->normalizeOrigin((string) request()->headers->get('Origin', '')), $this->normalizeOrigin((string) request()->headers->get('Referer', ''))] as $candidate) {
            if ($candidate === null || isset($knownOrigins[$candidate])) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (is_string($pattern) && preg_match($pattern, $candidate) === 1) {
                    $matched[] = $candidate;
                    break;
                }
            }
        }

        return $matched;
    }

    private function normalizeOrigin(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === '*' || ! str_contains($value, '://')) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.$host.':'.$port;
    }

    private function withBillingQuery(string $url, string $status): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query) && $query !== '') {
            parse_str($query, $params);

            if (isset($params['billing']) && $params['billing'] !== '') {
                return $url;
            }
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'billing='.$status;
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
