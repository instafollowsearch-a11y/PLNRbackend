<?php

namespace App\Services\Events\Adapters;

use App\Services\Events\EventSourceAdapter;
use App\Services\Events\NormalizedEvent;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class FixtureEventAdapter implements EventSourceAdapter
{
    public function source(): string
    {
        return 'fixture';
    }

    /**
     * @return list<NormalizedEvent>
     */
    public function fetch(string $city): array
    {
        $path = $this->fixturePathForCity($city);

        if (! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['events']) || ! is_array($decoded['events'])) {
            throw new RuntimeException("Invalid fixture format at {$path}");
        }

        $resolvedCity = (string) ($decoded['city'] ?? $city);
        $events = [];

        foreach ($decoded['events'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $events[] = new NormalizedEvent(
                source: $this->source(),
                externalId: (string) ($item['id'] ?? Str::uuid()->toString()),
                title: (string) ($item['title'] ?? 'Untitled Event'),
                description: isset($item['description']) ? (string) $item['description'] : null,
                city: $resolvedCity,
                venueName: isset($item['venue_name']) ? (string) $item['venue_name'] : null,
                startsAt: Carbon::parse((string) ($item['starts_at'] ?? now()->toIso8601String())),
                endsAt: isset($item['ends_at']) ? Carbon::parse((string) $item['ends_at']) : null,
                url: isset($item['url']) ? (string) $item['url'] : null,
                imageUrl: isset($item['image_url']) ? (string) $item['image_url'] : null,
                priceMin: isset($item['price_min']) ? (float) $item['price_min'] : null,
                priceMax: isset($item['price_max']) ? (float) $item['price_max'] : null,
                payload: $item,
            );
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    public function availableCities(): array
    {
        $directory = (string) config('services.events.fixtures_path');
        $cities = [];

        if (! is_dir($directory)) {
            return $cities;
        }

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded) && isset($decoded['city'])) {
                $cities[] = (string) $decoded['city'];

                continue;
            }

            $slug = basename($file, '.json');
            $cities[] = Str::title(str_replace('-', ' ', $slug));
        }

        return array_values(array_unique($cities));
    }

    private function fixturePathForCity(string $city): string
    {
        $directory = (string) config('services.events.fixtures_path');
        $slug = Str::slug($city);

        return $directory.'/'.$slug.'.json';
    }
}
