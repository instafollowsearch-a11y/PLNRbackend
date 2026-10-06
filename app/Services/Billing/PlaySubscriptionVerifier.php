<?php

namespace App\Services\Billing;

interface PlaySubscriptionVerifier
{
    public function verify(string $productId, string $purchaseToken): PlaySubscriptionReceipt;

    public function acknowledge(string $productId, string $purchaseToken): void;
}
