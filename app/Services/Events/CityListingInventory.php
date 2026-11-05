<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Services\AI\AiChatClient;
use App\Support\Geo\LocationLabel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CityListingInventory
{
    public const MAX_CATALOG = 24;

    public const CACHE_SECONDS = 600;

    public const SCOUT_MAX_USES = 2;

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
        $cached = $this->cachedEvents($cacheKey, $useCache);

        if ($cached !== null) {
            return $cached;
        }

        $load = function () use ($city, $cityKey, $windowStart, $windowEnd, $interests, $allowScout, $discoveredVia, $useCache, $cacheKey): Collection {
            $cached = $this->cachedEvents($cacheKey, $useCache);

            if ($cached !== null) {
                return $cached;
            }

            $syncStarted = microtime(true);
            $this->trySync($cityKey);
            Log::info('listing.sync_ms', [
                'city' => $cityKey,
                'ms' => (int) round((microtime(true) - $syncStarted) * 1000),
            ]);
            $events = $this->queryEvents($city, $cityKey, $windowStart, $windowEnd);

            if ($allowScout) {
                $scoutStarted = microtime(true);
                $this->scoutWeb($cityKey, $windowStart, $windowEnd, $events, $interests, $discoveredVia);
                Log::info('listing.scout_ms', [
                    'city' => $cityKey,
                    'ms' => (int) round((microtime(true) - $scoutStarted) * 1000),
                    'known' => $events->count(),
                ]);
                $events = $this->queryEvents($city, $cityKey, $windowStart, $windowEnd);
            }

            if ($useCache && $events->isNotEmpty()) {
                Cache::put($cacheKey, $events->pluck('id')->all(), self::CACHE_SECONDS);
            }

            return $events->values();
        };

        try {
            return Cache::lock('listing-fill:'.$cacheKey, 120)->block(90, $load);
        } catch (LockTimeoutException) {
            return $load();
        }
    }

    /**
     * @return Collection<int, Event>|null
     */
    private function cachedEvents(string $cacheKey, bool $useCache): ?Collection
    {
        if (! $useCache) {
            return null;
        }

        $cachedIds = Cache::get($cacheKey);
        if (! is_array($cachedIds) || $cachedIds === []) {
            return null;
        }

        $cached = Event::query()->whereIn('id', $cachedIds)->orderBy('starts_at')->get();
        if ($cached->isEmpty()) {
            Cache::forget($cacheKey);

            return null;
        }

        return $cached->values();
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
        $hint = $known->take(8)->map(fn (Event $event): array => [
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => $this->links->urlFor($event),
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

Fire up to two independent searches in the same turn (in parallel, not one after the other):
1) city + date window + interests + events/tickets
2) the same city and dates on Eventbrite, Allevents, Luma, Find Local, Ticketmaster, or a venue calendar

Stop as soon as you have enough dated listings. Do not run a third sequential search.

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
                    'web_search_allowed_callers' => ['direct'],
                    'user_location' => [
                        'type' => 'approximate',
                        'city' => $cityKey,
                        'timezone' => (string) config('app.timezone'),
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

        $persisted = $this->scout->persistFromResponse(
            $response,
            $cityKey,
            $windowStart,
            $windowEnd,
            $discoveredVia,
            self::MAX_CATALOG,
        );
        Log::info('listing.scout_persisted', [
            'city' => $cityKey,
            'persisted' => $persisted->count(),
        ]);
    }
}
