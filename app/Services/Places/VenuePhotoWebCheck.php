<?php

namespace App\Services\Places;

use App\Services\AI\AiChatClient;
use App\Services\Settings\AppSettings;
use Throwable;

class VenuePhotoWebCheck
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly AppSettings $settings,
    ) {}

    /**
     * Direct image addresses Claude found on the public web. Empty when a place has no photo.
     *
     * @param  list<string>  $names
     * @return array<string, string> lowercase place name => https image URL
     */
    public function imageUrls(array $names, string $city): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn (string $name): string => trim($name), $names),
            fn (string $name): bool => $name !== '',
        )));

        if ($names === [] || $this->settings->anthropicApiKey() === null) {
            return [];
        }

        try {
            $response = $this->client->chat(
                [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You find one real photograph of a place. Use web search. Check the venue website, Wikipedia, Wikimedia Commons, Google, Yelp, and news or travel pages.

Rules:
- Use the place name exactly as given.
- image_url must be a direct https address of a JPEG, PNG, or WebP file you found on a page. Never invent or guess a URL.
- The photo must be of that exact place, its building, or its interior. Do not use a photo of a different business, a logo, a map, or a generic city picture.
- If you cannot find a real photo, omit that place.

Respond ONLY with JSON: {"places":[{"name":"string","image_url":"https://..."}]}
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'city' => $city,
                            'places' => $names,
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
                true,
                2048,
                [
                    'web_search' => true,
                    'web_search_max_uses' => min(6, max(2, count($names))),
                    'user_location' => [
                        'type' => 'approximate',
                        'city' => $city,
                    ],
                ],
            );
        } catch (Throwable) {
            return [];
        }

        return $this->urlsByName($response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, string>
     */
    private function urlsByName(array $response): array
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
            $url = trim((string) ($row['image_url'] ?? ''));

            if ($name === '' || preg_match('#\Ahttps://#', $url) !== 1) {
                continue;
            }

            $found[$name] = $url;
        }

        return $found;
    }
}
