<?php

namespace Tests\Fakes;

use App\Services\Auth\VerifiesGoogleIdToken;

class FakeGoogleIdTokenVerifier implements VerifiesGoogleIdToken
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public ?array $payload,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array
    {
        if ($idToken === 'invalid') {
            return null;
        }

        return $this->payload;
    }
}
