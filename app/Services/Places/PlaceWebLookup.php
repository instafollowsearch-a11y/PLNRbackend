<?php

namespace App\Services\Places;

use App\Services\AI\AiChatClient;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PlaceWebLookup
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly AppSettings $settings,
    ) {}

    /**
     * Hours and a photo in one Messages request. Anthropic: factual lookups use 1–3 searches;
     * one batched query plus max_uses 2 avoids a search per venue.
     *
     * @param  list<string>  $names
     * @return array<string, array{hours?: string, image_url?: string}>
     */
    public function lookup(array $names, string $city): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn (string $name): string => trim($name), $names),
            fn (string $name): bool => $name !== '',
        )));

        if ($names === [] || $this->settings->anthropicApiKey() === null) {
            return [];
        }

        $found = [];
        $uncached = [];

        foreach ($names as $name) {
            $row = $this->cachedRow($city, $name);
            if ($row !== null) {
                if ($row !== []) {
                    $found[strtolower($name)] = $row;
                }
                continue;
            }
            $uncached[] = $name;
        }

        if ($uncached === []) {
            return $found;
        }

        try {
            $response = $this->client->chat(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You look up real hours and one real photograph for each place. Use web search. Check the venue website, Google, Yelp, Wikipedia, Wikimedia Commons, and news or travel pages.

<use_parallel_tool_calls>
For maximum efficiency, whenever you perform multiple independent operations, invoke all relevant tools simultaneously rather than sequentially. Prefer one query that lists every place name plus the city and today's weekday. If you need a second search (for example hours on one query and photos on another), fire both in the same turn, not one after the other.
</use_parallel_tool_calls>

Rules:
- Use the place name exactly as given.
- Only return hours you found on a page. Never guess a clock time.
- hours must be one weekday line for today, like "Friday: 5 PM–12 AM", "Friday: Closed", or "Friday: Open 24 hours".
- If a page lists office or ticket-window hours and separate venue, restaurant, or nightclub hours, use the guest-visit hours, not the office line.
- image_url must be a direct https address of a JPEG, PNG, or WebP file you found on a page. Never invent or guess a URL.
- The photo must be of that exact place, its building, or its interior. Do not use a photo of a different business, a logo, a map, or a generic city picture.
- If a field is missing on the web, omit that field. If the place has nothing, omit the place. Do not copy hours or photos from a different business.

Respond ONLY with JSON: {"places":[{"name":"string","hours":"Weekday: hours","image_url":"https://..."}]}
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'city' => $city,
                            'today' => now()->format('l, F j, Y'),
                            'places' => $uncached,
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
                true,
                2048,
                PlaceWebSearch::options($city),
            );
        } catch (Throwable) {
            return $found;
        }

        $rows = $this->rowsByName($response);

        foreach ($uncached as $name) {
            $key = strtolower($name);
            $row = $rows[$key] ?? [];
            $this->storeRow($city, $name, $row);
            if ($row !== []) {
                $found[$key] = $row;
            }
        }

        return $found;
    }

    /**
     * @return array{hours?: string, image_url?: string}|null null when not cached
     */
    private function cachedRow(string $city, string $name): ?array
    {
        $hoursKey = $this->hoursKey($city, $name);
        $photoKey = $this->photoKey($city, $name);

        if (! Cache::has($hoursKey) || ! Cache::has($photoKey)) {
            return null;
        }

        $hours = Cache::get($hoursKey);
        $photo = Cache::get($photoKey);

        $row = [];

        if (is_string($hours) && $hours !== '') {
            $row['hours'] = $hours;
        }

        if (is_string($photo) && $photo !== '') {
            $row['image_url'] = $photo;
        }

        return $row;
    }

    /**
     * @param  array{hours?: string, image_url?: string}  $row
     */
    private function storeRow(string $city, string $name, array $row): void
    {
        $hours = $row['hours'] ?? null;
        $photo = $row['image_url'] ?? null;
        $hoursTtl = is_string($hours) && $hours !== '' ? now()->addDays(7) : now()->addHours(6);
        $photoTtl = is_string($photo) && $photo !== '' ? now()->addDays(7) : now()->addHours(6);

        Cache::put($this->hoursKey($city, $name), is_string($hours) && $hours !== '' ? $hours : false, $hoursTtl);
        Cache::put($this->photoKey($city, $name), is_string($photo) && $photo !== '' ? $photo : false, $photoTtl);
    }

    private function hoursKey(string $city, string $name): string
    {
        return 'place-hours:'.sha1(strtolower(trim($city)).'|'.strtolower(trim($name)).'|'.now()->toDateString());
    }

    private function photoKey(string $city, string $name): string
    {
        return 'place-photo-url:'.sha1(strtolower(trim($city)).'|'.strtolower(trim($name)));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{hours?: string, image_url?: string}>
     */
    private function rowsByName(array $response): array
    {
        $rows = $response['places'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $found = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower(trim((string) ($row['name'] ?? '')));

            if ($name === '') {
                continue;
            }

            $parsed = [];
            $hours = trim((string) ($row['hours'] ?? ''));
            $url = trim((string) ($row['image_url'] ?? ''));

            if (preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday): .+$/', $hours) === 1) {
                $parsed['hours'] = $hours;
            }

            if (preg_match('#\Ahttps://#', $url) === 1) {
                $parsed['image_url'] = $url;
            }

            if ($parsed !== []) {
                $found[$name] = $parsed;
            }
        }

        return $found;
    }
}
