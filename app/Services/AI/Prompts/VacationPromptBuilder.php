<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

class VacationPromptBuilder extends AbstractPlanPromptBuilder
{
    public function slug(): string
    {
        return 'vacation';
    }

    public function suggestionSystemPrompt(): string
    {
        return 'You are a vacation planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_cost_total (number), highlights (array of strings). Prefer real local events from the user prompt when relevant; you may combine or suggest alternatives.';
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        return implode("\n", $this->withTravelStay($session, $this->withOpenToSuggestions($session, $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: vacation',
            'Destination: '.($session->city ?? $session->answers['destination'] ?? ''),
            $this->formatAnswers($session),
        ])))));
    }

    public function itinerarySystemPrompt(): string
    {
        return 'You are a vacation itinerary planner. Return only valid compact JSON with keys: title (string), summary (string), days (array of objects with date, theme, stops where each stop has time, name, activity, notes, and cost_per_person). cost_per_person is an estimated number for one person. Omit cost_per_person when you do not have an estimate. Use the year from the travel dates on every day label.';
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        $lines = [
            'Create a multi-day vacation itinerary as compact JSON only.',
            $this->itineraryLengthInstruction($session),
        ];
        $range = $this->tripRange($session);

        if ($range !== null) {
            [$start, $end] = $range;
            $lines[] = 'Travel dates are '.$start->toDateString().' through '.$end->toDateString().'. Use year '.$start->year.' on every day label.';
        }

        $lines[] = $this->formatAnswers($session);
        $lines[] = $this->formatSuggestionContext($suggestion);

        return implode("\n", $this->withTravelStay($session, $this->withOpenToSuggestions($session, $lines)));
    }

    public function normalizeSuggestionPayload(array $item): array
    {
        return [
            'name' => (string) ($item['name'] ?? 'Vacation Option'),
            'description' => (string) ($item['description'] ?? ''),
            'estimated_cost_total' => (float) ($item['estimated_cost_total'] ?? 0),
            'highlights' => array_values(array_filter(
                is_array($item['highlights'] ?? null) ? $item['highlights'] : [],
                fn ($highlight) => is_string($highlight) && $highlight !== '',
            )),
        ];
    }

    public function normalizeItineraryContent(array $response): array
    {
        if (! isset($response['days']) || ! is_array($response['days'])) {
            throw new InvalidArgumentException('OpenAI response missing itinerary days.');
        }

        $days = [];

        foreach ($response['days'] as $day) {
            if (! is_array($day)) {
                continue;
            }

            $stops = [];

            foreach ($day['stops'] ?? [] as $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $normalized = $this->normalizeStop($stop);
                $cost = $this->costPerPerson($stop['cost_per_person'] ?? null);

                if ($cost !== null) {
                    $normalized['cost_per_person'] = $cost;
                }

                $stops[] = $normalized;
            }

            $days[] = [
                'date' => (string) ($day['date'] ?? ''),
                'theme' => (string) ($day['theme'] ?? ''),
                'stops' => $stops,
            ];
        }

        if ($days === []) {
            throw new InvalidArgumentException('No valid itinerary days were generated.');
        }

        return [
            'title' => (string) ($response['title'] ?? 'Your Vacation'),
            'summary' => (string) ($response['summary'] ?? ''),
            'days' => $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function applyTripDates(PlanSession $session, array $content): array
    {
        $range = $this->tripRange($session);
        $days = $content['days'] ?? null;

        if ($range === null || ! is_array($days) || $days === []) {
            return $content;
        }

        [$start, $end] = $range;
        $spanDays = (int) $start->diffInDays($end) + 1;
        $useWeeks = $spanDays >= 32;

        foreach ($days as $index => $day) {
            if (! is_array($day)) {
                continue;
            }

            $date = $useWeeks
                ? $start->copy()->addWeeks($index)
                : $start->copy()->addDays($index);

            if ($date->gt($end)) {
                $date = $end->copy();
            }

            $days[$index]['date'] = $date->format('l, F j, Y');
        }

        $content['days'] = $days;

        return $content;
    }

    private function costPerPerson(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cost = round((float) $value, 2);

        return $cost > 0 ? $cost : null;
    }

    private function itineraryLengthInstruction(PlanSession $session): string
    {
        $days = $this->inclusiveTripDays($session);

        if ($days !== null && $days >= 1 && $days <= 10) {
            return 'Cover each day from the travel dates. Return one days entry per day, with up to 3 stops. Notes may be under 25 words. Include venue_url or maps_url only when you know a real https link.';
        }

        if ($days !== null && $days >= 11 && $days <= 31) {
            return 'Cover each day from the travel dates. Return one days entry per day, with exactly 1 stop. Keep notes under 8 words. Do not include venue_url or maps_url.';
        }

        return 'This stay is long. Return one days entry per week, covering the whole trip. Each entry date is the week span. Include 2 to 3 stops. Do not add a stop for every day.';
    }

    private function inclusiveTripDays(PlanSession $session): ?int
    {
        $range = $this->tripRange($session);

        if ($range === null) {
            return null;
        }

        return (int) $range[0]->diffInDays($range[1]) + 1;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function tripRange(PlanSession $session): ?array
    {
        $raw = $session->answers['dates'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['start'], $decoded['end']) && is_string($decoded['start']) && is_string($decoded['end'])) {
            return $this->orderedRange($decoded['start'], $decoded['end']);
        }

        $parts = preg_split('/\s*(?:–|—| - )\s*/u', trim($raw), 2);

        if (! is_array($parts) || count($parts) !== 2) {
            return null;
        }

        return $this->orderedRange($parts[0], $parts[1]);
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

        return [$startDate, $endDate];
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
