<?php

namespace App\Services\Events\Adapters;

use App\Services\Events\EventSourceAdapter;
use App\Services\Events\NormalizedEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EventbriteEventAdapter implements EventSourceAdapter
{
    public function source(): string
    {
        return 'eventbrite';
    }

    /**
     * @return list<NormalizedEvent>
     */
    public function fetch(string $city): array
    {
        $token = (string) config('services.events.eventbrite.token');

        if ($token === '') {
            return [];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->get('https://www.eventbriteapi.com/v3/events/search/', [
                'location.address' => $city,
                'start_date.range_start' => now()->toIso8601String(),
                'start_date.range_end' => now()->addDays(30)->toIso8601String(),
                'expand' => 'venue',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Eventbrite API request failed: '.$response->status());
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['events']) || ! is_array($payload['events'])) {
            return [];
        }

        $events = [];

        foreach ($payload['events'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $venue = is_array($item['venue'] ?? null) ? $item['venue'] : [];
            $startsAt = isset($item['start']['utc'])
                ? Carbon::parse((string) $item['start']['utc'])
                : now();
            $endsAt = isset($item['end']['utc'])
                ? Carbon::parse((string) $item['end']['utc'])
                : null;

            $events[] = new NormalizedEvent(
                source: $this->source(),
                externalId: (string) ($item['id'] ?? ''),
                title: (string) ($item['name']['text'] ?? $item['name'] ?? 'Eventbrite Event'),
                description: isset($item['description']['text'])
                    ? (string) $item['description']['text']
                    : null,
                city: $city,
                venueName: isset($venue['name']) ? (string) $venue['name'] : null,
                startsAt: $startsAt,
                endsAt: $endsAt,
                url: isset($item['url']) ? (string) $item['url'] : null,
                imageUrl: isset($item['logo']['url']) ? (string) $item['logo']['url'] : null,
                priceMin: null,
                priceMax: null,
                payload: $item,
            );
        }

        return array_values(array_filter($events, fn (NormalizedEvent $event) => $event->externalId !== ''));
    }
}
