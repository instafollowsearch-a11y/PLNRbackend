<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

class RoadTripPromptBuilder extends AbstractPlanPromptBuilder
{
    public function slug(): string
    {
        return 'road_trip';
    }

    public function suggestionSystemPrompt(): string
    {
        return $this->withPricingRealism('You are a road trip planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_gas_cost (number), estimated_food_cost (number), total_drive_time (string), stops (array of objects with name, closing_time, duration). Base estimated_gas_cost on the chosen car type. duration is how long to stay, such as 45 min. closing_time is when to be there. Prefer real local events from the user prompt when relevant; you may combine or suggest alternatives.');
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        return implode("\n", $this->withTravelStay($session, $this->withOpenToSuggestions($session, $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: road trip',
            $this->formatAnswers($session),
        ])))));
    }

    public function itinerarySystemPrompt(): string
    {
        return $this->withPricingRealism('You are a road trip itinerary planner. Return only valid compact JSON with keys: title (string), summary (string), days (array of objects with date, theme, and stops). Each stop has time, name, activity, notes under 25 words, and optional venue_url / maps_url https links when known. Return one days entry for every calendar day from departure through arrival. A same-day drive is one day. time is a clock time. The notes include how long to stay. Include departure, stops, food breaks, and arrival. Keep the full response under 2500 tokens.');
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        return implode("\n", $this->withTravelStay($session, $this->withOpenToSuggestions($session, $this->withLocalEvents($session, [
            'Create a detailed road trip itinerary with one day for each travel date as compact JSON only.',
            $this->formatAnswers($session),
            $this->formatSuggestionContext($suggestion),
        ]))));
    }

    public function normalizeSuggestionPayload(array $item): array
    {
        $stops = [];

        foreach ($item['stops'] ?? [] as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $stops[] = [
                'name' => (string) ($stop['name'] ?? ''),
                'closing_time' => (string) ($stop['closing_time'] ?? ''),
                'duration' => (string) ($stop['duration'] ?? ''),
            ];
        }

        return [
            'name' => (string) ($item['name'] ?? 'Road Trip Option'),
            'description' => (string) ($item['description'] ?? ''),
            'estimated_gas_cost' => (float) ($item['estimated_gas_cost'] ?? 0),
            'estimated_food_cost' => (float) ($item['estimated_food_cost'] ?? 0),
            'total_drive_time' => (string) ($item['total_drive_time'] ?? ''),
            'stops' => $stops,
        ];
    }

    public function normalizeItineraryContent(array $response): array
    {
        $days = $this->normalizeDays($response['days'] ?? null);

        if ($days !== []) {
            return [
                'title' => (string) ($response['title'] ?? 'Your Road Trip'),
                'summary' => (string) ($response['summary'] ?? ''),
                'days' => $days,
            ];
        }

        $stops = $this->normalizeStops($response['stops'] ?? null);

        if ($stops === []) {
            throw new InvalidArgumentException('OpenAI response missing itinerary stops.');
        }

        return [
            'title' => (string) ($response['title'] ?? 'Your Road Trip'),
            'summary' => (string) ($response['summary'] ?? ''),
            'stops' => $stops,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function applyTripDates(PlanSession $session, array $content): array
    {
        $days = $content['days'] ?? null;

        if (! is_array($days) || $days === []) {
            $stops = $content['stops'] ?? [];

            if (! is_array($stops) || $stops === []) {
                return $content;
            }

            $groups = $this->groupStops($stops, $session);

            if (count($groups) < 2) {
                return $content;
            }

            $days = [];

            foreach ($groups as $group) {
                $days[] = [
                    'date' => '',
                    'theme' => '',
                    'stops' => $group,
                ];
            }

            unset($content['stops']);
        }

        $content['days'] = $this->stampDates($session, $days);

        return $content;
    }

    /**
     * @return list<array{date: string, theme: string, stops: list<array<string, mixed>>}>
     */
    private function normalizeDays(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        $normalized = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $stops = $this->normalizeStops($day['stops'] ?? null);

            if ($stops === []) {
                continue;
            }

            $normalized[] = [
                'date' => (string) ($day['date'] ?? ''),
                'theme' => (string) ($day['theme'] ?? ''),
                'stops' => $stops,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeStops(mixed $stops): array
    {
        if (! is_array($stops)) {
            return [];
        }

        $normalized = [];

        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $normalized[] = $this->normalizeStop($stop);
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<list<array<string, mixed>>>
     */
    private function groupStops(array $stops, PlanSession $session): array
    {
        $groups = [[]];
        $previousClock = null;
        $previousDay = null;

        foreach ($stops as $stop) {
            $day = $this->dayNumber($stop);
            $clock = $this->clockMinutes((string) ($stop['time'] ?? ''));
            $rolledBack = $clock !== null && $previousClock !== null && $clock + 90 < $previousClock;
            $nextDay = $day !== null && $previousDay !== null && $day !== $previousDay;

            if (($rolledBack || $nextDay) && $groups[array_key_last($groups)] !== []) {
                $groups[] = [];
            }

            $groups[array_key_last($groups)][] = $stop;

            if ($day !== null) {
                $previousDay = $day;
            }

            if ($clock !== null) {
                $previousClock = $clock;
            }
        }

        $groups = array_values(array_filter($groups, fn (array $group): bool => $group !== []));

        if (count($groups) > 1) {
            return $groups;
        }

        $span = $this->tripDayCount($session);

        if ($span < 2) {
            return $groups;
        }

        return $this->chunkStops($stops, $span);
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<list<array<string, mixed>>>
     */
    private function chunkStops(array $stops, int $days): array
    {
        $count = min(max($days, 1), count($stops));
        $base = intdiv(count($stops), $count);
        $extra = count($stops) % $count;
        $groups = [];
        $index = 0;

        for ($day = 0; $day < $count; $day++) {
            $size = $base + ($extra > 0 ? 1 : 0);

            if ($extra > 0) {
                $extra--;
            }

            $groups[] = array_slice($stops, $index, $size);
            $index += $size;
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group !== []));
    }

    /**
     * @param  array<string, mixed>  $stop
     */
    private function dayNumber(array $stop): ?int
    {
        $text = trim(((string) ($stop['time'] ?? '')).' '.((string) ($stop['activity'] ?? '')).' '.((string) ($stop['notes'] ?? '')));

        if (preg_match('/\bday\s*(\d+)\b/i', $text, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function clockMinutes(string $time): ?int
    {
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', trim($time), $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) ($matches[2] ?? 0);
        $meridiem = strtolower((string) ($matches[3] ?? ''));

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        if ($meridiem === 'pm' && $hours < 12) {
            $hours += 12;
        }

        if ($meridiem === 'am' && $hours === 12) {
            $hours = 0;
        }

        return $hours * 60 + $minutes;
    }

    private function tripDayCount(PlanSession $session): int
    {
        $range = $this->tripRange($session);

        if ($range === null) {
            return 1;
        }

        return (int) $range[0]->diffInDays($range[1]) + 1;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function stampDates(PlanSession $session, array $days): array
    {
        $range = $this->tripRange($session);

        if ($range === null) {
            foreach ($days as $index => $day) {
                if (trim((string) ($day['date'] ?? '')) === '') {
                    $days[$index]['date'] = 'Day '.($index + 1);
                }
            }

            return $days;
        }

        [$start, $end] = $range;

        foreach ($days as $index => $day) {
            $date = $start->copy()->addDays($index);

            if ($date->gt($end)) {
                $date = $end->copy();
            }

            $days[$index]['date'] = $date->format('l, F j, Y');
        }

        return $days;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function tripRange(PlanSession $session): ?array
    {
        $answers = $session->answers ?? [];
        $start = $this->parseTripDate((string) ($answers['departure_date'] ?? ''));
        $end = $this->parseTripDate((string) ($answers['arrival_date'] ?? ''));

        if ($start === null || $end === null) {
            return null;
        }

        if ($end->lt($start)) {
            $end = $end->copy()->addYear();
        }

        return [$start, $end];
    }

    private function parseTripDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/\d{4}/', $value)) {
            $value .= ' '.now()->year;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
