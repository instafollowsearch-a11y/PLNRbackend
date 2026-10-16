<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;

class PinTokenRepository extends DatabaseTokenRepository
{
    public function createNewToken()
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
