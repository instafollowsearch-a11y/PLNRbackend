<?php

namespace App\Services\Weekend;

use App\Mail\WeekendRecommendationsMail;
use App\Models\User;
use App\Models\WeekendRecommendation;
use App\Services\AI\WeekendRecommendationService;
use App\Services\Notifications\ExpoPushService;
use App\Services\Pro\ProAccess;
use Illuminate\Support\Facades\Mail;

class DeliverWeekendPicks
{
    public const SENT = 'sent';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly WeekendRecommendationService $recommendations,
        private readonly ProAccess $proAccess,
        private readonly ExpoPushService $push,
        private readonly ScheduleWeekendPickNudges $nudges,
    ) {}

    public function deliver(User $user): string
    {
        if (! $this->ready($user)) {
            return self::SKIPPED;
        }

        [$windowStart, $windowEnd] = $this->recommendations->comingWeekendWindow();
        $latest = WeekendRecommendation::query()
            ->where('user_id', $user->id)
            ->whereDate('window_start', $windowStart->toDateString())
            ->whereDate('window_end', $windowEnd->toDateString())
            ->whereNotNull('email_sent_at')
            ->latest('id')
            ->first();

        if ($latest instanceof WeekendRecommendation && $this->samePreferences($latest, $user)) {
            return self::UNCHANGED;
        }

        $recommendation = $this->recommendations->generate(
            $user,
            (string) $user->city,
            $user->interests ?? [],
        );

        $this->send($user, $recommendation);

        return self::SENT;
    }

    public function send(User $user, WeekendRecommendation $recommendation): void
    {
        Mail::to($user->email)->send(new WeekendRecommendationsMail($recommendation));
        $recommendation->update(['email_sent_at' => now()]);
        $this->push->sendToUser($user, 'Your weekend picks', (string) $recommendation->city, [
            'screen' => 'weekend',
            'recommendation_uuid' => $recommendation->uuid,
        ]);
        $this->nudges->replaceFor($recommendation, (string) $user->email);
    }

    private function ready(User $user): bool
    {
        if (! $this->proAccess->isActive($user)) {
            return false;
        }

        if (trim((string) $user->city) === '') {
            return false;
        }

        return is_array($user->interests) && $user->interests !== [];
    }

    private function samePreferences(WeekendRecommendation $recommendation, User $user): bool
    {
        return $this->cityKey($recommendation->city) === $this->cityKey($user->city)
            && $this->interestKey($recommendation->interests ?? []) === $this->interestKey($user->interests ?? []);
    }

    private function cityKey(mixed $city): string
    {
        return mb_strtolower(trim((string) $city));
    }

    /**
     * @param  list<mixed>  $interests
     */
    private function interestKey(array $interests): string
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn (mixed $interest): string => mb_strtolower(trim((string) $interest)),
            $interests,
        ))));
        sort($items);

        return implode('|', $items);
    }
}
