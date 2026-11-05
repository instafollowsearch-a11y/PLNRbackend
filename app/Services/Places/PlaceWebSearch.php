<?php

namespace App\Services\Places;

class PlaceWebSearch
{
    public const MAX_USES = 2;

    /**
     * One batched lookup, not one search per venue (Anthropic: factual queries use 1–3 searches).
     * One pause_turn continue is enough for two searches; a second continue repeats a 180s wait.
     *
     * @return array<string, mixed>
     */
    public static function options(string $city): array
    {
        return [
            'web_search' => true,
            'web_search_max_uses' => self::MAX_USES,
            'web_search_allowed_callers' => ['direct'],
            'web_search_pause_limit' => 1,
            'user_location' => [
                'type' => 'approximate',
                'city' => $city,
                'timezone' => (string) config('app.timezone'),
            ],
        ];
    }
}
