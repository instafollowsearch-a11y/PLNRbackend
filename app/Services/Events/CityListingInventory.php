<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Services\AI\AiChatClient;
use App\Support\Geo\LocationLabel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CityListingInventory
{
    public const MAX_CATALOG = 24;

    public const CACHE_SECONDS = 120;

    public const SCOUT_MAX_USES = 3;

    public function __construct(
        private readonly AiChatClient $client,
        private readonly EventIngestionService $ingestion,
        private readonly EventSourceResolver $sources,
        private readonly EventWebListingScout $scout,
        private readonly FindLocalStopLinks $links,
    ) {}

    /**
     * Sync listing APIs and always scout the web for the same city and date window.
     *
     * @param  list<string>  $interests
     * @return Collection<int, Event>
     */
    public function fill(
        string $city,
        Carbon $windowStart,
        Carbon $windowEnd,
        array $interests = [],
        bool $allowScout = true,
        string $discoveredVia = 'listing_web_search',
        bool $useCache = true,
    ): Collection {
        $cityKey = $this->cityKey($city);

        if ($cityKey === '') {
            return collect();
        }

        $cacheKey = $this->cacheKey($cityKey, $windowStart, $windowEnd);

        if ($useCache) {
            $cachedIds = Cache::get($cacheKey);
            if (is_array($cachedIds) && $cachedIds !== []) {
                $cached = Event::query()->whereIn('id', $cachedIds)->orderBy('starts_at')->get();
                if ($cached->isNotEmpty()) {
                    return $cached->values();
                }

                Cache::forget($cacheKey);
            }
        }

        $this->trySync($cityKey);
        $events = $this->queryEvents($city, $cityKey, $windowStart, $windowEnd);

        if ($allowScout) {
            $this->scoutWeb($cityKey, $windowStart, $windowEnd, $events, $interests, $discoveredVia);
            $events = $this->queryEvents($city, $cityKey, $windowStart, $windowEnd);
        }

        if ($useCache && $events->isNotEmpty()) {
            Cache::put($cacheKey, $events->pluck('id')->all(), self::CACHE_SECONDS);
        }

        return $events->values();
    }

    public function cityKey(string $city): string
    {
        $primary = LocationLabel::cityName($city);

        return $primary !== '' ? $primary : trim($city);
    }

    /**
     * @return Collection<int, Event>
     */
    public function queryEvents(string $city, string $cityKey, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        if ($cityKey === '') {
            return collect();
        }

        return Event::query()
            ->where(function ($query) use ($city, $cityKey): void {
                $query->where('city', $city)
                    ->orWhere('city', $cityKey)
                    ->orWhereRaw('LOWER(city) = ?', [mb_strtolower($cityKey)])
                    ->orWhere('city', 'like', $cityKey.',%')
                    ->orWhere('city', 'like', $cityKey.' %');
            })
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<=', $windowEnd)
            ->orderBy('starts_at')
            ->limit(40)
            ->get();
    }

    private function cacheKey(string $cityKey, Carbon $windowStart, Carbon $windowEnd): string
    {
        return 'city-listing-inventory:'.$cityKey.':'.$windowStart->toIso8601String().':'.$windowEnd->toIso8601String();
    }

    private function trySync(string $cityKey): void
    {
        try {
            $this->ingestion->syncCity($cityKey, $this->sources);
        } catch (Throwable $exception) {
            Log::info('listing.local_sync_skipped', [
                'city' => $cityKey,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  Collection<int, Event>  $known
     * @param  list<string>  $interests
     */
    private function scoutWeb(
        string $cityKey,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $known,
        array $interests,
        string $discoveredVia,
    ): void {
        $hint = $known->take(12)->map(fn (Event $event): array => [
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => $this->links->urlFor($event),
            'source' => $event->source,
        ])->values()->all();

        $filtered = array_values(array_filter(
            $interests,
            static fn (string $interest): bool => trim($interest) !== '',
        ));
        $interestLine = $filtered === []
            ? 'popular local nightlife and live events'
            : implode(', ', array_slice($filtered, 0, 4));

        try {
            $response = $this->client->chat(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You are PLNR event scout. You MUST use web search. Find real, currently listed local events in the user's city that fall inside the date window.

Search Eventbrite, Allevents, Luma, Find Local, Ticketmaster, and venue calendars. Use at most three searches: city + date + interest, then a listings site, then one fallback.

Rules:
- Only include events verified from search results (real titles, venues, dates).
- starts_at must fall inside the provided window (ISO-8601).
- Prefer official ticket or listing URLs.
- Never invent venues, never include past events, never pad with generic nightlife.
- Return compact JSON only.

{"recommendations":[{"title":"string","venue":"string|null","starts_at":"ISO-8601","url":"https://...","source":"eventbrite|allevents|luma|findlocal|ticketmaster|other"}]}
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'city' => $cityKey,
                            'interests' => $interestLine,
                            'window_start' => $windowStart->toIso8601String(),
                            'window_end' => $windowEnd->toIso8601String(),
                            'today' => now()->toIso8601String(),
                            'known_local_catalog' => $hint,
                            'instructions' => 'Search the open web for events on these dates in this city. Skip known_local_catalog unless it avoids duplicates.',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
                true,
                2048,
                [
                    'web_search' => true,
                    'web_search_max_uses' => self::SCOUT_MAX_USES,
                    'user_location' => [
                        'type' => 'approximate',
                        'city' => $cityKey,
                    ],
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('listing.event_scout_failed', [
                'city' => $cityKey,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $this->scout->persistFromResponse(
            $response,
            $cityKey,
            $windowStart,
            $windowEnd,
            $discoveredVia,
            self::MAX_CATALOG,
        );
    }
}
