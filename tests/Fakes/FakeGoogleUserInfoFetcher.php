<?php

namespace Tests\Fakes;

use App\Services\Auth\FetchesGoogleUserInfo;

class FakeGoogleUserInfoFetcher implements FetchesGoogleUserInfo
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public ?array $payload = null,
    ) {}

    public function fetch(?string $accessToken): ?array
    {
        if (! is_string($accessToken) || trim($accessToken) === '') {
            return null;
        }

        return $this->payload;
    }
}
