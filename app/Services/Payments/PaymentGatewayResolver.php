<?php

namespace App\Services\Payments;

use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentGatewayResolver
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    public function resolve(): PaymentGateway
    {
        if (app()->environment('testing') || $this->settings->stripeFake()) {
            return new FakePaymentGateway;
        }

        $secret = (string) ($this->settings->stripeSecret() ?? '');

        if ($secret !== '') {
            return new StripePaymentGateway($this->settings);
        }

        if (app()->environment('local')) {
            return new FakePaymentGateway;
        }

        Log::error('stripe.secret_missing', [
            'environment' => app()->environment(),
        ]);

        throw new RuntimeException('Stripe is not configured for this environment.');
    }
}
