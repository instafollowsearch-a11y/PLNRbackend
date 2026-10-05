<?php

namespace App\Services\Events\Adapters;

use App\Services\Events\EventSourceAdapter;
use App\Services\Events\FindLocalMetros;
use App\Services\Events\NormalizedEvent;
use App\Services\Settings\AppSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FindLocalEventAdapter implements EventSourceAdapter
{
    private const CACHE_HOURS = 12;

    public function source(): string
    {
        return 'findlocal';
    }

    /**
     * @return list<NormalizedEvent>
     */
    public function fetch(string $city): array
    {
        $slug = FindLocalMetros::slugFor($city);
        $apiKey = app(AppSettings::class)->findLocalApiKey();

        if ($slug === null || $apiKey === null || $apiKey === '') {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            'findlocal.events.'.$slug,
            now()->addHours(self::CACHE_HOURS),
            fn (): array => $this->requestEvents($slug, $apiKey),
        );

        return $this->mapRows($city, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestEvents(string $slug, string $apiKey): array
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(8)
            ->get('https://findlocal.community/api/events', [
                'city' => $slug,
                'when' => 'week',
                'sort' => 'date',
                'limit' => 40,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Find Local API request failed: '.$response->status());
        }

        $payload = $response->json();
        $items = is_array($payload) ? ($payload['data'] ?? null) : null;

        if (! is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<NormalizedEvent>
     */
    private function mapRows(string $city, array $rows): array
    {
        $events = [];

        foreach ($rows as $item) {
            $event = $this->mapRow($city, $item);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapRow(string $city, array $item): ?NormalizedEvent
    {
        $externalId = trim((string) ($item['id'] ?? ''));
        $eventDate = trim((string) ($item['event_date'] ?? ''));
        $startTime = trim((string) ($item['start_time'] ?? ''));

        if ($externalId === '' || $eventDate === '' || $this->isDeleted($item) || $this->isMidnight($startTime)) {
            return null;
        }

        $url = $this->eventPageUrl($externalId, $item['detail_page_url'] ?? null);
        $endTime = trim((string) ($item['end_time'] ?? ''));
        $payload = $item;
        unset($payload['description']);

        return new NormalizedEvent(
            source: $this->source(),
            externalId: $externalId,
            title: trim((string) ($item['title'] ?? '')) !== '' ? trim((string) $item['title']) : 'Local event',
            description: $this->description($item['description'] ?? null),
            city: $city,
            venueName: $this->nullableString($item['venue_name'] ?? null),
            startsAt: Carbon::parse($eventDate.' '.$startTime),
            endsAt: $this->isMidnight($endTime) ? null : Carbon::parse($eventDate.' '.$endTime),
            url: $url,
            imageUrl: $this->nullableString($item['image_url'] ?? null),
            priceMin: is_numeric($item['price_amount'] ?? null) ? (float) $item['price_amount'] : null,
            priceMax: null,
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isDeleted(array $item): bool
    {
        return (int) ($item['is_deleted'] ?? 0) === 1;
    }

    private function isMidnight(string $time): bool
    {
        if ($time === '') {
            return true;
        }

        return in_array($time, ['00:00', '00:00:00', '0:00'], true);
    }

    private function eventPageUrl(string $externalId, mixed $detailUrl): string
    {
        $detail = $this->nullableString($detailUrl);

        if ($detail !== null && $this->isFindLocalEventPage($detail)) {
            return $detail;
        }

        return 'https://findlocal.community/event/'.$externalId;
    }

    private function isFindLocalEventPage(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === 'findlocal.community' && str_starts_with($path, '/event/');
    }

    private function description(mixed $value): ?string
    {
        $text = $this->nullableString($value);

        if ($text === null) {
            return null;
        }

        return mb_substr($text, 0, 1500);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
