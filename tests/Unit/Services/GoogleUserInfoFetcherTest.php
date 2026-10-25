<?php

namespace Tests\Unit\Services;

use App\Services\Auth\GoogleUserInfoFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleUserInfoFetcherTest extends TestCase
{
    public function test_fetch_returns_null_without_access_token(): void
    {
        Http::fake();

        $this->assertNull((new GoogleUserInfoFetcher)->fetch(null));
        Http::assertNothingSent();
    }

    public function test_fetch_returns_userinfo_payload(): void
    {
        Http::fake([
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'abc',
                'email' => 'ada@plnr.test',
                'email_verified' => true,
                'name' => 'Ada',
            ]),
        ]);

        $payload = (new GoogleUserInfoFetcher)->fetch('access-token');

        $this->assertSame('abc', $payload['sub'] ?? null);
        $this->assertSame('ada@plnr.test', $payload['email'] ?? null);
    }
}
