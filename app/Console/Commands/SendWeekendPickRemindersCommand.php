<?php

namespace App\Console\Commands;

use App\Mail\WeekendPickReminderMail;
use App\Models\User;
use App\Models\WeekendPickReminder;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWeekendPickRemindersCommand extends Command
{
    protected $signature = 'weekends:send-pick-reminders';

    protected $description = 'Email and push weekend pick nudges that are due';

    public function handle(ExpoPushService $push): int
    {
        $reminders = WeekendPickReminder::query()
            ->with('recommendation')
            ->whereNull('email_sent_at')
            ->where('remind_at', '<=', now())
            ->where('remind_at', '>=', now()->subHour())
            ->orderBy('remind_at')
            ->get();

        $count = 0;

        foreach ($reminders as $reminder) {
            try {
                Mail::to($reminder->recipient_email)->send(new WeekendPickReminderMail($reminder));
                $reminder->update(['email_sent_at' => now()]);
                $this->push($reminder, $push);
                $count++;
            } catch (\Throwable $exception) {
                Log::warning('weekend_pick_reminder.send_failed', [
                    'reminder_id' => $reminder->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$count} weekend pick nudges.");

        return self::SUCCESS;
    }

    private function push(WeekendPickReminder $reminder, ExpoPushService $push): void
    {
        if ($reminder->push_sent_at !== null) {
            return;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($reminder->recipient_email)])
            ->first();

        if ($user === null) {
            return;
        }

        $sent = $push->sendToUser($user, $reminder->title, 'Coming up on your weekend picks.', [
            'screen' => 'weekend',
            'recommendation_uuid' => $reminder->recommendation?->uuid,
        ]);

        if ($sent) {
            $reminder->update(['push_sent_at' => now()]);
        }
    }
}
