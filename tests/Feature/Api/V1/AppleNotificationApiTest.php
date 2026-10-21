<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AppleJwsTestFixture;
use Tests\TestCase;

class AppleNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private AppleJwsTestFixture $jws;

    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jws = AppleJwsTestFixture::create();
        $this->rootPath = tempnam(sys_get_temp_dir(), 'apple-notify-root-');
        $this->assertNotFalse($this->rootPath);
        file_put_contents($this->rootPath, $this->jws->rootPem());
        config([
            'services.apple.bundle_id' => 'com.myplnr.app',
            'services.apple.product_id' => 'plnr_pro_monthly',
            'services.apple.jws_root_pem' => $this->rootPath,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_string($this->rootPath) && is_file($this->rootPath)) {
            unlink($this->rootPath);
        }
        parent::tearDown();
    }

    public function test_did_renew_keeps_matching_user_pro(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'apple_original_transaction_id' => '2000000000000001',
            'apple_product_id' => 'plnr_pro_monthly',
            'pro_current_period_end' => now()->addDay(),
        ]);

        $expiresAt = now()->addMonth();
        $response = $this->postJson('/api/v1/billing/apple/notifications', [
            'signedPayload' => $this->notificationPayload('DID_RENEW', [
                'productId' => 'plnr_pro_monthly',
                'bundleId' => 'com.myplnr.app',
                'originalTransactionId' => '2000000000000001',
                'expiresDate' => $expiresAt->getTimestamp() * 1000,
            ]),
        ]);

        $response->assertOk()->assertJsonPath('received', true);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(User::PRO_STATUS_ACTIVE, $fresh->pro_status);
        $this->assertNotNull($fresh->pro_current_period_end);
        $this->assertSame($expiresAt->timestamp, $fresh->pro_current_period_end->timestamp);
    }

    public function test_refund_cancels_pro_for_matching_user(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'apple_original_transaction_id' => '2000000000000001',
            'pro_current_period_end' => now()->addMonth(),
        ]);

        $this->postJson('/api/v1/billing/apple/notifications', [
            'signedPayload' => $this->notificationPayload('REFUND', [
                'productId' => 'plnr_pro_monthly',
                'bundleId' => 'com.myplnr.app',
                'originalTransactionId' => '2000000000000001',
                'expiresDate' => now()->addMonth()->getTimestamp() * 1000,
            ]),
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(User::PRO_STATUS_CANCELED, $fresh->pro_status);
    }

    public function test_unknown_original_transaction_is_a_noop(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
            'apple_original_transaction_id' => null,
        ]);

        $this->postJson('/api/v1/billing/apple/notifications', [
            'signedPayload' => $this->notificationPayload('DID_RENEW', [
                'productId' => 'plnr_pro_monthly',
                'bundleId' => 'com.myplnr.app',
                'originalTransactionId' => '9999999999999999',
                'expiresDate' => now()->addMonth()->getTimestamp() * 1000,
            ]),
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(User::PRO_STATUS_INACTIVE, $fresh->pro_status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postJson('/api/v1/billing/apple/notifications', [
            'signedPayload' => $this->jws->signWithTamperedSignature([
                'notificationType' => 'DID_RENEW',
                'data' => [
                    'signedTransactionInfo' => $this->jws->sign([
                        'productId' => 'plnr_pro_monthly',
                        'bundleId' => 'com.myplnr.app',
                        'originalTransactionId' => '2000000000000001',
                        'expiresDate' => now()->addMonth()->getTimestamp() * 1000,
                    ]),
                ],
            ]),
        ])->assertStatus(400);
    }

    public function test_renew_notification_is_idempotent(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'apple_original_transaction_id' => '2000000000000001',
            'pro_current_period_end' => now()->addDay(),
        ]);

        $payload = [
            'signedPayload' => $this->notificationPayload('DID_RENEW', [
                'productId' => 'plnr_pro_monthly',
                'bundleId' => 'com.myplnr.app',
                'originalTransactionId' => '2000000000000001',
                'expiresDate' => now()->addMonths(2)->getTimestamp() * 1000,
            ]),
        ];

        $this->postJson('/api/v1/billing/apple/notifications', $payload)->assertOk();
        $this->postJson('/api/v1/billing/apple/notifications', $payload)->assertOk();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(User::PRO_STATUS_ACTIVE, $fresh->pro_status);
        $this->assertSame('2000000000000001', $fresh->apple_original_transaction_id);
    }

    /**
     * @param  array<string, mixed>  $transactionClaims
     */
    private function notificationPayload(string $type, array $transactionClaims): string
    {
        return $this->jws->sign([
            'notificationType' => $type,
            'data' => [
                'signedTransactionInfo' => $this->jws->sign($transactionClaims),
            ],
        ]);
    }
}
