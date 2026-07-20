<?php

namespace App\Services\Payments;

use App\Models\User;

interface PaymentGateway
{
    public function ensureCustomer(User $user): string;

    /**
     * @return array{client_secret: string}
     */
    public function createSetupIntent(string $customerId): array;

    /**
     * @return array{brand: string, last4: string, exp_month: int, exp_year: int}
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId): array;

    public function detachPaymentMethod(string $paymentMethodId): void;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{payment_intent_id: string, status: string}
     */
    public function charge(
        string $customerId,
        string $paymentMethodId,
        int $amountCents,
        string $currency,
        array $metadata = [],
    ): array;
}
