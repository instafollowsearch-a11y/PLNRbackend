<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\PlanConsistency;
use App\Services\Events\PlanEventInventory;
use App\Services\Places\AreaLimit;

abstract class AbstractPlanPromptBuilder implements PlanPromptBuilder
{
    public const OPEN_TO_SUGGESTIONS = 'Open to suggestions';

    public const OPEN_TO_SUGGESTIONS_LINE = 'The person selected Open to suggestions. They do not know exactly what they want, so mix different kinds of stops. Any other selected interests are only a light lean.';

    /**
     * Shared pricing realism for every Claude suggestion and itinerary response.
     * Cost numbers must match real venue tiers — never underprice expensive places.
     */
    public const PRICING_REALISM_RULES = 'Pricing must be realistic for the named places. Estimate from typical menu prices, tickets, cover charges, lodging, or travel costs in that city — not a round "nice" number. Fine dining, steakhouses, cocktail bars, clubs, hotels, and major attractions use mid-to-high real-world ranges; never describe an expensive venue as about $15 or similarly cheap. Stay within the stated budget by choosing appropriately priced places, not by underpricing expensive ones. Numeric cost fields and the description must agree. When asked for cheaper options, still use realistic prices for the cheaper tier.';

    /**
     * @var list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>
     */
    protected array $eventCatalog = [];

    /**
     * @param  list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>  $catalog
     */
    public function setEventCatalog(array $catalog): void
    {
        $this->eventCatalog = $catalog;
    }

    /**
     * @return list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>
     */
    public function eventCatalog(): array
    {
        return $this->eventCatalog;
    }

    protected function withItineraryDetail(string $prompt): string
    {
        return $this->withPricingRealism($prompt.' Expand the picked card into a usable plan, not a stub. Summary is 2 to 4 sentences. Each stop has time, the listing or venue name, what you actually do there, and notes of about 40 to 70 words covering vibe, what to expect, how long to stay, and one practical tip. Everyday wording is fine as long as it is obviously the same events as the selected card. Stop time windows must end before known listing hours when hours are listed. Keep JSON only.');
    }

    protected function withCatalogRules(string $prompt): string
    {
        return $this->withPricingRealism($prompt.' Each suggestion must include event_ids from the catalog JSON in the user prompt. Never invent event_id values, event titles, or dates. Use different primary event_ids across cards when the catalog allows. Optional filler venues (dinner, drinks, hotel) may be added beside those events.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<int>
     */
    protected function eventIdsFrom(array $item): array
    {
        $ids = [];

        foreach ($item['event_ids'] ?? [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    protected function withPricingRealism(string $prompt): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return self::PRICING_REALISM_RULES;
        }

        return $prompt.' '.self::PRICING_REALISM_RULES;
    }

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

        $wantsCheaper = PlanConsistency::wantsCheaper($session);
        $wantsLowKey = PlanConsistency::wantsLowKey($session);
        $wantsDifferent = PlanConsistency::wantsDifferent($session);

        if ($wantsCheaper) {
            $lines[] = 'The person already has ideas. Keep the city, date, and group size. Replace the ideas with different plans that cost less.';
        } elseif ($wantsLowKey) {
            $lines[] = 'The person already has ideas. Keep the city, date, and group size. Replace them with a quieter, more low-key plan.';
        } elseif ($wantsDifferent) {
            $lines[] = 'The person already has ideas. Keep the city, date, group size, and a similar price. Replace the places with different ones of the same kind.';
        } else {
            $lines[] = 'The person already has a plan. Keep the city, date, group size, and budget unless their note changes those.';
        }

        $ideaNames = [];

        foreach ($session->suggestions as $suggestion) {
            $payload = $suggestion->payload;
            $name = is_array($payload) ? ($payload['name'] ?? $payload['title'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $ideaNames[] = $name;
            }
        }

        if ($ideaNames !== []) {
            $priced = [];

            foreach ($session->suggestions as $suggestion) {
                $payload = is_array($suggestion->payload) ? $suggestion->payload : [];
                $name = $payload['name'] ?? $payload['title'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                $cost = PlanConsistency::cost($payload);
                $priced[] = $cost === null ? $name : $name.' ($'.$cost.')';
            }

            $lines[] = 'Current ideas: '.implode('; ', $priced !== [] ? $priced : $ideaNames);

            if ($wantsCheaper) {
                $lines[] = PlanConsistency::cheaperInstruction($session);
            } elseif ($wantsLowKey) {
                $lines[] = 'More low-key means a quieter plan than the current one: smaller venues, an earlier pace, no nightclubs, and no big crowds. Do not make it louder, later, or more expensive.';
            } elseif ($wantsDifferent) {
                $lines[] = 'Like this but different means the same kind of outing and a similar price, with different places. Do not repeat the current places.';
            }
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

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function withAreaLimit(PlanSession $session, array $lines): array
    {
        $limit = AreaLimit::fromAnswers($session->answers ?? []);

        if ($limit !== null) {
            $lines[] = $limit->instruction();
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
        $needsHotel = in_array((string) ($answers['needs_hotel'] ?? ''), ['I need a hotel', 'Already booked'], true);
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
            $isYes = (string) ($answers['needs_hotel'] ?? '') === 'I need a hotel';

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
        $catalog = $this->eventCatalog;

        if ($catalog === []) {
            $catalog = app(PlanEventInventory::class)->catalogOnly($session);
            $this->eventCatalog = $catalog;
        }

        if ($catalog === []) {
            return $lines;
        }

        $lines[] = 'Catalog of real local events (JSON). Use only these event_id values. Do not invent events.';
        $lines[] = json_encode($catalog, JSON_UNESCAPED_SLASHES);

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    protected function withLocalEvents(PlanSession $session, array $lines): array
    {
        $withEvents = $this->appendLocalEventsContext($session, $lines);

        if ($withEvents === $lines) {
            return $lines;
        }

        $withEvents[] = 'Every selected catalog event must show up as its own stop. Match by meaning, not exact spelling: "Jazz at the Harbor" is the same listing as "Harbor Jazz Night". Use the catalog title on that stop, set venue_url to the listing URL, and write a fuller activity plus notes around it. You may add dinner or drinks nearby. Do not swap in a different event.';

        return $withEvents;
    }

    protected function formatSuggestionContext(Suggestion $suggestion): string
    {
        $payload = $suggestion->payload ?? [];

        return implode("\n", [
            'Selected option: '.($payload['name'] ?? ''),
            'Description: '.($payload['description'] ?? ''),
            'Payload: '.json_encode($payload),
            PlanConsistency::priceLine($payload),
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
