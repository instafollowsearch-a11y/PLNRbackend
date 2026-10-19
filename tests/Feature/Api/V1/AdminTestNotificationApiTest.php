<?php

namespace Tests\Feature\Api\V1;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTestNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_a_test_notification(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok'],
                ],
            ]),
        ]);

        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['email' => 'member@plnr.test']);
        DevicePushToken::factory()->create([
            'user_id' => $member->id,
            'token' => 'ExponentPushToken[test]',
            'platform' => 'ios',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/users?search=member')
            ->assertOk()
            ->assertJsonPath('data.users.0.push_token_count', 1);

        $this->postJson("/api/v1/admin/users/{$member->id}/test-notification")
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.token_count', 1)
            ->assertJsonPath('message', 'Test notification sent.');

        Http::assertSent(function ($request) {
            $message = $request->data()[0] ?? [];

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && ($message['title'] ?? null) === 'PLNR test'
                && ($message['data']['screen'] ?? null) === 'test'
                && ($message['channelId'] ?? null) === 'default';
        });
    }

    public function test_admin_sees_no_tokens_when_the_user_has_none(): void
    {
        Http::fake();

        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/users/{$member->id}/test-notification")
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'no_tokens')
            ->assertJsonPath('data.token_count', 0);

        Http::assertNothingSent();
    }

    public function test_non_admin_cannot_send_a_test_notification(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/admin/users/{$user->id}/test-notification")
            ->assertForbidden();
    }
}
