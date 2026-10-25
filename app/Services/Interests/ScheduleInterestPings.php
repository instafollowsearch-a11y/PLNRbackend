<?php

namespace App\Services\Interests;

use App\Models\InterestMatch;
use App\Models\InterestPing;
use App\Models\User;
use App\Services\Pro\ProAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ScheduleInterestPings
{
    public function __construct(private readonly ProAccess $proAccess) {}

    /**
     * Sync pending interest pings for all Pro users from their current matches.
     */
    public function syncAll(): void
    {
        User::query()
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $this->syncFor($user);
                }
            });
    }

    public function syncFor(User $user): void
    {
        if (! $this->proAccess->isActive($user)) {
            InterestPing::query()
                ->where('user_id', $user->id)
                ->whereNull('push_sent_at')
                ->delete();

            return;
        }

        $matches = InterestMatch::query()
            ->with('event')
            ->where('user_id', $user->id)
            ->get();

        $activeEventIds = [];

        foreach ($matches as $match) {
            $event = $match->event;

            if ($event === null || $event->starts_at === null) {
                continue;
            }

            if ($event->starts_at->isPast()) {
                continue;
            }

            $activeEventIds[] = $event->id;
            $interest = (string) $match->matched_interest;

            foreach ($this->kindsFor($event->starts_at) as $kind => $remindAt) {
                $this->upsertPending(
                    userId: $user->id,
                    eventId: $event->id,
                    kind: $kind,
                    matchedInterest: $interest,
                    scheduledAt: $event->starts_at,
                    remindAt: $remindAt,
                );
            }
        }

        $pending = InterestPing::query()
            ->where('user_id', $user->id)
            ->whereNull('push_sent_at');

        if ($activeEventIds === []) {
            $pending->delete();
        } else {
            $pending->whereNotIn('event_id', $activeEventIds)->delete();
        }
    }

    /**
     * @return array<string, Carbon> kind => remind_at
     */
    private function kindsFor(Carbon $startsAt): array
    {
        $kinds = [
            InterestPing::KIND_MORNING_OF => $this->atTenOrNow(
                $startsAt->copy()->startOfDay()->setTime(10, 0),
            ),
        ];

        // Day-before only when the event is still tomorrow or later (not already today).
        if ($startsAt->copy()->startOfDay()->greaterThan(now()->startOfDay())) {
            $dayBeforeMorning = $startsAt->copy()->subDay()->startOfDay()->setTime(10, 0);
            $kinds[InterestPing::KIND_DAY_BEFORE] = $this->atTenOrNow($dayBeforeMorning);
        }

        return $kinds;
    }

    private function atTenOrNow(Carbon $remindAt): Carbon
    {
        if ($remindAt->isPast()) {
            return now();
        }

        return $remindAt;
    }

    private function upsertPending(
        int $userId,
        int $eventId,
        string $kind,
        string $matchedInterest,
        Carbon $scheduledAt,
        Carbon $remindAt,
    ): void {
        $existing = InterestPing::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->where('kind', $kind)
            ->first();

        if ($existing instanceof InterestPing && $existing->push_sent_at !== null) {
            return;
        }

        try {
            InterestPing::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'event_id' => $eventId,
                    'kind' => $kind,
                ],
                [
                    'matched_interest' => $matchedInterest,
                    'scheduled_at' => $scheduledAt,
                    'remind_at' => $remindAt,
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('interest_ping.schedule_failed', [
                'user_id' => $userId,
                'event_id' => $eventId,
                'kind' => $kind,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
