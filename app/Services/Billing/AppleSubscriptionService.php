<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class AppleSubscriptionService
{
    public function __construct(
        private readonly AppleSubscriptionVerifier $verifier,
    ) {}

    public function grant(User $user, string $productId, string $transactionId): User
    {
        try {
            $receipt = $this->verifier->verify($productId, $transactionId);
        } catch (AppleBillingException $exception) {
            throw ValidationException::withMessages([
                'transaction_id' => [$exception->getMessage()],
            ]);
        }

        if (! $receipt->active || $receipt->originalTransactionId === null || $receipt->originalTransactionId === '') {
            throw ValidationException::withMessages([
                'transaction_id' => ['This App Store purchase could not be verified.'],
            ]);
        }

        $user->forceFill([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'apple_product_id' => $productId,
            'apple_original_transaction_id' => $receipt->originalTransactionId,
            'pro_current_period_end' => $receipt->expiresAt,
        ])->save();

        return $user->fresh() ?? $user;
    }
}
