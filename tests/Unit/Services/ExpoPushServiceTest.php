<?php

namespace Tests\Unit\Services;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpoPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_push_messages_for_user_tokens(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok', 'id' => 'ticket-1'],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[abc123]',
        ]);

        $service = new ExpoPushService;
        $sent = $service->sendToUser($user, 'Reminder', 'Your plan is coming up.', [
            'booking_uuid' => 'uuid-123',
        ]);

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body[0]['to'] ?? null) === 'ExponentPushToken[abc123]'
                && ($body[0]['title'] ?? null) === 'Reminder';
        });
    }

    public function test_removes_stale_tokens_on_device_not_registered(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    [
                        'status' => 'error',
                        'message' => 'Device not registered',
                        'details' => ['error' => 'DeviceNotRegistered'],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[stale]',
        ]);

        $service = new ExpoPushService;
        $sent = $service->sendToUser($user, 'Reminder', 'Body');

        $this->assertFalse($sent);
        $this->assertDatabaseMissing('device_push_tokens', [
            'token' => 'ExponentPushToken[stale]',
        ]);
    }
}
