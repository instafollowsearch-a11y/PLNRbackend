<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;

class AppleIdTokenVerifier implements VerifiesAppleIdToken
{
    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array
    {
        try {
            $response = (new Client(['timeout' => 10]))->get('https://appleid.apple.com/auth/keys');
            $jwks = json_decode((string) $response->getBody(), true);

            if (! is_array($jwks)) {
                return null;
            }

            $keys = JWK::parseKeySet($jwks);
            $decoded = JWT::decode($idToken, $keys);
            $payload = (array) $decoded;
        } catch (\Throwable) {
            return null;
        }

        if (($payload['iss'] ?? null) !== 'https://appleid.apple.com') {
            return null;
        }

        $audience = $payload['aud'] ?? null;
        $allowed = config('services.apple.bundle_id');

        if (is_string($allowed) && $allowed !== '') {
            if (is_array($audience)) {
                if (! in_array($allowed, $audience, true)) {
                    return null;
                }
            } elseif ($audience !== $allowed) {
                return null;
            }
        }

        $expiry = $payload['exp'] ?? null;

        if (! is_numeric($expiry) || time() >= (int) $expiry) {
            return null;
        }

        $sub = trim((string) ($payload['sub'] ?? ''));

        if ($sub === '') {
            return null;
        }

        return $payload;
    }
}