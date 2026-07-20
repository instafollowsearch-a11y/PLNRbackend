<?php

namespace App\Jobs;

use App\Mail\ItineraryStopReminderMail;
use App\Models\ItineraryStopReminder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendItineraryStopReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $reminderId) {}

    public function handle(): void
    {
        $reminder = ItineraryStopReminder::query()
            ->with(['planSession.planType'])
            ->find($this->reminderId);

        if ($reminder === null || $reminder->email_sent_at !== null) {
            return;
        }

        try {
            Mail::to($reminder->recipient_email)->send(new ItineraryStopReminderMail($reminder));
            $reminder->update(['email_sent_at' => now()]);
        } catch (\Throwable $exception) {
            Log::error('itinerary_stop_reminder.send_failed', [
                'reminder_id' => $reminder->id,
                'plan_session_id' => $reminder->plan_session_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
