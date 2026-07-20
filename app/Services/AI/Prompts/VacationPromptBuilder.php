<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use InvalidArgumentException;

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
        return implode("\n", $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: vacation',
            'Destination: '.($session->city ?? $session->answers['destination'] ?? ''),
            $this->formatAnswers($session),
        ])));
    }

    public function itinerarySystemPrompt(): string
    {
        return 'You are a vacation itinerary planner. Return only valid JSON with keys: title (string), summary (string), days (array of objects with date, theme, stops where each stop has time, name, activity, notes).';
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        return implode("\n", [
            'Create a multi-day vacation itinerary.',
            $this->formatAnswers($session),
            $this->formatSuggestionContext($suggestion),
        ]);
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

                $stops[] = [
                    'time' => (string) ($stop['time'] ?? ''),
                    'name' => (string) ($stop['name'] ?? ''),
                    'activity' => (string) ($stop['activity'] ?? ''),
                    'notes' => (string) ($stop['notes'] ?? ''),
                ];
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
}
