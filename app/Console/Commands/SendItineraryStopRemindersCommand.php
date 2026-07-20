<?php

namespace App\Console\Commands;

use App\Jobs\SendItineraryStopReminderJob;
use App\Models\ItineraryStopReminder;
use Illuminate\Console\Command;

class SendItineraryStopRemindersCommand extends Command
{
    protected $signature = 'itineraries:send-stop-reminders';

    protected $description = 'Queue per-stop reminder emails that are due';

    public function handle(): int
    {
        $reminders = ItineraryStopReminder::query()
            ->whereNull('email_sent_at')
            ->where('remind_at', '<=', now())
            ->where('remind_at', '>=', now()->subHour())
            ->orderBy('remind_at')
            ->get();

        $count = 0;

        foreach ($reminders as $reminder) {
            SendItineraryStopReminderJob::dispatch($reminder->id);
            $count++;
        }

        $this->info("Queued {$count} itinerary stop reminders.");

        return self::SUCCESS;
    }
}
