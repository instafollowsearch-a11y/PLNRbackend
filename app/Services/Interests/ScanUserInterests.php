<?php

namespace App\Services\Interests;

use App\Models\InterestMatch;
use App\Models\InterestScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ScanUserInterests
{
    public function __construct(private readonly InterestEventMatcher $matcher) {}

    public function run(): InterestScan
    {
        $scan = InterestScan::query()->create([
            'started_at' => now(),
            'users_checked' => 0,
            'matches_kept' => 0,
            'users_skipped' => 0,
        ]);

        $checked = 0;
        $kept = 0;
        $skipped = 0;

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($scan, &$checked, &$kept, &$skipped): void {
            foreach ($users as $user) {
                if (! $this->ready($user)) {
                    InterestMatch::query()->where('user_id', $user->id)->delete();
                    $skipped++;

                    continue;
                }

                $matches = $this->matcher->match($user);
                $checked++;

                DB::transaction(function () use ($user, $scan, $matches, &$kept): void {
                    InterestMatch::query()->where('user_id', $user->id)->delete();

                    foreach ($matches as $match) {
                        InterestMatch::query()->create([
                            'interest_scan_id' => $scan->id,
                            'user_id' => $user->id,
                            'event_id' => $match['event_id'],
                            'matched_interest' => $match['matched_interest'],
                            'score' => $match['score'],
                        ]);
                        $kept++;
                    }
                });
            }
        });

        $scan->update([
            'finished_at' => now(),
            'users_checked' => $checked,
            'matches_kept' => $kept,
            'users_skipped' => $skipped,
        ]);

        return $scan->fresh() ?? $scan;
    }

    private function ready(User $user): bool
    {
        if (trim((string) $user->city) === '') {
            return false;
        }

        if (! is_array($user->interests)) {
            return false;
        }

        foreach ($user->interests as $interest) {
            if (trim((string) $interest) !== '') {
                return true;
            }
        }

        return false;
    }
}
