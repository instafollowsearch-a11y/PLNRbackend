<?php

namespace App\Services\Events;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class EventWebListingScout
{
    public function __construct(
        private readonly EventIngestionService $ingestion,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     * @return Collection<int, Event>
     */
    public function persistFromResponse(
        array $response,
        string $cityKey,
        Carbon $windowStart,
        Carbon $windowEnd,
        string $discoveredVia,
        int $limit = 24,
    ): Collection {
        $raw = $response['recommendations'] ?? $response['events'] ?? null;

        if (! is_array($raw)) {
            return collect();
        }

        return $this->persistRows($raw, $cityKey, $windowStart, $windowEnd, $discoveredVia, $limit);
    }

    /**
     * @param  list<mixed>  $rows
     * @return Collection<int, Event>
     */
    public function persistRows(
        array $rows,
        string $cityKey,
        Carbon $windowStart,
        Carbon $windowEnd,
        string $discoveredVia,
        int $limit = 24,
    ): Collection {
        $events = collect();
        $seen = [];

        foreach ($rows as $row) {
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
            $reason = trim((string) ($row['reason'] ?? $row['description'] ?? ''));
            $dedupeKey = Str::lower(($url ?? '').'|'.$title.'|'.$startsAt->toDateString());

            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $externalId = $url !== null
                ? 'web:'.sha1($url)
                : 'web:'.sha1($cityKey.'|'.$title.'|'.$startsAt->toIso8601String());

            $events->push($this->ingestion->upsert(new NormalizedEvent(
                source: $source,
                externalId: $externalId,
                title: $title,
                description: $reason !== '' ? $reason : null,
                city: $cityKey,
                venueName: $venue,
                startsAt: $startsAt,
                endsAt: null,
                url: $url,
                imageUrl: null,
                priceMin: null,
                priceMax: null,
                payload: [
                    'discovered_via' => $discoveredVia,
                    'raw' => $row,
                ],
            )));

            if ($events->count() >= $limit) {
                break;
            }
        }

        return $events->values();
    }

    public function parseStartsAt(mixed $value): ?Carbon
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

    public function normalizeUrl(mixed $value): ?string
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

    public function normalizeSource(mixed $value): string
    {
        $source = Str::lower(trim((string) ($value ?? 'web')));
        $allowed = ['eventbrite', 'allevents', 'luma', 'ticketmaster', 'posh', 'partiful', 'groupon', 'findlocal', 'web', 'other'];

        if ($source === '' || $source === 'other') {
            return 'web_ai';
        }

        if (in_array($source, $allowed, true)) {
            return $source === 'web' ? 'web_ai' : $source;
        }

        return 'web_ai';
    }
}
