<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WeekendPickReminder;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWeekendPickRemindersCommand extends Command
{
    protected $signature = 'weekends:send-pick-reminders';

    protected $description = 'Push weekend pick nudges that are due';

    public function handle(ExpoPushService $push): int
    {
        $reminders = WeekendPickReminder::query()
            ->with(['recommendation', 'user'])
            ->whereNull('push_sent_at')
            ->where('remind_at', '<=', now())
            ->where('remind_at', '>=', now()->subHours(6))
            ->orderBy('remind_at')
            ->get();

        $count = 0;

        foreach ($reminders as $reminder) {
            $user = $reminder->user
                ?? User::query()
                    ->whereRaw('LOWER(email) = ?', [strtolower($reminder->recipient_email)])
                    ->first();

            if ($user === null || $user->devicePushTokens()->doesntExist()) {
                Log::info('reminder.push_skipped', [
                    'kind' => 'weekend_pick',
                    'reminder_id' => $reminder->id,
                    'reason' => $user === null ? 'no_user' : 'no_tokens',
                ]);

                continue;
            }

            $sent = $push->sendToUser($user, $reminder->title, 'Coming up on your weekend picks.', [
                'screen' => 'weekend',
                'recommendation_uuid' => $reminder->recommendation?->uuid,
            ]);

            if ($sent) {
                $reminder->update(['push_sent_at' => now()]);
                $count++;
            }
        }

        $this->info("Sent {$count} weekend pick nudges.");

        return self::SUCCESS;
    }
}
