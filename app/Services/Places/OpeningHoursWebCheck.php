<?php

namespace App\Services\Places;

use App\Services\AI\AiChatClient;
use App\Services\Settings\AppSettings;
use Throwable;

class OpeningHoursWebCheck
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly AppSettings $settings,
    ) {}

    /**
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    public function fill(array $stops, string $city): array
    {
        if ($this->settings->anthropicApiKey() === null) {
            return $stops;
        }

        $names = [];

        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $name = trim((string) ($stop['name'] ?? ''));
            $hours = trim((string) ($stop['hours'] ?? ''));

            if ($name !== '' && $hours === '') {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));

        if ($names === []) {
            return $stops;
        }

        try {
            $response = $this->client->chat(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You look up real hours of operation. Use web search. Check the venue website, Google, Yelp, or the map listing.

Rules:
- Only return hours you found on a page. Never guess a clock time.
- hours must be one weekday line for today, like "Friday: 5 PM–12 AM", "Friday: Closed", or "Friday: Open 24 hours".
- Use the place name exactly as given.
- If the exact place has no published hours, search similar businesses in the same city and use one of their published hours.
- Return every place. Do not leave hours empty.

Respond ONLY with JSON: {"places":[{"name":"string","hours":"Weekday: hours"}]}
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'city' => $city,
                            'today' => now()->format('l, F j, Y'),
                            'places' => $names,
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
                true,
                2048,
                [
                    'web_search' => true,
                    'web_search_max_uses' => max(2, count($names)),
                    'user_location' => [
                        'type' => 'approximate',
                        'city' => $city,
                    ],
                ],
            );
        } catch (Throwable) {
            return $stops;
        }

        $found = $this->hoursByName($response);

        if ($found === []) {
            return $stops;
        }

        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $name = strtolower(trim((string) ($stop['name'] ?? '')));
            $hours = trim((string) ($stop['hours'] ?? ''));

            if ($name === '' || $hours !== '' || ! isset($found[$name])) {
                continue;
            }

            $stop['hours'] = $found[$name];
            $stops[$index] = $stop;
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, string>
     */
    private function hoursByName(array $response): array
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
            $hours = trim((string) ($row['hours'] ?? ''));

            if ($name === '' || preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday): .+$/', $hours) !== 1) {
                continue;
            }

            $found[$name] = $hours;
        }

        return $found;
    }
}
