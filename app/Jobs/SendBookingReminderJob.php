<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendBookingReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $bookingId) {}

    public function handle(ExpoPushService $expoPushService): void
    {
        $booking = Booking::query()->with(['user.devicePushTokens'])->find($this->bookingId);

        if ($booking === null || $booking->user === null || $booking->push_reminder_sent_at !== null) {
            return;
        }

        if ($booking->user->devicePushTokens->isEmpty()) {
            Log::info('reminder.push_skipped', [
                'kind' => 'booking',
                'booking_uuid' => $booking->uuid,
                'reason' => 'no_tokens',
            ]);

            return;
        }

        $sent = $expoPushService->sendToUser(
            $booking->user,
            'Booking reminder',
            'Your plan "'.$booking->title.'" is coming up soon.',
            ['booking_uuid' => $booking->uuid],
        );

        if ($sent) {
            $booking->update(['push_reminder_sent_at' => now()]);
        }
    }
}
