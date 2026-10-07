<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Billing\AppleSubscriptionReceipt;
use App\Services\Billing\AppleSubscriptionVerifier;
use App\Services\Payments\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppleSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_apple_transaction_marks_the_user_pro_and_keeps_stripe(): void
    {
        $this->fakeVerifier();
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_existing',
            'google_play_purchase_token' => 'play-token',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/apple/subscription', [
            'product_id' => 'plnr_pro_monthly',
            'transaction_id' => 'valid-transaction',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.is_pro', true);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('cus_existing', $fresh->stripe_customer_id);
        $this->assertSame('sub_existing', $fresh->stripe_subscription_id);
        $this->assertSame('play-token', $fresh->google_play_purchase_token);
        $this->assertSame('original-transaction', $fresh->apple_original_transaction_id);
        $this->assertTrue($fresh->isPro());
    }

    public function test_an_invalid_apple_transaction_does_not_grant_pro(): void
    {
        $this->fakeVerifier();
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
            'stripe_subscription_id' => 'sub_existing',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/apple/subscription', [
            'product_id' => 'plnr_pro_monthly',
            'transaction_id' => 'invalid-transaction',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_id']);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->isPro());
        $this->assertNull($fresh->apple_original_transaction_id);
        $this->assertSame('sub_existing', $fresh->stripe_subscription_id);
    }

    public function test_a_stripe_cancel_keeps_pro_while_apple_is_still_active(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_existing',
            'apple_original_transaction_id' => 'original-transaction',
            'pro_current_period_end' => now()->addMonth(),
        ]);

        app(SubscriptionService::class)->markCanceledFromSubscription((object) [
            'id' => 'sub_existing',
            'customer' => 'cus_existing',
            'metadata' => (object) ['user_id' => (string) $user->id],
        ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->isPro());
        $this->assertSame(User::PRO_STATUS_ACTIVE, $fresh->pro_status);
        $this->assertSame('original-transaction', $fresh->apple_original_transaction_id);
    }

    public function test_a_stripe_cancel_keeps_pro_while_play_is_still_active(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_existing',
            'google_play_purchase_token' => 'play-token',
            'pro_current_period_end' => now()->addMonth(),
        ]);

        app(SubscriptionService::class)->markCanceledFromSubscription((object) [
            'id' => 'sub_existing',
            'customer' => 'cus_existing',
            'metadata' => (object) ['user_id' => (string) $user->id],
        ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->isPro());
        $this->assertSame('play-token', $fresh->google_play_purchase_token);
    }

    public function test_a_stripe_cancel_ends_pro_after_the_store_period(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_existing',
            'apple_original_transaction_id' => 'original-transaction',
            'pro_current_period_end' => now()->subDay(),
        ]);

        app(SubscriptionService::class)->markCanceledFromSubscription((object) [
            'id' => 'sub_existing',
            'customer' => 'cus_existing',
            'metadata' => (object) ['user_id' => (string) $user->id],
        ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->isPro());
        $this->assertSame(User::PRO_STATUS_CANCELED, $fresh->pro_status);
    }

    private function fakeVerifier(): void
    {
        $this->app->instance(AppleSubscriptionVerifier::class, new class implements AppleSubscriptionVerifier
        {
            public function verify(string $productId, string $transactionId): AppleSubscriptionReceipt
            {
                if ($transactionId !== 'valid-transaction') {
                    return new AppleSubscriptionReceipt(false, null, null);
                }

                return new AppleSubscriptionReceipt(true, now()->addMonth(), 'original-transaction');
            }
        });
    }
}
