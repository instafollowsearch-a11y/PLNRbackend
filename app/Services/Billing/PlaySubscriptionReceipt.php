<?php

namespace App\Services\Billing;

use DateTimeInterface;

final class PlaySubscriptionReceipt
{
    public function __construct(
        public readonly bool $active,
        public readonly ?DateTimeInterface $expiresAt,
    ) {}
}
