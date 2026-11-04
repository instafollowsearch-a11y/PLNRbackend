<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\PlanConsistency;
use InvalidArgumentException;

class NightOutPromptBuilder extends AbstractPlanPromptBuilder
{
    public function slug(): string
    {
        return 'night_out';
    }

    public function suggestionSystemPrompt(): string
    {
        return $this->withCatalogRules('You are a nightlife planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_cost_per_person (number), time_slot (string), venues (array of strings), event_ids (array of catalog event_id numbers). Use as many catalog event_ids as the catalog allows for that night; never invent ids or exceed the catalog.');
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        $answers = $session->answers ?? [];

        return implode("\n", $this->withAreaLimit($session, $this->withOpenToSuggestions($session, $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: night out',
            'City: '.($session->city ?? $answers['city'] ?? ''),
            'Interests: '.($answers['interests'] ?? ''),
            'Group size: '.($answers['group_size'] ?? ''),
            'Budget per person: '.($answers['budget_per_person'] ?? ''),
            'Dates: '.($answers['dates'] ?? ''),
            'Start time: '.($answers['start_time'] ?? ''),
        ])))));
    }

    public function itinerarySystemPrompt(): string
    {
        return $this->withItineraryDetail('You are a nightlife itinerary planner. Return only valid JSON with keys: title (string), summary (string), stops (array of objects with time, name, activity, notes, and optional venue_url / maps_url https links when known). Return one stop per required event_id using the same listing names as the picked card, then at most one meal or drinks filler. Keep every stop in the same neighborhood. Travel from one stop to the next is about 10 to 20 minutes, and the next stop starts 45 to 90 minutes after the previous one. Do not leave a two-hour drive between outings.');
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        $answers = $session->answers ?? [];
        $payload = $suggestion->payload ?? [];

        return implode("\n", $this->withAreaLimit($session, $this->withOpenToSuggestions($session, $this->withLocalEvents($session, [
            'Create a detailed evening itinerary as JSON only. Make it fuller than the suggestion card: walk through the night stop by stop.',
            'City: '.($session->city ?? $answers['city'] ?? ''),
            'Group size: '.($answers['group_size'] ?? ''),
            'Budget per person: '.($answers['budget_per_person'] ?? ''),
            'Dates: '.($answers['dates'] ?? ''),
            'Start time: '.($answers['start_time'] ?? ''),
            'Selected option: '.($payload['name'] ?? ''),
            'Description: '.($payload['description'] ?? ''),
            'Time slot: '.($payload['time_slot'] ?? ''),
            'Venues: '.implode(', ', $payload['venues'] ?? []),
            'Required event_ids: '.implode(', ', $payload['event_ids'] ?? []),
            PlanConsistency::priceLine($payload),
        ]))));
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
            'event_ids' => $this->eventIdsFrom($item),
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
