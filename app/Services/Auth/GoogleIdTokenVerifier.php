<?php

namespace App\Services\Auth;

use App\Services\Settings\AppSettings;
use Google\Client;

class GoogleIdTokenVerifier implements VerifiesGoogleIdToken
{
    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array
    {
        foreach ($this->clientIds() as $clientId) {
            try {
                $client = new Client(['client_id' => $clientId]);
                $payload = $client->verifyIdToken($idToken);
            } catch (\Throwable) {
                continue;
            }

            if (is_array($payload) && $this->audienceMatches($payload, $clientId)) {
                return $payload;
            }
        }

        return null;
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

    /**
     * @return list<string>
     */
    private function clientIds(): array
    {
        try {
            return app(AppSettings::class)->googleSignInClientIds();
        } catch (\Throwable) {
            $ids = [];

            foreach ([
                config('services.google.client_id'),
                config('services.google.ios_client_id'),
                config('services.google.android_client_id'),
            ] as $clientId) {
                if (! is_string($clientId)) {
                    continue;
                }

                $clientId = trim($clientId);

                if ($clientId !== '' && ! in_array($clientId, $ids, true)) {
                    $ids[] = $clientId;
                }
            }

            return $ids;
        }
    }
}
