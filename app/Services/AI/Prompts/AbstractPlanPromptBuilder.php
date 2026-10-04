<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\Events\EventContextService;

abstract class AbstractPlanPromptBuilder implements PlanPromptBuilder
{
    public const OPEN_TO_SUGGESTIONS = 'Open to suggestions';

    public const OPEN_TO_SUGGESTIONS_LINE = 'The person selected Open to suggestions. They do not know exactly what they want, so mix different kinds of stops. Any other selected interests are only a light lean.';

    protected function appendRefinementMessages(PlanSession $session, array $lines): array
    {
        $requests = [];

        foreach ($session->refinement_messages ?? [] as $message) {
            if (($message['role'] ?? '') === 'user' && is_string($message['content'] ?? null) && $message['content'] !== '') {
                $requests[] = $message['content'];
            }
        }

        if ($requests === []) {
            return $lines;
        }

        $lines[] = 'The person already has a plan. Keep the city, date, group size, and budget unless their note changes those.';

        $ideaNames = [];

        foreach ($session->suggestions as $suggestion) {
            $payload = $suggestion->payload;
            $name = is_array($payload) ? ($payload['name'] ?? $payload['title'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $ideaNames[] = $name;
            }
        }

        if ($ideaNames !== []) {
            $lines[] = 'Current ideas: '.implode('; ', $ideaNames);
        }

        $stopNames = $this->savedPlanStopNames($session);

        if ($stopNames !== []) {
            $lines[] = 'Saved plan stops: '.implode('; ', $stopNames);
        }

        $lines[] = 'User refinement requests:';

        foreach ($requests as $request) {
            $lines[] = '- '.$request;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function savedPlanStopNames(PlanSession $session): array
    {
        $content = $session->itinerary?->content;

        if (! is_array($content)) {
            return [];
        }

        $names = [];

        foreach ($this->itineraryStopLists($content) as $stop) {
            $name = $stop['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function itineraryStopLists(array $content): array
    {
        $stops = [];
        $listedStops = $content['stops'] ?? [];

        if (is_array($listedStops)) {
            foreach ($listedStops as $stop) {
                if (is_array($stop)) {
                    $stops[] = $stop;
                }
            }
        }

        $days = $content['days'] ?? [];

        if (is_array($days)) {
            foreach ($days as $day) {
                if (! is_array($day)) {
                    continue;
                }

                $dayStops = $day['stops'] ?? [];

                if (! is_array($dayStops)) {
                    continue;
                }

                foreach ($dayStops as $stop) {
                    if (is_array($stop)) {
                        $stops[] = $stop;
                    }
                }
            }
        }

        return $stops;
    }

    protected function formatAnswers(PlanSession $session): string
    {
        $answers = $session->answers ?? [];
        $lines = [];

        foreach ($answers as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function withOpenToSuggestions(PlanSession $session, array $lines): array
    {
        $line = $this->openToSuggestionsLine($session);

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    protected function openToSuggestionsLine(PlanSession $session): string
    {
        foreach ($session->answers ?? [] as $value) {
            if (is_string($value) && str_contains($value, self::OPEN_TO_SUGGESTIONS)) {
                return self::OPEN_TO_SUGGESTIONS_LINE;
            }
        }

        return '';
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function withTravelStay(PlanSession $session, array $lines): array
    {
        $line = $this->travelStayLine($session);

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    protected function travelStayLine(PlanSession $session): string
    {
        $answers = $session->answers ?? [];
        $needsHotel = in_array((string) ($answers['needs_hotel'] ?? ''), ["I don't have a hotel", 'Already booked'], true);
        $isFlying = (string) ($answers['flying'] ?? '') === 'Yes';

        if (! $needsHotel && ! $isFlying) {
            return '';
        }

        $parts = [];

        if ($needsHotel) {
            $pick = trim((string) ($answers['hotel_pick'] ?? ''));
            $namedFromPick = $pick !== '' && $pick !== '__suggest__' ? $pick : '';
            $location = $namedFromPick !== ''
                ? $namedFromPick
                : trim((string) ($answers['hotel_location'] ?? ''));
            $shuttle = trim((string) ($answers['hotel_shuttle'] ?? ''));
            $isYes = (string) ($answers['needs_hotel'] ?? '') === "I don't have a hotel";

            if ($isYes && $location === '') {
                $base = 'They need a hotel. Suggest 2 or 3 real hotels with https website links in the plan. Do not book the hotel.';
            } else {
                $base = $location !== ''
                    ? 'Use the hotel at '.$location.' as the base for the plan.'
                    : 'Use the hotel as the base for the plan.';

                if ($isYes) {
                    $base .= ' Include a link to the hotel site when you know one. Do not book the hotel.';
                }
            }

            if ($shuttle !== '') {
                $base .= ' Account for the hotel shuttle: '.$shuttle.'.';
            }

            $parts[] = $base;
        }

        if ($isFlying) {
            $parts[] = 'They are flying, so account for the flight in the plan.';
        }

        return implode(' ', $parts);
    }

    protected function appendLocalEventsContext(PlanSession $session, array $lines): array
    {
        $city = $session->city ?? ($session->answers['city'] ?? null);
        $context = app(EventContextService::class)->formatForPrompt(is_string($city) ? $city : null);

        if ($context !== '') {
            $lines[] = $context;
        }

        return $lines;
    }

    protected function formatSuggestionContext(Suggestion $suggestion): string
    {
        $payload = $suggestion->payload ?? [];

        return implode("\n", [
            'Selected option: '.($payload['name'] ?? ''),
            'Description: '.($payload['description'] ?? ''),
            'Payload: '.json_encode($payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    protected function normalizeStop(array $stop): array
    {
        $normalized = [
            'time' => (string) ($stop['time'] ?? ''),
            'name' => (string) ($stop['name'] ?? ''),
            'activity' => (string) ($stop['activity'] ?? ''),
            'notes' => (string) ($stop['notes'] ?? ''),
        ];

        $venueUrl = $this->sanitizeHttpsUrl(
            $stop['venue_url'] ?? $stop['url'] ?? $stop['booking_url'] ?? null,
        );
        $mapsUrl = $this->sanitizeHttpsUrl($stop['maps_url'] ?? null);
        $externalUrl = $this->sanitizeHttpsUrl(
            $stop['external_url'] ?? $stop['link'] ?? $stop['website'] ?? null,
        );

        if ($venueUrl !== null) {
            $normalized['venue_url'] = $venueUrl;
        }

        if ($mapsUrl !== null) {
            $normalized['maps_url'] = $mapsUrl;
        }

        if ($externalUrl !== null) {
            $normalized['external_url'] = $externalUrl;
        }

        return $normalized;
    }

    protected function sanitizeHttpsUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            return null;
        }

        return $url;
    }
}
