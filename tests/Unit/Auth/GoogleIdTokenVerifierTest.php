<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\GoogleIdTokenVerifier;
use Tests\TestCase;

class GoogleIdTokenVerifierTest extends TestCase
{
    public function test_missing_client_id_rejects_the_token(): void
    {
        config(['services.google.client_id' => '']);

        $this->assertNull((new GoogleIdTokenVerifier)->verify('not-a-jwt'));
    }

    public function test_malformed_token_is_rejected(): void
    {
        config(['services.google.client_id' => '123.apps.googleusercontent.com']);

        $this->assertNull((new GoogleIdTokenVerifier)->verify('not-a-jwt'));
    }
}
