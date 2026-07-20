<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;

class EventContextService
{
    public function formatForPrompt(?string $city, int $limit = 12): string
    {
        if ($city === null || trim($city) === '') {
            return '';
        }

        $events = Event::query()
            ->forCity($city)
            ->upcoming()
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return '';
        }

        $lines = ['Local upcoming events:'];

        foreach ($events as $event) {
            $lines[] = '- '.$this->formatEventLine($event);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function citiesToSync(EventSourceResolver $resolver): array
    {
        $fixtureCities = $resolver->fixtureAdapter()->availableCities();

        $userCities = User::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city')
            ->map(fn (mixed $city) => (string) $city)
            ->all();

        /** @var Collection<int, string> $merged */
        $merged = collect($fixtureCities)
            ->merge($userCities)
            ->map(fn (string $city) => trim($city))
            ->filter()
            ->unique()
            ->values();

        return $merged->all();
    }

    private function formatEventLine(Event $event): string
    {
        $parts = [
            $event->title,
            $event->starts_at?->format('M j, g:i A'),
            $event->venue_name,
            'source='.$event->source,
        ];

        if ($event->price_min !== null || $event->price_max !== null) {
            $parts[] = 'price='.$this->formatPriceRange($event);
        }

        if ($event->url) {
            $parts[] = $event->url;
        }

        return implode(' | ', array_values(array_filter($parts)));
    }

    private function formatPriceRange(Event $event): string
    {
        if ($event->price_min !== null && $event->price_max !== null && $event->price_min != $event->price_max) {
            return '$'.$event->price_min.'-$'.$event->price_max;
        }

        $value = $event->price_min ?? $event->price_max;

        return $value === null ? '' : '$'.$value;
    }
}
