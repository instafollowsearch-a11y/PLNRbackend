<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentGatewayResolver
{
    public function resolve(): PaymentGateway
    {
        if (app()->environment('testing')) {
            return new FakePaymentGateway;
        }

        $secret = (string) config('services.stripe.secret');

        if ($secret !== '') {
            return new StripePaymentGateway;
        }

        if (app()->environment('local') && filter_var(env('STRIPE_FAKE', false), FILTER_VALIDATE_BOOL)) {
            return new FakePaymentGateway;
        }

        Log::error('stripe.secret_missing', [
            'environment' => app()->environment(),
        ]);

        throw new RuntimeException('Stripe is not configured for this environment.');
    }
}
