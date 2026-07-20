<?php

namespace Tests\Feature\Console;

use App\Jobs\SendBookingReminderJob;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendBookingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_reminder_job_for_upcoming_booking(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'status' => Booking::STATUS_CONFIRMED,
            'scheduled_for' => now()->addHours(12),
            'reminder_sent_at' => null,
        ]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendBookingReminderJob::class, function (SendBookingReminderJob $job) use ($booking) {
            return $job->bookingId === $booking->id;
        });
    }

    public function test_queues_catch_up_reminder_for_recently_past_booking(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'status' => Booking::STATUS_CONFIRMED,
            'scheduled_for' => now()->subMinutes(30),
            'reminder_sent_at' => null,
        ]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendBookingReminderJob::class, function (SendBookingReminderJob $job) use ($booking) {
            return $job->bookingId === $booking->id;
        });
    }
}
