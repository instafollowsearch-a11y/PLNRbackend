<?php

namespace App\Services\Auth;

interface FetchesGoogleBirthday
{
    /**
     * Returns YYYY-MM-DD when Google provides a full birthday for the access token.
     */
    public function fetch(?string $accessToken): ?string;
}
