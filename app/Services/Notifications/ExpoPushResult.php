<?php

namespace App\Services\Notifications;

final class ExpoPushResult
{
    /**
     * @param  list<string>  $ticketErrors
     */
    public function __construct(
        public readonly bool $sent,
        public readonly int $tokenCount,
        public readonly array $ticketErrors = [],
    ) {}
}
