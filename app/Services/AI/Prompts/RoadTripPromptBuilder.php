<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use InvalidArgumentException;

class RoadTripPromptBuilder extends AbstractPlanPromptBuilder
{
    public function slug(): string
    {
        return 'road_trip';
    }

    public function suggestionSystemPrompt(): string
    {
        return 'You are a road trip planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_gas_cost (number), estimated_food_cost (number), total_drive_time (string), stops (array of objects with name, closing_time, duration). Prefer real local events from the user prompt when relevant; you may combine or suggest alternatives.';
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        return implode("\n", $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            'Plan type: road trip',
            $this->formatAnswers($session),
        ])));
    }

    public function itinerarySystemPrompt(): string
    {
        return 'You are a road trip itinerary planner. Return only valid JSON with keys: title (string), summary (string), stops (array of objects with time, name, activity, notes, and optional venue_url / maps_url https links when a real venue page or maps link is known). Include departure, stops, food breaks, and arrival.';
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        return implode("\n", [
            'Create a detailed road trip itinerary with times for each leg.',
            $this->formatAnswers($session),
            $this->formatSuggestionContext($suggestion),
        ]);
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
            'title' => (string) ($response['title'] ?? 'Your Road Trip'),
            'summary' => (string) ($response['summary'] ?? ''),
            'stops' => $stops,
        ];
    }
}
