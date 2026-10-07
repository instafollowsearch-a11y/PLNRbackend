<?php

namespace Tests\Unit\Services;

use App\Services\Billing\AppleBillingException;
use App\Services\Billing\AppStoreSubscriptionVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppStoreSubscriptionVerifierTest extends TestCase
{
    public function test_missing_key_does_not_call_apple(): void
    {
        config([
            'services.apple.issuer_id' => null,
            'services.apple.key_id' => null,
            'services.apple.private_key' => null,
        ]);
        Http::fake();

        try {
            (new AppStoreSubscriptionVerifier)->verify('plnr_pro_monthly', '1000001');
            $this->fail('App Store verification should fail closed without a key.');
        } catch (AppleBillingException $exception) {
            $this->assertSame('App Store billing is not configured yet.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_sandbox_transaction_is_accepted_after_production_misses_it(): void
    {
        $this->configureKey();
        $expiresAt = now()->addMonth()->getTimestamp() * 1000;
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'productId' => 'plnr_pro_monthly',
            'bundleId' => 'com.myplnr.app',
            'originalTransactionId' => '2000000000000001',
            'expiresDate' => $expiresAt,
        ])), '+/', '-_'), '=');

        Http::fake([
            'api.storekit.apple.com/*' => Http::response(['errorCode' => 4040010], 404),
            'api.storekit-sandbox.apple.com/*' => Http::response([
                'data' => [[
                    'lastTransactions' => [[
                        'status' => 1,
                        'signedTransactionInfo' => 'e30.'.$payload.'.sig',
                    ]],
                ]],
            ]),
        ]);

        $receipt = (new AppStoreSubscriptionVerifier)->verify('plnr_pro_monthly', '1000001');

        $this->assertTrue($receipt->active);
        $this->assertSame('2000000000000001', $receipt->originalTransactionId);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storekit-sandbox.apple.com/inApps/v1/subscriptions/1000001'));
    }

    public function test_the_api_token_is_a_valid_es256_jwt(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        config([
            'services.apple.issuer_id' => 'issuer-id',
            'services.apple.key_id' => 'KEYID12345',
            'services.apple.private_key' => '"'.str_replace("\n", '\\n', $pem).'"',
            'services.apple.bundle_id' => 'com.myplnr.app',
            'services.apple.product_id' => 'plnr_pro_monthly',
        ]);

        $authorization = '';
        Http::fake(function ($request) use (&$authorization) {
            $authorization = (string) $request->header('Authorization')[0];

            return Http::response(['data' => []], 200);
        });

        (new AppStoreSubscriptionVerifier)->verify('plnr_pro_monthly', '1000001');

        $jwt = substr($authorization, strlen('Bearer '));
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        $this->assertSame('ES256', $header['alg'] ?? null);
        $this->assertSame('KEYID12345', $header['kid'] ?? null);
        $this->assertSame('appstoreconnect-v1', $claims['aud'] ?? null);
        $this->assertSame('com.myplnr.app', $claims['bid'] ?? null);
        $this->assertSame('issuer-id', $claims['iss'] ?? null);

        $raw = $this->base64UrlDecode($parts[2]);
        $this->assertSame(64, strlen($raw));
        $verified = openssl_verify(
            $parts[0].'.'.$parts[1],
            $this->rawSignatureToDer($raw),
            $details['key'],
            OPENSSL_ALGO_SHA256,
        );
        $this->assertSame(1, $verified);
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $this->assertNotFalse($decoded);

        return $decoded;
    }

    private function rawSignatureToDer(string $raw): string
    {
        $encode = function (string $coordinate): string {
            $coordinate = ltrim($coordinate, "\x00");
            if ($coordinate === '' || (ord($coordinate[0]) & 0x80) !== 0) {
                $coordinate = "\x00".$coordinate;
            }

            return "\x02".chr(strlen($coordinate)).$coordinate;
        };

        $body = $encode(substr($raw, 0, 32)).$encode(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    private function configureKey(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $exported = openssl_pkey_export($key, $pem);
        $this->assertTrue($exported);

        config([
            'services.apple.issuer_id' => 'issuer-id',
            'services.apple.key_id' => 'KEYID12345',
            'services.apple.private_key' => $pem,
            'services.apple.bundle_id' => 'com.myplnr.app',
            'services.apple.product_id' => 'plnr_pro_monthly',
        ]);
    }
}
