<?php

namespace App\Services\AI;

use RuntimeException;

class HotelSuggestionService
{
    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @return list<array{name: string, url: string}>
     */
    public function suggest(string $destination): array
    {
        $decoded = $this->client->chat([
            [
                'role' => 'system',
                'content' => 'Return JSON with key "hotels" containing exactly 3 objects. Each object has name (string) and url (https website of that hotel). Use real hotels near the destination. Do not invent a booking link on PLNR.',
            ],
            [
                'role' => 'user',
                'content' => 'Destination: '.$destination,
            ],
        ], true, 600);

        $hotels = [];

        foreach ($decoded['hotels'] ?? [] as $hotel) {
            if (! is_array($hotel)) {
                continue;
            }

            $name = trim((string) ($hotel['name'] ?? ''));
            $url = $this->httpsUrl($hotel['url'] ?? null);

            if ($name === '' || $url === null) {
                continue;
            }

            $hotels[] = [
                'name' => $name,
                'url' => $url,
            ];

            if (count($hotels) === 3) {
                break;
            }
        }

        if ($hotels === []) {
            throw new RuntimeException('No hotel suggestions were returned.');
        }

        return $hotels;
    }

    private function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
