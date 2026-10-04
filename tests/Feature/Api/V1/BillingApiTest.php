<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Stripe\Exception\InvalidRequestException;
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

    public function test_portal_and_cancel_require_stripe(): void
    {
        $user = User::factory()->pro()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/portal-session', [
            'return_url' => 'http://localhost:5173/plans',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');

        $this->postJson('/api/v1/billing/cancel-subscription')
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');

        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_checkout_does_not_grant_pro_without_stripe(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/checkout-session', [
            'success_url' => 'http://localhost:5173/plans?billing=success',
            'cancel_url' => 'http://localhost:5173/plans?billing=cancel',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');

        $this->assertFalse($user->fresh()->isPro());
    }

    public function test_checkout_accepts_the_site_and_api_return_pages(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        foreach ([
            ['http://localhost:5173/plans?billing=success', 'http://localhost:5173/plans?billing=cancel'],
            ['http://localhost/billing/return?billing=success', 'http://localhost/billing/return?billing=cancel'],
        ] as [$successUrl, $cancelUrl]) {
            $this->postJson('/api/v1/billing/checkout-session', [
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ])
                ->assertStatus(422)
                ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');
        }

        $this->assertFalse($user->fresh()->isPro());
    }

    public function test_billing_return_page_opens_the_app(): void
    {
        $this->get('/billing/return?billing=success')
            ->assertOk()
            ->assertSee('plnr:///(tabs)/account?billing=success', false);
    }

    public function test_checkout_allows_a_site_listed_in_checkout_or_cors_env(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        $payload = [
            'success_url' => 'https://myplnr.app/plans?billing=success',
            'cancel_url' => 'https://myplnr.app/plans?billing=cancel',
        ];

        $this->postJson('/api/v1/billing/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.success_url.0', 'Return URL origin is not allowed.');

        config(['services.pro.checkout_success_origins' => ['https://myplnr.app']]);

        $this->postJson('/api/v1/billing/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');

        config([
            'services.pro.checkout_success_origins' => ['http://localhost:5173'],
            'cors.allowed_origins' => ['https://myplnr.app'],
        ]);

        $this->postJson('/api/v1/billing/checkout-session', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');

        $this->assertFalse($user->fresh()->isPro());
    }

    public function test_checkout_allows_the_calling_site_when_cors_is_open(): void
    {
        config([
            'services.pro.checkout_success_origins' => ['http://localhost:5173'],
            'services.pro.web_app_url' => 'http://localhost:5173',
            'cors.allowed_origins' => ['*'],
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/billing/checkout-session', [
            'success_url' => 'https://myplnr.app/plans?billing=success',
            'cancel_url' => 'https://myplnr.app/plans?billing=cancel',
        ], [
            'Origin' => 'https://myplnr.app',
            'Referer' => 'https://myplnr.app/plans',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe is not configured. Add a secret key in Admin → Settings.');
    }

    public function test_checkout_rejects_disallowed_origin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/billing/checkout-session', [
            'success_url' => 'https://evil.example/ok',
            'cancel_url' => 'https://evil.example/cancel',
        ])->assertStatus(422);
    }

    public function test_stripe_api_errors_are_not_server_errors(): void
    {
        Route::get('/api/v1/_stripe_error_probe', function () {
            throw InvalidRequestException::factory('No such customer: cus_fake_1', 404, null, null, null, 'resource_missing');
        });

        $this->getJson('/api/v1/_stripe_error_probe')
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'Stripe could not complete that request. Check the Stripe key in Admin → Settings.');
    }

    public function test_placeholder_subscription_is_not_sent_to_stripe(): void
    {
        config(['services.stripe.secret' => 'sk_test_placeholder']);
        $user = User::factory()->pro()->create([
            'stripe_subscription_id' => 'sub_fake_demo',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/cancel-subscription')
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', 'No Stripe subscription found. Use Manage Pro to update billing.');

        $this->assertTrue($user->fresh()->isPro());
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
