<?php

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, string> */
    private array $customers = [];

    public function ensureCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customerId = 'cus_fake_'.Str::random(12);
        $this->customers[(string) $user->id] = $customerId;
        $user->update(['stripe_customer_id' => $customerId]);

        return $customerId;
    }

    public function createSetupIntent(string $customerId): array
    {
        return [
            'client_secret' => 'seti_fake_'.Str::random(12).'_secret_'.Str::random(12),
        ];
    }

    public function attachPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        return [
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYear()->format('Y'),
        ];
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        //
    }

    public function charge(
        string $customerId,
        string $paymentMethodId,
        int $amountCents,
        string $currency,
        array $metadata = [],
    ): array {
        return [
            'payment_intent_id' => 'pi_fake_'.Str::random(12),
            'status' => 'succeeded',
        ];
    }
}
