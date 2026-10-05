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
        $pages = $this->pagesForCity($city);

        if ($pages === []) {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = $this->attachStops($content['stops'], $pages);
        }

        if (! isset($content['days']) || ! is_array($content['days'])) {
            return $content;
        }

        foreach ($content['days'] as $index => $day) {
            if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                continue;
            }

            $day['stops'] = $this->attachStops($day['stops'], $pages);
            $content['days'][$index] = $day;
        }

        return $content;
    }

    /**
     * @return array<string, string>
     */
    private function pagesForCity(?string $city): array
    {
        if (! is_string($city) || trim($city) === '') {
            return [];
        }

        $pages = [];

        $events = Event::query()
            ->where('source', 'findlocal')
            ->forCity($city)
            ->where('external_id', '!=', '')
            ->get();

        foreach ($events as $event) {
            $title = $this->normalize((string) $event->title);

            if ($title === null || strlen($title) < 8) {
                continue;
            }

            $pages[$title] = $this->pageUrl($event);
        }

        return $pages;
    }

    /**
     * @param  list<mixed>  $stops
     * @param  array<string, string>  $pages
     * @return list<mixed>
     */
    private function attachStops(array $stops, array $pages): array
    {
        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $url = $this->match((string) ($stop['name'] ?? ''), $pages);

            if ($url === null) {
                continue;
            }

            $stop['findlocal_url'] = $url;
            $stops[$index] = $stop;
        }

        return $stops;
    }

    /**
     * @param  array<string, string>  $pages
     */
    private function match(string $stopName, array $pages): ?string
    {
        $name = $this->normalize($stopName);

        if ($name === null) {
            return null;
        }

        if (isset($pages[$name])) {
            return $pages[$name];
        }

        foreach ($pages as $title => $url) {
            if (str_contains($name, $title)) {
                return $url;
            }
        }

        return null;
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
