<?php

namespace App\Services\Billing;

interface AppleSubscriptionVerifier
{
    public function verify(string $productId, string $transactionId): AppleSubscriptionReceipt;
}
