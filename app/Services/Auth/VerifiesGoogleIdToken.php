<?php

namespace App\Services\Auth;

interface VerifiesGoogleIdToken
{
    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array;
}
