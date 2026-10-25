<?php

namespace App\Services\Auth;

interface FetchesGoogleUserInfo
{
    /**
     * @return array<string, mixed>|null  Same shape fields as an ID token payload (sub, email, …).
     */
    public function fetch(?string $accessToken): ?array;
}
