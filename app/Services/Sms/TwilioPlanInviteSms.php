<?php

namespace App\Services\Sms;

use Twilio\Rest\Client;

class TwilioPlanInviteSms implements SendsPlanInviteSms
{
    public function configured(): bool
    {
        return $this->accountSid() !== null
            && $this->authToken() !== null
            && ($this->messagingServiceSid() !== null || $this->fromNumber() !== null);
    }

    public function send(string $to, string $body): void
    {
        $sid = $this->accountSid();
        $token = $this->authToken();

        if ($sid === null || $token === null) {
            throw new \RuntimeException('Twilio is not configured.');
        }

        $options = ['body' => $body];
        $serviceSid = $this->messagingServiceSid();

        if ($serviceSid !== null) {
            $options['messagingServiceSid'] = $serviceSid;
        } else {
            $from = $this->fromNumber();

            if ($from === null) {
                throw new \RuntimeException('Twilio is not configured.');
            }

            $options['from'] = $from;
        }

        $client = new Client($sid, $token);
        $client->messages->create($to, $options);
    }

    private function accountSid(): ?string
    {
        return $this->filled(config('services.twilio.account_sid'));
    }

    private function authToken(): ?string
    {
        return $this->filled(config('services.twilio.auth_token'));
    }

    private function fromNumber(): ?string
    {
        return $this->filled(config('services.twilio.from'));
    }

    private function messagingServiceSid(): ?string
    {
        return $this->filled(config('services.twilio.messaging_service_sid'));
    }

    private function filled(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
