<?php

namespace App\Services\Interests;

use App\Models\InterestMatch;
use App\Models\PlanSession;
use App\Models\User;
use App\Models\WeekendRecommendation;
use Illuminate\Support\Collection;

class InterestAccountDirectory
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     accounts: list<array<string, mixed>>,
     *     meta: array<string, int>
     * }
     */
    public function page(string $search, string $status, int $page, int $perPage): array
    {
        $users = User::query()->orderBy('name')->orderBy('id')->get();
        $matchedIds = InterestMatch::query()->distinct()->pluck('user_id')->flip();
        $classified = $users->map(fn (User $user): array => $this->classify($user, $matchedIds->has($user->id)));
        $summary = $this->summary($classified);

        $filtered = $classified->filter(function (array $row) use ($search, $status): bool {
            if ($status !== 'all' && $row['status'] !== $status) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower($row['name'].' '.$row['email']);

            return str_contains($haystack, mb_strtolower($search));
        })->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = $filtered->slice(($page - 1) * $perPage, $perPage)->values();
        $ids = $slice->pluck('id')->all();

        $matches = InterestMatch::query()
            ->with('event')
            ->whereIn('user_id', $ids)
            ->orderByDesc('score')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $plans = PlanSession::query()
            ->with('planType')
            ->whereIn('user_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        $weekends = WeekendRecommendation::query()
            ->whereIn('user_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $accounts = $slice->map(function (array $row) use ($matches, $plans, $weekends): array {
            $userId = $row['id'];
            unset($row['ready']);

            return [
                ...$row,
                'plan_interests' => $this->planMentions($plans->get($userId, collect())),
                'weekend_interests' => $this->labels($weekends->get($userId)?->interests),
                'matches' => $this->matchPayload($matches->get($userId, collect())),
            ];
        })->all();

        return [
            'summary' => $summary,
            'accounts' => $accounts,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return array<string, mixed>
     */
    private function summary(Collection $classified): array
    {
        $top = [];

        foreach ($classified as $row) {
            $seen = [];

            foreach ($row['saved_interests'] as $label) {
                $key = mb_strtolower($label);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $top[$key] ??= ['label' => $label, 'accounts' => 0];
                $top[$key]['accounts']++;
            }
        }

        $topInterests = array_values($top);
        usort($topInterests, function (array $left, array $right): int {
            if ($left['accounts'] !== $right['accounts']) {
                return $right['accounts'] <=> $left['accounts'];
            }

            return strcasecmp($left['label'], $right['label']);
        });

        return [
            'accounts' => $classified->count(),
            'with_saved_interests' => $classified->filter(fn (array $row): bool => $row['saved_interests'] !== [])->count(),
            'ready' => $classified->where('ready', true)->count(),
            'matched' => $classified->where('status', self::STATUS_MATCHED)->count(),
            'unmatched' => $classified->where('status', self::STATUS_UNMATCHED)->count(),
            'skipped' => $classified->where('status', self::STATUS_SKIPPED)->count(),
            'top_interests' => array_slice($topInterests, 0, 8),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(User $user, bool $hasMatches): array
    {
        $saved = $this->labels($user->interests);
        $hasCity = trim((string) $user->city) !== '';
        $ready = $hasCity && $saved !== [];

        if ($hasMatches) {
            $status = self::STATUS_MATCHED;
            $skipReason = null;
        } elseif ($ready) {
            $status = self::STATUS_UNMATCHED;
            $skipReason = null;
        } else {
            $status = self::STATUS_SKIPPED;
            $skipReason = ! $hasCity && $saved === []
                ? 'no_city_or_interests'
                : (! $hasCity ? 'no_city' : 'no_interests');
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'city' => $user->city,
            'is_pro' => $user->isPro(),
            'role' => $user->role ?? User::ROLE_USER,
            'saved_interests' => $saved,
            'ready' => $ready,
            'status' => $status,
            'skip_reason' => $skipReason,
        ];
    }

    /**
     * @param  Collection<int, PlanSession>  $sessions
     * @return list<array{label: string, city: string|null, plan_type: string|null, created_at: string|null}>
     */
    private function planMentions(Collection $sessions): array
    {
        $mentions = [];
        $seen = [];

        foreach ($sessions as $session) {
            $answers = is_array($session->answers) ? $session->answers : [];

            foreach ($this->labels($answers['interests'] ?? null) as $label) {
                $key = mb_strtolower($label);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $mentions[] = [
                    'label' => $label,
                    'city' => $session->city,
                    'plan_type' => $session->planType?->label,
                    'created_at' => $session->created_at?->toIso8601String(),
                ];

                if (count($mentions) >= 8) {
                    return $mentions;
                }
            }
        }

        return $mentions;
    }

    /**
     * @param  Collection<int, InterestMatch>  $matches
     * @return list<array<string, mixed>>
     */
    private function matchPayload(Collection $matches): array
    {
        return $matches->map(function (InterestMatch $match): array {
            return [
                'id' => $match->id,
                'matched_interest' => $match->matched_interest,
                'score' => $match->score,
                'event' => $match->event === null ? null : [
                    'id' => $match->event->id,
                    'title' => $match->event->title,
                    'city' => $match->event->city,
                    'starts_at' => $match->event->starts_at?->toIso8601String(),
                    'url' => $match->event->url,
                ],
            ];
        })->values()->all();
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|]/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $labels = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['label'] ?? $item['name'] ?? '';
            }

            $label = trim((string) $item);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }
}
