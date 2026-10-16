<?php

namespace App\Services\Places;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenStreetMapOpeningHours
{
    private const CACHE_PREFIX = 'place-osm-hours:';

    /**
     * @return string|null A weekday line such as "Friday: 5 PM–12 AM"
     */
    public function hoursFor(string $name, string $city): ?string
    {
        $name = trim($name);
        $city = trim($city);

        if ($name === '' || $city === '') {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX.sha1(strtolower($city.'|'.$name));
        $cached = Cache::get($cacheKey);

        if ($cached === false) {
            return null;
        }

        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        $raw = $this->openingHoursTag($name.', '.$city, $name);
        $line = $raw === null ? null : $this->lineForToday($raw);

        Cache::put($cacheKey, $line ?? false, now()->addDays(7));

        return $line;
    }

    /**
     * Hours from a different business of the same kind in the city.
     */
    public function similarHours(string $name, string $activity, string $city): ?string
    {
        $name = trim($name);
        $city = trim($city);
        $category = $this->category($name, $activity);

        if ($city === '' || $category === '') {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX.'similar:'.sha1(strtolower($city.'|'.$category));
        $cached = Cache::get($cacheKey);

        if ($cached === false) {
            return null;
        }

        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        $raw = $this->openingHoursTag($category.' in '.$city, null);
        $line = $raw === null ? null : $this->lineForToday($raw);

        Cache::put($cacheKey, $line ?? false, now()->addDays(7));

        return $line;
    }

    private function openingHoursTag(string $query, ?string $mustContainName): ?string
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => (string) config('services.nominatim.user_agent'),
                ])
                ->timeout(8)
                ->get(rtrim((string) config('services.nominatim.url'), '/').'/search', [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'extratags' => 1,
                    'limit' => 8,
                    'addressdetails' => 1,
                    'accept-language' => 'en',
                ]);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $results = $response->json();

        if (! is_array($results)) {
            return null;
        }

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $label = strtolower((string) ($result['name'] ?? $result['display_name'] ?? ''));

            if ($mustContainName !== null && $label !== '' && ! str_contains($label, strtolower($mustContainName))) {
                continue;
            }

            $hours = $result['extratags']['opening_hours'] ?? null;

            if (is_string($hours) && trim($hours) !== '') {
                return trim($hours);
            }
        }

        return null;
    }

    private function category(string $name, string $activity): string
    {
        $text = strtolower($name.' '.$activity);
        $kinds = [
            'cafe' => ['cafe', 'coffee'],
            'bar' => ['bar', 'pub', 'lounge', 'club', 'jazz', 'cocktail', 'nightlife'],
            'museum' => ['museum', 'gallery'],
            'park' => ['park', 'garden', 'trail', 'hike', 'walk'],
            'hotel' => ['hotel', 'inn'],
            'restaurant' => ['restaurant', 'dinner', 'lunch', 'food', 'bistro', 'grill', 'kitchen', 'taco', 'pizza'],
        ];

        foreach ($kinds as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return 'restaurant';
    }

    private function lineForToday(string $openingHours): ?string
    {
        $value = trim($openingHours);

        if ($value === '') {
            return null;
        }

        $day = now()->format('l');

        if (preg_match('/^24\s*\/\s*7$/i', $value) === 1) {
            return $day.': Open 24 hours';
        }

        $today = $this->weekdayToken($day);
        $rules = preg_split('/\s*;\s*/', $value) ?: [];

        foreach ($rules as $rule) {
            if (preg_match('/^([A-Za-z0-9,\-]+)\s+(\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}.*)$/', $rule, $matches) !== 1) {
                continue;
            }

            if (! $this->coversToday($matches[1], $today)) {
                continue;
            }

            $span = $this->formatSpan($matches[2]);

            if ($span !== null) {
                return $day.': '.$span;
            }
        }

        return null;
    }

    private function weekdayToken(string $day): string
    {
        return match ($day) {
            'Monday' => 'Mo',
            'Tuesday' => 'Tu',
            'Wednesday' => 'We',
            'Thursday' => 'Th',
            'Friday' => 'Fr',
            'Saturday' => 'Sa',
            default => 'Su',
        };
    }

    private function coversToday(string $days, string $today): bool
    {
        $order = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        $parts = preg_split('/\s*,\s*/', $days) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/^(Mo|Tu|We|Th|Fr|Sa|Su)-(Mo|Tu|We|Th|Fr|Sa|Su)$/', $part, $range) === 1) {
                $start = array_search($range[1], $order, true);
                $end = array_search($range[2], $order, true);
                $current = array_search($today, $order, true);

                if ($start === false || $end === false || $current === false) {
                    continue;
                }

                if ($start <= $end && $current >= $start && $current <= $end) {
                    return true;
                }

                if ($start > $end && ($current >= $start || $current <= $end)) {
                    return true;
                }

                continue;
            }

            if ($part === $today) {
                return true;
            }
        }

        return false;
    }

    private function formatSpan(string $span): ?string
    {
        $pieces = preg_split('/\s*,\s*/', trim($span)) ?: [];
        $formatted = [];

        foreach ($pieces as $piece) {
            if (preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $piece, $times) !== 1) {
                return null;
            }

            $formatted[] = $this->clock($times[1]).'–'.$this->clock($times[2]);
        }

        if ($formatted === []) {
            return null;
        }

        return implode(', ', $formatted);
    }

    private function clock(string $time): string
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        if ($hour === 24) {
            $hour = 0;
        }

        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;

        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $minute === 0
            ? $hour12.' '.$suffix
            : $hour12.':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.$suffix;
    }
}
