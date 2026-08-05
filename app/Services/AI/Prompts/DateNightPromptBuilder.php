<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use InvalidArgumentException;

class DateNightPromptBuilder extends AbstractPlanPromptBuilder
{
    public function slug(): string
    {
        return 'date_night';
    }

    public function suggestionSystemPrompt(): string
    {
        return 'You are a romantic date planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_cost (number, total for the couple), time_slot (string), venues (array of strings). Prefer real local events from the user prompt when relevant; you may combine or suggest alternatives.';
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        $answers = $session->answers ?? [];

        return implode("\n", $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: date night',
            'City: '.($session->city ?? $answers['city'] ?? ''),
            'Self gender: '.($answers['self_gender'] ?? ''),
            'Partner gender: '.($answers['partner_gender'] ?? ''),
            'Timeframe: '.($answers['timeframe'] ?? ''),
            'Partner interests: '.($answers['partner_interests'] ?? ''),
            'Budget for the night: '.($answers['budget'] ?? ''),
            'Number of events throughout the day: '.($answers['event_count'] ?? ''),
        ])));
    }

    public function itinerarySystemPrompt(): string
    {
        return 'You are a romantic date itinerary planner. Return only valid compact JSON with keys: title (string), summary (string), stops (array of 3-8 objects with time, name, activity, notes under 25 words, and optional venue_url / maps_url https links when known). Cover the requested number of events. Keep the full response under 2500 tokens.';
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        $answers = $session->answers ?? [];

        return implode("\n", [
            'Create a full-day romantic date itinerary as compact JSON only.',
            $this->formatAnswers($session),
            $this->formatSuggestionContext($suggestion),
            'Event count requested: '.($answers['event_count'] ?? ''),
        ]);
    }

    public function normalizeSuggestionPayload(array $item): array
    {
        return [
            'name' => (string) ($item['name'] ?? 'Date Night Option'),
            'description' => (string) ($item['description'] ?? ''),
            'estimated_cost' => (float) ($item['estimated_cost'] ?? 0),
            'time_slot' => (string) ($item['time_slot'] ?? ''),
            'venues' => array_values(array_filter(
                is_array($item['venues'] ?? null) ? $item['venues'] : [],
                fn ($venue) => is_string($venue) && $venue !== '',
            )),
        ];
    }

    public function normalizeItineraryContent(array $response): array
    {
        if (! isset($response['stops']) || ! is_array($response['stops'])) {
            throw new InvalidArgumentException('OpenAI response missing itinerary stops.');
        }

        $stops = [];

        foreach ($response['stops'] as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $stops[] = $this->normalizeStop($stop);
        }

        if ($stops === []) {
            throw new InvalidArgumentException('No valid itinerary stops were generated.');
        }

        return [
            'title' => (string) ($response['title'] ?? 'Your Date Night'),
            'summary' => (string) ($response['summary'] ?? ''),
            'stops' => $stops,
        ];
    }
}
