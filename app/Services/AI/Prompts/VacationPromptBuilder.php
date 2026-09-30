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
        return 'You are a vacation itinerary planner. Return only valid compact JSON with keys: title (string), summary (string), days (array of objects with date, theme, stops where each stop has time, name, activity, and notes).';
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        return implode("\n", $this->withTravelStay($session, $this->withOpenToSuggestions($session, [
            'Create a multi-day vacation itinerary as compact JSON only.',
            $this->itineraryLengthInstruction($session),
            $this->formatAnswers($session),
            $this->formatSuggestionContext($suggestion),
        ])));
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

                $stops[] = $this->normalizeStop($stop);
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
        $raw = $session->answers['dates'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['start'], $decoded['end']) && is_string($decoded['start']) && is_string($decoded['end'])) {
            return $this->daysBetween($decoded['start'], $decoded['end']);
        }

        $parts = preg_split('/\s*(?:–|—| - )\s*/u', trim($raw), 2);

        if (! is_array($parts) || count($parts) !== 2) {
            return null;
        }

        return $this->daysBetween($parts[0], $parts[1]);
    }

    private function daysBetween(string $start, string $end): ?int
    {
        $startDate = $this->parseTripDate($start);
        $endDate = $this->parseTripDate($end);

        if ($startDate === null || $endDate === null) {
            return null;
        }

        if ($endDate->lt($startDate)) {
            $endDate = $endDate->copy()->addYear();
        }

        return (int) $startDate->diffInDays($endDate) + 1;
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
