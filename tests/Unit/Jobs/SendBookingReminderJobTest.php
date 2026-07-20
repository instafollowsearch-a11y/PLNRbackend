<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendBookingReminderJob;
use App\Mail\BookingReminderMail;
use App\Models\Booking;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendBookingReminderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_email_and_push_reminders(): void
    {
        Mail::fake();

        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok'],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[abc123]',
        ]);

        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'status' => Booking::STATUS_CONFIRMED,
            'reminder_sent_at' => null,
            'push_reminder_sent_at' => null,
        ]);

        (new SendBookingReminderJob($booking->id))->handle(app(\App\Services\Notifications\ExpoPushService::class));

        Mail::assertSent(BookingReminderMail::class);
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
        $this->assertNotNull($booking->fresh()->push_reminder_sent_at);
    }

    public function test_skips_email_when_already_sent(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'status' => Booking::STATUS_CONFIRMED,
            'reminder_sent_at' => now(),
            'push_reminder_sent_at' => null,
        ]);

        (new SendBookingReminderJob($booking->id))->handle(app(\App\Services\Notifications\ExpoPushService::class));

        Mail::assertNothingSent();
    }
}
