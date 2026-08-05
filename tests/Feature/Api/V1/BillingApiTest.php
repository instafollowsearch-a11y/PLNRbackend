<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.fake' => true,
            'services.pro.checkout_success_origins' => [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
            ],
        ]);
    }

    public function test_billing_config_is_public(): void
    {
        $this->getJson('/api/v1/billing-config')
            ->assertOk()
            ->assertJsonPath('data.pro_monthly_price_cents', 999)
            ->assertJsonPath('data.pro_currency', 'usd')
            ->assertJsonPath('data.stripe_fake', true);
    }

    public function test_fake_portal_and_cancel_subscription(): void
    {
        $user = User::factory()->pro()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/portal-session', [
            'return_url' => 'http://localhost:5173/plans',
        ])
            ->assertOk()
            ->assertJsonPath('data.fake', true)
            ->assertJsonPath('data.portal_url', 'http://localhost:5173/plans?billing=portal&fake=1');

        $this->postJson('/api/v1/billing/cancel-subscription')
            ->assertOk()
            ->assertJsonPath('data.user.is_pro', false)
            ->assertJsonPath('data.user.pro_status', User::PRO_STATUS_CANCELED);

        $this->assertFalse($user->fresh()->isPro());
    }

    public function test_fake_checkout_activates_pro(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/checkout-session', [
            'success_url' => 'http://localhost:5173/plans',
            'cancel_url' => 'http://localhost:5173/plans',
        ])->assertOk();

        $this->assertStringContainsString('billing=success', $response->json('data.checkout_url'));
        $this->assertTrue($user->fresh()->isPro());
        $this->assertSame(User::PRO_STATUS_ACTIVE, $user->fresh()->pro_status);
    }

    public function test_checkout_rejects_disallowed_origin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/billing/checkout-session', [
            'success_url' => 'https://evil.example/ok',
            'cancel_url' => 'https://evil.example/cancel',
        ])->assertStatus(422);
    }

    public function test_user_resource_includes_pro_fields(): void
    {
        $user = User::factory()->pro()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.user.is_pro', true)
            ->assertJsonPath('data.user.pro_status', User::PRO_STATUS_ACTIVE);
    }
}
