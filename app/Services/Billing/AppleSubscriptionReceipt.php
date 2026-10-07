<?php

namespace App\Services\Billing;

use DateTimeInterface;

final class AppleSubscriptionReceipt
{
    public function __construct(
        public readonly bool $active,
        public readonly ?DateTimeInterface $expiresAt,
        public readonly ?string $originalTransactionId,
    ) {}
}
