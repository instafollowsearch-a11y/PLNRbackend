<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleUserInfoFetcher implements FetchesGoogleUserInfo
{
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function fetch(?string $accessToken): ?array
    {
        $token = is_string($accessToken) ? trim($accessToken) : '';

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(8)
                ->get(self::USERINFO_URL);
        } catch (\Throwable $exception) {
            Log::warning('Google userinfo request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Google userinfo request rejected.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }
}
