<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class PlaySubscriptionService
{
    public function __construct(
        private readonly PlaySubscriptionVerifier $verifier,
    ) {}

    public function grant(User $user, string $productId, string $purchaseToken): User
    {
        try {
            $receipt = $this->verifier->verify($productId, $purchaseToken);
        } catch (PlayBillingException $exception) {
            throw ValidationException::withMessages([
                'purchase_token' => [$exception->getMessage()],
            ]);
        }

        if (! $receipt->active) {
            throw ValidationException::withMessages([
                'purchase_token' => ['This Google Play purchase could not be verified.'],
            ]);
        }

        $previous = [
            'pro_status' => $user->pro_status,
            'google_play_product_id' => $user->google_play_product_id,
            'google_play_purchase_token' => $user->google_play_purchase_token,
            'pro_current_period_end' => $user->pro_current_period_end,
        ];

        $user->forceFill([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'google_play_product_id' => $productId,
            'google_play_purchase_token' => $purchaseToken,
            'pro_current_period_end' => $receipt->expiresAt ?? now()->addMonth(),
        ])->save();

        try {
            $this->verifier->acknowledge($productId, $purchaseToken);
        } catch (PlayBillingException $exception) {
            $user->forceFill($previous)->save();

            throw ValidationException::withMessages([
                'purchase_token' => [$exception->getMessage()],
            ]);
        }

        return $user->fresh() ?? $user;
    }
}
