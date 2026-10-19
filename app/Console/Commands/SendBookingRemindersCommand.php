<?php

namespace App\Console\Commands;

use App\Jobs\SendBookingReminderJob;
use App\Models\Booking;
use Illuminate\Console\Command;

class SendBookingRemindersCommand extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Queue reminder pushes for upcoming confirmed bookings';

    public function handle(): int
    {
        $bookings = Booking::query()
            ->with(['user.devicePushTokens'])
            ->where('status', Booking::STATUS_CONFIRMED)
            ->whereNull('push_reminder_sent_at')
            ->whereHas('user.devicePushTokens')
            ->where(function ($query): void {
                $query->whereBetween('scheduled_for', [now(), now()->addDay()])
                    ->orWhere(function ($catchUp): void {
                        $catchUp->where('scheduled_for', '>=', now()->subHour())
                            ->where('scheduled_for', '<', now());
                    });
            })
            ->get();

        $count = 0;

        foreach ($bookings as $booking) {
            if ($booking->user === null) {
                continue;
            }

            SendBookingReminderJob::dispatch($booking->id);
            $count++;
        }

        $this->info("Queued {$count} booking reminders.");

        return self::SUCCESS;
    }
}
