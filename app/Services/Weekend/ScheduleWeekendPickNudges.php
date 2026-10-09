<?php

namespace App\Services\Weekend;

use App\Models\WeekendPickReminder;
use App\Models\WeekendRecommendation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ScheduleWeekendPickNudges
{
    public function replaceFor(WeekendRecommendation $recommendation, string $email): void
    {
        $email = strtolower(trim($email));

        WeekendPickReminder::query()
            ->where('user_id', $recommendation->user_id)
            ->whereNull('email_sent_at')
            ->delete();

        foreach ($recommendation->items ?? [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $startsAt = $this->startsAt($item);

            if ($startsAt === null || $startsAt->isPast()) {
                continue;
            }

            $remindAt = $startsAt->copy()->subMinutes(30);

            if ($remindAt->isPast()) {
                $remindAt = now();
            }

            try {
                WeekendPickReminder::query()->create([
                    'weekend_recommendation_id' => $recommendation->id,
                    'user_id' => $recommendation->user_id,
                    'item_index' => $index,
                    'title' => trim((string) ($item['title'] ?? 'Weekend pick')),
                    'scheduled_at' => $startsAt,
                    'remind_at' => $remindAt,
                    'recipient_email' => $email,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('weekend_pick_reminder.schedule_failed', [
                    'weekend_recommendation_id' => $recommendation->id,
                    'item_index' => $index,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function startsAt(array $item): ?Carbon
    {
        $raw = $item['starts_at'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
