<?php

namespace App\Services\Events\Adapters;

use App\Services\Events\EventSourceAdapter;
use App\Services\Events\NormalizedEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AlleventsEventAdapter implements EventSourceAdapter
{
    public function source(): string
    {
        return 'allevents';
    }

    /**
     * @return list<NormalizedEvent>
     */
    public function fetch(string $city): array
    {
        $apiKey = (string) config('services.events.allevents.api_key');

        if ($apiKey === '') {
            return [];
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $apiKey,
            ])
            ->get((string) config('services.events.allevents.url'), [
                'city' => $city,
                'keywords' => 'events',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Allevents API request failed: '.$response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return [];
        }

        $items = $payload['data'] ?? $payload['events'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $events = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $externalId = (string) ($item['event_id'] ?? $item['id'] ?? '');

            if ($externalId === '') {
                continue;
            }

            $price = isset($item['ticket_price']) ? (float) $item['ticket_price'] : null;

            $events[] = new NormalizedEvent(
                source: $this->source(),
                externalId: $externalId,
                title: (string) ($item['eventname'] ?? $item['title'] ?? 'Allevents Event'),
                description: isset($item['description']) ? (string) $item['description'] : null,
                city: $city,
                venueName: isset($item['location']) ? (string) $item['location'] : null,
                startsAt: Carbon::parse((string) ($item['start_time'] ?? now()->toDateTimeString())),
                endsAt: isset($item['end_time']) ? Carbon::parse((string) $item['end_time']) : null,
                url: isset($item['event_url']) ? (string) $item['event_url'] : null,
                imageUrl: isset($item['banner_url']) ? (string) $item['banner_url'] : null,
                priceMin: $price,
                priceMax: $price,
                payload: $item,
            );
        }

        return $events;
    }
}
