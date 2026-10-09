<?php

namespace Tests\Fakes;

use App\Services\Sms\SendsPlanInviteSms;
use RuntimeException;

class FakePlanInviteSms implements SendsPlanInviteSms
{
    /** @var list<array{to: string, body: string}> */
    public array $messages = [];

    public function __construct(
        public bool $isConfigured = true,
        public bool $shouldFail = false,
    ) {}

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function send(string $to, string $body): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Twilio rejected the message.');
        }

        $this->messages[] = [
            'to' => $to,
            'body' => $body,
        ];
    }
}
