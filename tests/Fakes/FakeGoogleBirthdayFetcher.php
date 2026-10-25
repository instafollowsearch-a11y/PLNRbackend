<?php

namespace Tests\Fakes;

use App\Services\Auth\FetchesGoogleBirthday;

class FakeGoogleBirthdayFetcher implements FetchesGoogleBirthday
{
    public function __construct(
        public ?string $birthday = null,
    ) {}

    public function fetch(?string $accessToken): ?string
    {
        if (! is_string($accessToken) || trim($accessToken) === '') {
            return null;
        }

        return $this->birthday;
    }
}
