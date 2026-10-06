<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Billing\PlayBillingException;
use App\Services\Billing\PlaySubscriptionReceipt;
use App\Services\Billing\PlaySubscriptionVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaySubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_play_token_marks_the_user_pro_without_clearing_stripe(): void
    {
        $this->fakeVerifier();
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
            'stripe_customer_id' => 'cus_existing',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/play/subscription', [
            'product_id' => 'plnr_pro_monthly',
            'purchase_token' => 'valid-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.is_pro', true);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('cus_existing', $fresh->stripe_customer_id);
        $this->assertSame('valid-token', $fresh->google_play_purchase_token);
        $this->assertTrue($fresh->isPro());
    }

    public function test_an_invalid_play_token_does_not_grant_pro(): void
    {
        $this->fakeVerifier();
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/play/subscription', [
            'product_id' => 'plnr_pro_monthly',
            'purchase_token' => 'invalid-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purchase_token']);

        $this->assertFalse($user->fresh()?->isPro() ?? true);
    }

    public function test_a_failed_acknowledgement_does_not_leave_the_user_pro(): void
    {
        $this->app->instance(PlaySubscriptionVerifier::class, new class implements PlaySubscriptionVerifier
        {
            public function verify(string $productId, string $purchaseToken): PlaySubscriptionReceipt
            {
                return new PlaySubscriptionReceipt(true, now()->addMonth());
            }

            public function acknowledge(string $productId, string $purchaseToken): void
            {
                throw new PlayBillingException('This Google Play purchase could not be verified.');
            }
        });
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/play/subscription', [
            'product_id' => 'plnr_pro_monthly',
            'purchase_token' => 'valid-token',
        ])->assertUnprocessable();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->isPro());
        $this->assertNull($fresh->google_play_purchase_token);
    }

    private function fakeVerifier(): void
    {
        $this->app->instance(PlaySubscriptionVerifier::class, new class implements PlaySubscriptionVerifier
        {
            public function verify(string $productId, string $purchaseToken): PlaySubscriptionReceipt
            {
                return new PlaySubscriptionReceipt($purchaseToken === 'valid-token', now()->addMonth());
            }

            public function acknowledge(string $productId, string $purchaseToken): void {}
        });
    }
}
