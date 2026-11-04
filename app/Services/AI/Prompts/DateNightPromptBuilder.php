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
        return $this->withCatalogRules('You are a romantic date planning assistant. Return only valid JSON with key "suggestions" containing 3 to 5 objects. Each object must have: name (string), description (string), estimated_cost (number, total for the couple, or for one person when the user prompt says this is a solo date), time_slot (string), venues (array of strings), event_ids (array of catalog event_id numbers). Use as many catalog event_ids as the requested event count when the catalog has enough; never invent ids or exceed the catalog. When the user prompt says this is a solo date, plan the evening for one person and do not include a partner.');
    }

    public function suggestionUserPrompt(PlanSession $session): string
    {
        $answers = $session->answers ?? [];
        $solo = $this->isSolo($answers);

        return implode("\n", $this->withAreaLimit($session, $this->withOpenToSuggestions($session, $this->appendLocalEventsContext($session, $this->appendRefinementMessages($session, [
            $solo
                ? 'Plan type: solo date night for one person. Do not include a partner, couple, or significant other. The budget is for one person.'
                : 'Plan type: date night',
            'City: '.($session->city ?? $answers['city'] ?? ''),
            'Self gender: '.($answers['self_gender'] ?? ''),
            $solo ? 'Going alone: yes' : 'Partner gender: '.($answers['partner_gender'] ?? ''),
            'Timeframe: '.($answers['timeframe'] ?? ''),
            ($solo ? 'Interests: ' : 'Partner interests: ').($answers['partner_interests'] ?? ''),
            'Budget for the night: '.($answers['budget'] ?? ''),
            'Number of events throughout the day: '.($answers['event_count'] ?? ''),
        ])))));
    }

    public function itinerarySystemPrompt(): string
    {
        return $this->withItineraryDetail('You are a romantic date itinerary planner. Return only valid JSON with keys: title (string), summary (string), stops (array of objects with time, name, activity, notes, and optional venue_url / maps_url https links when known). Return one stop per requested event using the same listing names as the picked card, then at most one meal or drinks filler. Keep every stop in the same neighborhood. Travel from one stop to the next is about 10 to 20 minutes, and the next stop starts 45 to 90 minutes after the previous one. Do not leave a two-hour drive between outings. When the user prompt says this is a solo date, plan the evening for one person and do not include a partner.');
    }

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string
    {
        $answers = $session->answers ?? [];
        $solo = $this->isSolo($answers);

        return implode("\n", $this->withAreaLimit($session, $this->withOpenToSuggestions($session, $this->withLocalEvents($session, [
            $solo
                ? 'Create a solo date itinerary for one person as JSON only. Make it fuller than the suggestion card. Do not include a partner.'
                : 'Create a full-day romantic date itinerary as JSON only. Make it fuller than the suggestion card.',
            $this->formatAnswers($session),
            $this->formatSuggestionContext($suggestion),
            'Event count requested: '.($answers['event_count'] ?? ''),
        ]))));
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function isSolo(array $answers): bool
    {
        return ($answers['partner_gender'] ?? '') === 'solo';
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
            'title' => (string) ($response['title'] ?? 'Your Date Night'),
            'summary' => (string) ($response['summary'] ?? ''),
            'stops' => $stops,
        ];
    }
}
