<?php

namespace App\Jobs;

use App\Mail\BookingReminderMail;
use App\Models\Booking;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBookingReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $bookingId) {}

    public function handle(ExpoPushService $expoPushService): void
    {
        $booking = Booking::query()->with(['user.devicePushTokens'])->find($this->bookingId);

        if ($booking === null || $booking->user === null) {
            return;
        }

        if ($booking->reminder_sent_at === null) {
            try {
                Mail::to($booking->user->email)->send(new BookingReminderMail($booking));
                $booking->update(['reminder_sent_at' => now()]);
            } catch (\Throwable $exception) {
                Log::error('booking.email_reminder_failed', [
                    'booking_uuid' => $booking->uuid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($booking->fresh()->push_reminder_sent_at === null && $booking->user->devicePushTokens->isNotEmpty()) {
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
}
