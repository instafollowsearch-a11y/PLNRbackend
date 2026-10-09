<?php

namespace App\Services\Sms;

interface SendsPlanInviteSms
{
    public function configured(): bool;

    public function send(string $to, string $body): void;
}
