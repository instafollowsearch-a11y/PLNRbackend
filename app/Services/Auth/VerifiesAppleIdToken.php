<?php

namespace App\Services\Auth;

interface VerifiesAppleIdToken
{
    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array;
}