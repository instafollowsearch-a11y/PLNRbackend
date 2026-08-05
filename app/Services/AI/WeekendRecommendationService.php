<?php

namespace App\Services\AI;

use App\Models\Event;
use App\Models\User;
use App\Models\WeekendRecommendation;
use App\Services\Events\EventIngestionService;
use App\Services\Events\EventSourceResolver;
use App\Services\Events\NormalizedEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class WeekendRecommendationService
{
    private const MIN_RECOMMENDATIONS = 3;

    private const MAX_RECOMMENDATIONS = 5;

    public function __construct(
        private readonly AiChatClient $client,
        private readonly EventIngestionService $ingestion,
        private readonly EventSourceResolver $sources,
    ) {}

    /**
     * @param  list<string>  $interests
     */
    public function generate(User $user, string $city, array $interests): WeekendRecommendation
    {
        $interests = array_values(array_filter(array_map(
            static fn (mixed $interest): string => trim((string) $interest),
            $interests,
        )));

        if ($interests === []) {
            throw ValidationException::withMessages([
                'interests' => ['Select at least one interest.'],
            ]);
        }

        $city = trim($city);
        if ($city === '') {
            throw ValidationException::withMessages([
                'city' => ['City is required.'],
            ]);
        }

        $cityKey = $this->primaryCityName($city);
        $windowStart = now()->startOfDay();
        $windowEnd = now()->addDays(7)->endOfDay();

        $this->trySyncLocalCatalog($cityKey);

        $events = $this->upcomingEventsForCity($city, $cityKey, $windowStart, $windowEnd);

        $items = null;

        if ($events->count() >= self::MIN_RECOMMENDATIONS) {
            try {
                $items = $this->recommendFromCatalog($city, $interests, $windowStart, $windowEnd, $events);
            } catch (Throwable $exception) {
                Log::warning('weekend.catalog_recommend_failed', [
                    'city' => $city,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($items === null || count($items) < self::MIN_RECOMMENDATIONS) {
            $items = $this->recommendFromWebSearch($city, $cityKey, $interests, $windowStart, $windowEnd, $events);
        }

        if (count($items) < self::MIN_RECOMMENDATIONS) {
            throw ValidationException::withMessages([
                'events' => ['We could not find enough upcoming events for this city. Try another city or different interests.'],
            ]);
        }

        $user->forceFill([
            'city' => $cityKey,
            'interests' => $interests,
        ])->save();

        return WeekendRecommendation::query()->create([
            'user_id' => $user->id,
            'city' => $cityKey,
            'interests' => $interests,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'items' => $items,
        ]);
    }

    /**
     * @param  list<string>  $interests
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function recommendFromCatalog(
        string $city,
        array $interests,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $events,
    ): array {
        $catalog = $events->map(fn (Event $event): array => [
            'event_id' => $event->id,
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => $event->url,
            'source' => $event->source,
            'description' => mb_substr((string) ($event->description ?? ''), 0, 240),
        ])->values()->all();

        $response = $this->client->chat([
            [
                'role' => 'system',
                'content' => 'You are PLNR weekend recommender. Pick 3 to 5 real events from the provided catalog that best match the user interests for the upcoming week. Respond ONLY with JSON: {"recommendations":[{"event_id":number,"reason":"short why"}]}. Never invent event_id values.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'city' => $city,
                    'interests' => $interests,
                    'window_start' => $windowStart->toIso8601String(),
                    'window_end' => $windowEnd->toIso8601String(),
                    'events' => $catalog,
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $items = $this->normalizeCatalogItems($response, $events);

        if (count($items) < self::MIN_RECOMMENDATIONS) {
            throw new InvalidArgumentException('AI did not return enough matching event recommendations.');
        }

        return $items;
    }

    /**
     * Browse the live web for upcoming local events, then persist + return picks.
     *
     * @param  list<string>  $interests
     * @param  Collection<int, Event>  $catalogEvents
     * @return list<array<string, mixed>>
     */
    private function recommendFromWebSearch(
        string $city,
        string $cityKey,
        array $interests,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $catalogEvents,
    ): array {
        $catalogHint = $catalogEvents->take(12)->map(fn (Event $event): array => [
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => $event->url,
            'source' => $event->source,
        ])->values()->all();

        $response = $this->client->chat(
            [
                [
                    'role' => 'system',
                    'content' => <<<'PROMPT'
You are PLNR weekend event scout. You MUST use web search to find real, bookable or RSVP-able local events happening in the user's city during the next seven days.

Search Eventbrite, Allevents, Luma, Ticketmaster, venue calendars, city event guides, and similar listings. Prefer events that clearly match the user's interests.

Rules:
- Only include events you can verify from search results (real titles, venues, dates).
- starts_at must fall inside the provided window (ISO-8601 with timezone when known).
- Prefer official ticket/listing URLs.
- Never invent fake venues or past events.
- Return 3 to 5 picks ranked best-match first.

Respond ONLY with JSON:
{"recommendations":[{"title":"string","venue":"string|null","starts_at":"ISO-8601","url":"https://...","source":"eventbrite|allevents|luma|ticketmaster|other","reason":"short why it matches"}]}
PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'city' => $city,
                        'city_key' => $cityKey,
                        'interests' => $interests,
                        'window_start' => $windowStart->toIso8601String(),
                        'window_end' => $windowEnd->toIso8601String(),
                        'today' => now()->toIso8601String(),
                        'known_local_catalog' => $catalogHint,
                        'instructions' => 'Search the open web for upcoming events in this city matching these interests. Ignore known_local_catalog unless it helps avoid duplicates.',
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            true,
            4096,
            [
                'web_search' => true,
                'web_search_max_uses' => 8,
                'user_location' => [
                    'type' => 'approximate',
                    'city' => $cityKey,
                ],
            ],
        );

        return $this->normalizeWebItems($response, $cityKey, $windowStart, $windowEnd);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function normalizeCatalogItems(array $response, Collection $events): array
    {
        $raw = $response['recommendations'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $byId = $events->keyBy('id');
        $items = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $eventId = (int) ($row['event_id'] ?? 0);
            $event = $byId->get($eventId);
            if (! $event instanceof Event) {
                continue;
            }

            $items[] = $this->itemFromEvent(
                $event,
                trim((string) ($row['reason'] ?? 'Matches your interests.')),
            );

            if (count($items) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function normalizeWebItems(
        array $response,
        string $cityKey,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): array {
        $raw = $response['recommendations'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $items = [];
        $seen = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $startsAt = $this->parseStartsAt($row['starts_at'] ?? null);
            if ($startsAt === null || $startsAt->lt($windowStart) || $startsAt->gt($windowEnd)) {
                continue;
            }

            $url = $this->normalizeUrl($row['url'] ?? null);
            $venue = trim((string) ($row['venue'] ?? '')) ?: null;
            $source = $this->normalizeSource($row['source'] ?? null);
            $reason = trim((string) ($row['reason'] ?? 'Matches your interests.'));
            $dedupeKey = Str::lower(($url ?? '').'|'.$title.'|'.$startsAt->toDateString());

            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $externalId = $url !== null
                ? 'web:'.sha1($url)
                : 'web:'.sha1($cityKey.'|'.$title.'|'.$startsAt->toIso8601String());

            $event = $this->ingestion->upsert(new NormalizedEvent(
                source: $source,
                externalId: $externalId,
                title: $title,
                description: $reason,
                city: $cityKey,
                venueName: $venue,
                startsAt: $startsAt,
                endsAt: null,
                url: $url,
                imageUrl: null,
                priceMin: null,
                priceMax: null,
                payload: [
                    'discovered_via' => 'weekend_web_search',
                    'raw' => $row,
                ],
            ));

            $items[] = $this->itemFromEvent($event, $reason);

            if (count($items) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFromEvent(Event $event, string $reason): array
    {
        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => $event->url,
            'image_url' => $event->image_url,
            'source' => $event->source,
            'reason' => $reason !== '' ? $reason : 'Matches your interests.',
        ];
    }

    private function trySyncLocalCatalog(string $cityKey): void
    {
        try {
            $this->ingestion->syncCity($cityKey, $this->sources);
        } catch (Throwable $exception) {
            Log::info('weekend.local_sync_skipped', [
                'city' => $cityKey,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, Event>
     */
    private function upcomingEventsForCity(
        string $city,
        string $cityKey,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): Collection {
        return Event::query()
            ->where(function ($query) use ($city, $cityKey): void {
                $query->where('city', $city)
                    ->orWhere('city', $cityKey)
                    ->orWhere('city', 'like', $cityKey.',%')
                    ->orWhere('city', 'like', $cityKey.' %');
            })
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<=', $windowEnd)
            ->orderBy('starts_at')
            ->limit(40)
            ->get();
    }

    private function primaryCityName(string $city): string
    {
        $primary = trim(Str::before($city, ','));

        return $primary !== '' ? $primary : trim($city);
    }

    private function parseStartsAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private function normalizeSource(mixed $value): string
    {
        $source = Str::lower(trim((string) ($value ?? 'web')));
        $allowed = ['eventbrite', 'allevents', 'luma', 'ticketmaster', 'posh', 'partiful', 'groupon', 'web', 'other'];

        if ($source === '' || $source === 'other') {
            return 'web_ai';
        }

        if (in_array($source, $allowed, true)) {
            return $source === 'web' ? 'web_ai' : $source;
        }

        return 'web_ai';
    }
}
