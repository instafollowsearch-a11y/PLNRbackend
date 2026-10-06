<?php

namespace Tests\Unit\Services;

use App\Services\Billing\GooglePlaySubscriptionVerifier;
use App\Services\Billing\PlayBillingException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GooglePlaySubscriptionVerifierTest extends TestCase
{
    public function test_missing_service_account_does_not_call_google(): void
    {
        config(['services.google_play.service_account_json' => null]);

        $this->expectException(PlayBillingException::class);
        $this->expectExceptionMessage('Google Play billing is not configured yet.');

        (new GooglePlaySubscriptionVerifier)->verify('plnr_pro_monthly', 'token');
    }

    public function test_an_active_subscription_is_entitled_and_acknowledged(): void
    {
        $this->fakeGoogle('SUBSCRIPTION_STATE_ACTIVE', now()->addMonth()->toIso8601String(), 204);

        $receipt = (new GooglePlaySubscriptionVerifier)->verify('plnr_pro_monthly', 'play-token');
        (new GooglePlaySubscriptionVerifier)->acknowledge('plnr_pro_monthly', 'play-token');

        $this->assertTrue($receipt->active);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/purchases/subscriptionsv2/tokens/play-token'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/purchases/subscriptions/plnr_pro_monthly/tokens/play-token:acknowledge'));
    }

    public function test_a_canceled_subscription_stays_entitled_until_it_expires(): void
    {
        $this->fakeGoogle('SUBSCRIPTION_STATE_CANCELED', now()->addDays(12)->toIso8601String());

        $receipt = (new GooglePlaySubscriptionVerifier)->verify('plnr_pro_monthly', 'play-token');

        $this->assertTrue($receipt->active);
    }

    public function test_an_expired_cancellation_is_not_entitled(): void
    {
        $this->fakeGoogle('SUBSCRIPTION_STATE_CANCELED', now()->subDay()->toIso8601String());

        $receipt = (new GooglePlaySubscriptionVerifier)->verify('plnr_pro_monthly', 'play-token');

        $this->assertFalse($receipt->active);
    }

    private function fakeGoogle(string $state, string $expiry, int $acknowledgeStatus = 204): void
    {
        config([
            'services.google_play.package_name' => 'com.plnr.app',
            'services.google_play.service_account_json' => json_encode([
                'client_email' => 'play@plnr-test.iam.gserviceaccount.com',
                'private_key' => $this->privateKey(),
            ]),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'https://androidpublisher.googleapis.com/*' => function ($request) use ($state, $expiry, $acknowledgeStatus) {
                if ($request->method() === 'POST') {
                    return Http::response('', $acknowledgeStatus);
                }

                return Http::response([
                    'subscriptionState' => $state,
                    'lineItems' => [[
                        'productId' => 'plnr_pro_monthly',
                        'expiryTime' => $expiry,
                    ]],
                ]);
            },
        ]);
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $exported = '';
        $this->assertTrue(openssl_pkey_export($key, $exported));

        return $exported;
    }
}
