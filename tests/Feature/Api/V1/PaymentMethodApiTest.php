<?php

namespace Tests\Feature\Api\V1;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentMethodApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_intent_requires_authentication(): void
    {
        $this->postJson('/api/v1/payment-methods/setup-intent')->assertUnauthorized();
    }

    public function test_user_can_create_list_and_delete_payment_method(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payment-methods/setup-intent')
            ->assertOk()
            ->assertJsonStructure(['data' => ['client_secret']]);

        $create = $this->postJson('/api/v1/payment-methods', [
            'payment_method_id' => 'pm_fake_visa',
        ])->assertCreated();

        $methodId = $create->json('data.payment_method.id');

        $this->getJson('/api/v1/payment-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data.payment_methods')
            ->assertJsonPath('data.payment_methods.0.last4', '4242');

        $this->deleteJson("/api/v1/payment-methods/{$methodId}")
            ->assertOk();

        $this->assertDatabaseMissing('payment_methods', ['id' => $methodId]);
    }

    public function test_user_cannot_delete_another_users_payment_method(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $method = PaymentMethod::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/v1/payment-methods/{$method->id}")->assertForbidden();
    }

    public function test_second_card_becomes_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/payment-methods', [
            'payment_method_id' => 'pm_fake_visa',
        ])->assertCreated()->json('data.payment_method.id');

        $second = $this->postJson('/api/v1/payment-methods', [
            'payment_method_id' => 'pm_fake_mastercard',
        ])->assertCreated()->json('data.payment_method.id');

        $this->assertDatabaseHas('payment_methods', ['id' => $first, 'is_default' => false]);
        $this->assertDatabaseHas('payment_methods', ['id' => $second, 'is_default' => true]);
    }

    public function test_user_can_set_default_payment_method(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/payment-methods', [
            'payment_method_id' => 'pm_fake_visa',
        ])->assertCreated()->json('data.payment_method.id');

        $second = $this->postJson('/api/v1/payment-methods', [
            'payment_method_id' => 'pm_fake_mastercard',
        ])->assertCreated()->json('data.payment_method.id');

        $this->patchJson("/api/v1/payment-methods/{$first}/default")
            ->assertOk()
            ->assertJsonPath('data.payment_method.is_default', true);

        $this->assertDatabaseHas('payment_methods', ['id' => $first, 'is_default' => true]);
        $this->assertDatabaseHas('payment_methods', ['id' => $second, 'is_default' => false]);
    }
}
