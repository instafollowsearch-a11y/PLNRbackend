<?php

namespace App\Services\Events;

use App\Models\Event;

class FindLocalStopLinks
{
    public function urlFor(Event $event): ?string
    {
        if ($event->source !== 'findlocal') {
            return is_string($event->url) && $event->url !== '' ? $event->url : null;
        }

        $externalId = trim((string) $event->external_id);

        if ($externalId === '') {
            return is_string($event->url) && $event->url !== '' ? $event->url : null;
        }

        return $this->pageUrl($event);
    }

    /**
     * Put each matched Find Local event page on the stop that names it.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function attach(array $content, ?string $city): array
    {
        $events = $this->eventsForCity($city);

        if ($events === []) {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = $this->attachStops($content['stops'], $events);
        }

        if (! isset($content['days']) || ! is_array($content['days'])) {
            return $content;
        }

        foreach ($content['days'] as $index => $day) {
            if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                continue;
            }

            $day['stops'] = $this->attachStops($day['stops'], $events);
            $content['days'][$index] = $day;
        }

        return $content;
    }

    /**
     * @return list<array{title: ?string, venue: ?string, url: string}>
     */
    private function eventsForCity(?string $city): array
    {
        if (! is_string($city) || trim($city) === '') {
            return [];
        }

        $events = [];

        $rows = Event::query()
            ->where('source', 'findlocal')
            ->forCity($city)
            ->where('external_id', '!=', '')
            ->get();

        foreach ($rows as $event) {
            $title = $this->phrase((string) $event->title);
            $venue = $this->phrase((string) $event->venue_name);

            if ($title === null && $venue === null) {
                continue;
            }

            $events[] = [
                'title' => $title,
                'venue' => $venue,
                'url' => $this->pageUrl($event),
            ];
        }

        return $events;
    }

    /**
     * @param  list<mixed>  $stops
     * @param  list<array{title: ?string, venue: ?string, url: string}>  $events
     * @return list<mixed>
     */
    private function attachStops(array $stops, array $events): array
    {
        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $url = $this->matchStop($stop, $events);

            if ($url === null) {
                continue;
            }

            $stop['findlocal_url'] = $url;
            $stops[$index] = $stop;
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @param  list<array{title: ?string, venue: ?string, url: string}>  $events
     */
    private function matchStop(array $stop, array $events): ?string
    {
        $name = $this->normalize((string) ($stop['name'] ?? ''));
        $text = $this->normalize(implode(' ', [
            (string) ($stop['name'] ?? ''),
            (string) ($stop['activity'] ?? ''),
            (string) ($stop['notes'] ?? ''),
        ]));

        if ($text === null) {
            return null;
        }

        foreach ($events as $event) {
            $title = $event['title'];

            if ($title !== null && str_contains($text, $title)) {
                return $event['url'];
            }

            if ($name !== null && $title !== null && strlen($name) >= 8 && str_contains($title, $name)) {
                return $event['url'];
            }
        }

        $venueUrls = [];

        if ($name !== null) {
            foreach ($events as $event) {
                $venue = $event['venue'];

                if ($venue === null) {
                    continue;
                }

                if (str_contains($name, $venue) || str_contains($venue, $name)) {
                    $venueUrls[$event['url']] = true;
                }
            }
        }

        if (count($venueUrls) === 1) {
            return array_key_first($venueUrls);
        }

        return null;
    }

    private function phrase(string $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null || strlen($normalized) < 8) {
            return null;
        }

        return $normalized;
    }

    private function pageUrl(Event $event): string
    {
        $stored = is_string($event->url) ? $event->url : '';
        $host = strtolower((string) parse_url($stored, PHP_URL_HOST));
        $path = (string) parse_url($stored, PHP_URL_PATH);

        if ($host === 'findlocal.community' && str_starts_with($path, '/event/')) {
            return $stored;
        }

        return 'https://findlocal.community/event/'.$event->external_id;
    }

    private function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
