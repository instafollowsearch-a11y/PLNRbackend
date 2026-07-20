<?php

namespace App\Services\Events\Adapters;

use App\Services\Events\EventSourceAdapter;
use App\Services\Events\NormalizedEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LumaEventAdapter implements EventSourceAdapter
{
    public function source(): string
    {
        return 'luma';
    }

    /**
     * @return list<NormalizedEvent>
     */
    public function fetch(string $city): array
    {
        $apiKey = (string) config('services.events.luma.api_key');

        if ($apiKey === '') {
            return [];
        }

        $response = Http::withHeaders([
            'x-luma-api-key' => $apiKey,
            'Accept' => 'application/json',
        ])->get((string) config('services.events.luma.url'), [
            'city' => $city,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Luma API request failed: '.$response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return [];
        }

        $entries = $payload['entries'] ?? $payload['events'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $events = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $item = is_array($entry['event'] ?? null) ? $entry['event'] : $entry;
            $externalId = (string) ($entry['api_id'] ?? $item['api_id'] ?? $item['id'] ?? '');

            if ($externalId === '') {
                continue;
            }

            $geo = is_array($item['geo_address_info'] ?? null) ? $item['geo_address_info'] : [];
            $startsAt = isset($item['start_at'])
                ? Carbon::parse((string) $item['start_at'])
                : now();

            $events[] = new NormalizedEvent(
                source: $this->source(),
                externalId: $externalId,
                title: (string) ($item['name'] ?? $item['title'] ?? 'Luma Event'),
                description: isset($item['description']) ? (string) $item['description'] : null,
                city: (string) ($geo['city'] ?? $city),
                venueName: isset($geo['address']) ? (string) $geo['address'] : null,
                startsAt: $startsAt,
                endsAt: isset($item['end_at']) ? Carbon::parse((string) $item['end_at']) : null,
                url: isset($item['url']) ? (string) $item['url'] : null,
                imageUrl: isset($item['cover_url']) ? (string) $item['cover_url'] : null,
                priceMin: null,
                priceMax: null,
                payload: $entry,
            );
        }

        return $events;
    }
}
