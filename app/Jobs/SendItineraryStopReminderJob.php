<?php

namespace App\Jobs;

use App\Mail\ItineraryStopReminderMail;
use App\Models\ItineraryStopReminder;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendItineraryStopReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $reminderId) {}

    public function handle(ExpoPushService $expoPushService): void
    {
        $reminder = ItineraryStopReminder::query()
            ->with(['planSession.planType'])
            ->find($this->reminderId);

        if ($reminder === null) {
            return;
        }

        if ($reminder->email_sent_at === null) {
            $this->sendEmail($reminder);
        }

        if ($reminder->fresh()->push_sent_at === null) {
            $this->sendPush($reminder->fresh(), $expoPushService);
        }
    }

    private function sendEmail(ItineraryStopReminder $reminder): void
    {
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

    private function sendPush(ItineraryStopReminder $reminder, ExpoPushService $expoPushService): void
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($reminder->recipient_email)])
            ->first();

        if ($user === null || $user->devicePushTokens()->doesntExist()) {
            return;
        }

        $session = $reminder->planSession;
        $slug = $reminder->plan_type_slug ?? $session?->planType?->slug;
        $sent = $expoPushService->sendToUser(
            $user,
            $reminder->stop_name,
            'Coming up on your plan.',
            [
                'plan_session_uuid' => $session?->uuid,
                'plan_type_slug' => $slug,
            ],
        );

        if ($sent) {
            $reminder->update(['push_sent_at' => now()]);
        }
    }
}
