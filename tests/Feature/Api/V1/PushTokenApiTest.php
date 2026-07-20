<?php

namespace Tests\Feature\Api\V1;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_push_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/push-tokens', [
            'token' => 'ExponentPushToken[abc123]',
            'platform' => 'ios',
        ])->assertCreated()
            ->assertJsonPath('data.push_token.platform', 'ios');

        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[abc123]',
            'platform' => 'ios',
        ]);
    }

    public function test_registering_same_token_updates_owner(): void
    {
        $originalUser = User::factory()->create();
        $newUser = User::factory()->create();

        DevicePushToken::factory()->create([
            'user_id' => $originalUser->id,
            'token' => 'ExponentPushToken[shared]',
            'platform' => 'android',
        ]);

        Sanctum::actingAs($newUser);

        $this->postJson('/api/v1/push-tokens', [
            'token' => 'ExponentPushToken[shared]',
            'platform' => 'android',
        ])->assertCreated();

        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $newUser->id,
            'token' => 'ExponentPushToken[shared]',
        ]);
    }

    public function test_user_can_delete_push_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[delete-me]',
        ]);

        $this->deleteJson('/api/v1/push-tokens', [
            'token' => 'ExponentPushToken[delete-me]',
        ])->assertOk();

        $this->assertDatabaseMissing('device_push_tokens', [
            'token' => 'ExponentPushToken[delete-me]',
        ]);
    }

    public function test_push_token_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/push-tokens', [
            'token' => 'ExponentPushToken[abc123]',
            'platform' => 'ios',
        ])->assertUnauthorized();
    }
}
