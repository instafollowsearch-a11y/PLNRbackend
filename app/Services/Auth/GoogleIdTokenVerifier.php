<?php

namespace App\Services\Auth;

use Google\Client;

class GoogleIdTokenVerifier implements VerifiesGoogleIdToken
{
    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array
    {
        $clientId = trim((string) config('services.google.client_id'));

        if ($clientId === '') {
            return null;
        }

        try {
            $client = new Client(['client_id' => $clientId]);
            $payload = $client->verifyIdToken($idToken);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || ! $this->audienceMatches($payload, $clientId)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audienceMatches(array $payload, string $clientId): bool
    {
        $audience = $payload['aud'] ?? null;

        if (is_array($audience)) {
            return in_array($clientId, $audience, true);
        }

        return $audience === $clientId;
    }
}
