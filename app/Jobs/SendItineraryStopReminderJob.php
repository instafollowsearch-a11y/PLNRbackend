<?php

namespace App\Jobs;

use App\Models\ItineraryStopReminder;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendItineraryStopReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $reminderId) {}

    public function handle(ExpoPushService $expoPushService): void
    {
        $reminder = ItineraryStopReminder::query()
            ->with(['planSession.planType'])
            ->find($this->reminderId);

        if ($reminder === null || $reminder->push_sent_at !== null) {
            return;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($reminder->recipient_email)])
            ->first();

        if ($user === null || $user->devicePushTokens()->doesntExist()) {
            Log::info('reminder.push_skipped', [
                'kind' => 'itinerary_stop',
                'reminder_id' => $reminder->id,
                'reason' => $user === null ? 'no_user' : 'no_tokens',
            ]);

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
