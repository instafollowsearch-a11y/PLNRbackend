<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Mail\WeekendRecommendationsMail;
use App\Models\User;
use App\Models\WeekendRecommendation;
use App\Services\AI\WeekendRecommendationService;
use App\Services\Notifications\ExpoPushService;
use App\Services\Pro\ProAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendWeekendPicksCommand extends Command
{
    protected $signature = 'weekends:send {--email= : Send only this account}';

    protected $description = 'Email and push the coming weekend picks to Pro accounts';

    public function handle(
        WeekendRecommendationService $recommendations,
        ProAccess $proAccess,
        ExpoPushService $push,
    ): int {
        [$windowStart, $windowEnd] = $recommendations->comingWeekendWindow();
        $email = trim((string) $this->option('email'));
        $sent = 0;
        $skipped = 0;

        $users = User::query()
            ->when($email !== '', fn ($query) => $query->whereRaw('LOWER(email) = ?', [strtolower($email)]))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            if (! $this->shouldSend($user, $proAccess)) {
                $skipped++;

                continue;
            }

            $alreadySent = WeekendRecommendation::query()
                ->where('user_id', $user->id)
                ->whereDate('window_start', $windowStart->toDateString())
                ->whereDate('window_end', $windowEnd->toDateString())
                ->whereNotNull('email_sent_at')
                ->exists();

            if ($alreadySent) {
                $skipped++;

                continue;
            }

            try {
                $recommendation = $this->recommendationForWindow($user, $recommendations, $windowStart, $windowEnd);
                Mail::to($user->email)->send(new WeekendRecommendationsMail($recommendation));
                $recommendation->update(['email_sent_at' => now()]);
                $push->sendToUser($user, 'Your weekend picks', $recommendation->city, [
                    'screen' => 'weekend',
                    'recommendation_uuid' => $recommendation->uuid,
                ]);
                $sent++;
            } catch (Throwable $exception) {
                $skipped++;
                Log::warning('weekend.send_failed', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);
                $this->warn($user->email.': '.$exception->getMessage());
            }
        }

        $this->info("Sent weekend picks to {$sent} accounts. Skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function shouldSend(User $user, ProAccess $proAccess): bool
    {
        if (! $proAccess->isActive($user)) {
            return false;
        }

        if (trim((string) $user->city) === '') {
            return false;
        }

        return is_array($user->interests) && $user->interests !== [];
    }

    private function recommendationForWindow(
        User $user,
        WeekendRecommendationService $recommendations,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): WeekendRecommendation {
        $pending = WeekendRecommendation::query()
            ->where('user_id', $user->id)
            ->whereDate('window_start', $windowStart->toDateString())
            ->whereDate('window_end', $windowEnd->toDateString())
            ->whereNull('email_sent_at')
            ->latest('id')
            ->first();

        if ($pending instanceof WeekendRecommendation) {
            return $pending;
        }

        return $recommendations->generate(
            $user,
            (string) $user->city,
            $user->interests ?? [],
        );
    }
}
