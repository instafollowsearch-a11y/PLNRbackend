<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\PlanSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlanEventInventory
{
    public const MIN_CATALOG = 8;

    public const MAX_CATALOG = CityListingInventory::MAX_CATALOG;

    public const CACHE_SECONDS = CityListingInventory::CACHE_SECONDS;

    public function __construct(
        private readonly CityListingInventory $listings,
        private readonly FindLocalStopLinks $links,
    ) {}

    /**
     * @return list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>
     */
    public function forSession(PlanSession $session, bool $allowScout = true): array
    {
        $city = $this->cityFor($session);
        [$windowStart, $windowEnd] = $this->windowFor($session);
        $interests = $this->interests($session);
        $events = $this->listings->fill(
            $city,
            $windowStart,
            $windowEnd,
            $interests,
            $allowScout,
            'plan_web_search',
        );
        $rows = $this->compact($this->rank($events, $interests));

        if ($rows === []) {
            throw ValidationException::withMessages([
                'events' => ['We could not find enough upcoming events for this city. Try another city or different dates.'],
            ]);
        }

        return $rows;
    }

    /**
     * Catalog rows for prompts without throwing when the city is empty.
     *
     * @return list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>
     */
    public function catalogOnly(PlanSession $session): array
    {
        try {
            return $this->forSession($session, false);
        } catch (ValidationException) {
            return [];
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Event>
     */
    public function eventsByIds(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return collect();
        }

        return Event::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    public function cityFor(PlanSession $session): string
    {
        $answers = is_array($session->answers) ? $session->answers : [];
        $candidates = [
            $answers['city'] ?? null,
            $answers['destination'] ?? null,
            $answers['start_location'] ?? null,
            $session->city,
        ];

        foreach ($candidates as $city) {
            if (is_string($city) && trim($city) !== '') {
                return trim($city);
            }
        }

        return '';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(PlanSession $session): array
    {
        $answers = is_array($session->answers) ? $session->answers : [];
        $range = $this->tripRange($answers);

        if ($range !== null) {
            return $range;
        }

        $dates = trim((string) ($answers['dates'] ?? $answers['timeframe'] ?? ''));
        $parsed = $this->parseLooseDate($dates);

        if ($parsed !== null) {
            return [$parsed->copy()->startOfDay(), $parsed->copy()->endOfDay()];
        }

        return [now()->startOfDay(), now()->addDays(7)->endOfDay()];
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  list<string>  $interests
     * @return Collection<int, Event>
     */
    private function rank(Collection $events, array $interests): Collection
    {
        if ($interests === []) {
            return $events->values();
        }

        $tokens = array_values(array_filter(array_map(
            static fn (string $interest): string => strtolower($interest),
            $interests,
        )));

        return $events->sortBy(function (Event $event) use ($tokens): array {
            $haystack = strtolower($event->title.' '.$event->venue_name.' '.$event->description);
            $score = 0;

            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($haystack, $token)) {
                    $score++;
                }
            }

            return [-$score, $event->starts_at?->timestamp ?? 0];
        })->values();
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>
     */
    private function compact(Collection $events): array
    {
        return $events->take(self::MAX_CATALOG)->map(fn (Event $event): array => [
            'event_id' => $event->id,
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => $this->links->urlFor($event),
            'source' => $event->source,
        ])->values()->all();
    }

    /**
     * @return list<string>
     */
    private function interests(PlanSession $session): array
    {
        $answers = is_array($session->answers) ? $session->answers : [];
        $raw = $answers['interests'] ?? $answers['partner_interests'] ?? '';

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,\/]| and /i', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts,
        )));
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function tripRange(array $answers): ?array
    {
        $raw = $answers['dates'] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && isset($decoded['start'], $decoded['end']) && is_string($decoded['start']) && is_string($decoded['end'])) {
                $range = $this->orderedRange($decoded['start'], $decoded['end']);
                if ($range !== null) {
                    return $range;
                }
            }

            $parts = preg_split('/\s*(?:–|—| - )\s*/u', trim($raw), 2);
            if (is_array($parts) && count($parts) === 2) {
                $range = $this->orderedRange($parts[0], $parts[1]);
                if ($range !== null) {
                    return $range;
                }
            }
        }

        $start = $this->parseTripDate((string) ($answers['departure_date'] ?? ''));
        $end = $this->parseTripDate((string) ($answers['arrival_date'] ?? ''));

        if ($start === null || $end === null) {
            return null;
        }

        if ($end->lt($start)) {
            $end = $end->copy()->addYear();
        }

        return [$start->copy()->startOfDay(), $end->copy()->endOfDay()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function orderedRange(string $start, string $end): ?array
    {
        $startDate = $this->parseTripDate($start);
        $endDate = $this->parseTripDate($end);

        if ($startDate === null || $endDate === null) {
            return null;
        }

        if ($endDate->lt($startDate)) {
            $endDate = $endDate->copy()->addYear();
        }

        return [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()];
    }

    private function parseTripDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace(
            '/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday),?\s*/i',
            '',
            $value,
        ) ?? $value;
        $value = trim($value);

        if (! preg_match('/\d{4}/', $value)) {
            $value .= ' '.now()->year;
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        if ($date->lt(now()->startOfDay())) {
            $date->addYear();
        }

        return $date;
    }

    private function parseLooseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $value, $matches) === 1) {
            $dayName = strtolower($matches[1]);

            if (strtolower((string) now()->englishDayOfWeek) === $dayName) {
                return now()->startOfDay();
            }

            try {
                return now()->next($dayName)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        return $this->parseTripDate($value);
    }
}
