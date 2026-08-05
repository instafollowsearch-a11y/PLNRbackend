<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use InvalidArgumentException;

class NightOutPromptBuilder extends AbstractPlanPromptBuilder
{
    public function slug(): string
    {
        return 'night_out';
    }

    public function suggestionSystemPrompt(): string
    {
        return 'You are a nightlife planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_cost_per_person (number), time_slot (string), venues (array of strings). Prefer real local events from the user prompt when relevant; you may combine or suggest alternatives.';
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        $answers = $session->answers ?? [];

        return implode("\n", $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: night out',
            'City: '.($session->city ?? $answers['city'] ?? ''),
            'Interests: '.($answers['interests'] ?? ''),
            'Group size: '.($answers['group_size'] ?? ''),
            'Budget per person: '.($answers['budget_per_person'] ?? ''),
            'Dates: '.($answers['dates'] ?? ''),
            'Start time: '.($answers['start_time'] ?? ''),
        ])));
    }

    public function itinerarySystemPrompt(): string
    {
        return 'You are a nightlife itinerary planner. Return only valid compact JSON with keys: title (string), summary (string), stops (array of 3-6 objects with time, name, activity, notes under 25 words, and optional venue_url / maps_url https links when known). Keep the full response under 2500 tokens.';
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        $answers = $session->answers ?? [];
        $payload = $suggestion->payload ?? [];

        return implode("\n", [
            'Create a detailed evening itinerary as compact JSON only.',
            'City: '.($session->city ?? $answers['city'] ?? ''),
            'Group size: '.($answers['group_size'] ?? ''),
            'Budget per person: '.($answers['budget_per_person'] ?? ''),
            'Dates: '.($answers['dates'] ?? ''),
            'Start time: '.($answers['start_time'] ?? ''),
            'Selected option: '.($payload['name'] ?? ''),
            'Description: '.($payload['description'] ?? ''),
            'Time slot: '.($payload['time_slot'] ?? ''),
            'Venues: '.implode(', ', $payload['venues'] ?? []),
        ]);
    }

    public function normalizeSuggestionPayload(array $item): array
    {
        return [
            'name' => (string) ($item['name'] ?? 'Night Out Option'),
            'description' => (string) ($item['description'] ?? ''),
            'estimated_cost_per_person' => (float) ($item['estimated_cost_per_person'] ?? 0),
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
            'title' => (string) ($response['title'] ?? 'Your Night Out'),
            'summary' => (string) ($response['summary'] ?? ''),
            'stops' => $stops,
        ];
    }
}
