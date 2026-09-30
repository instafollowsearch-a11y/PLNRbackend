<?php

namespace App\Services\Places;

use App\Services\Settings\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PlaceListingLookup
{
    public const PHOTO_CACHE_PREFIX = 'place-photo:';

    private const MATCH_CACHE_PREFIX = 'place-match:';

    private const MATCH_TTL_DAYS = 7;

    private const PHOTO_TTL_DAYS = 30;

    private const SEARCH_URL = 'https://places.googleapis.com/v1/places:searchText';

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function enrich(array $content, ?string $city): array
    {
        if ($this->settings->googlePlacesApiKey() === null) {
            return $content;
        }

        $cityName = trim((string) $city);

        if ($cityName === '') {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = $this->enrichStopList($content['stops'], $cityName);
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $index => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                $day['stops'] = $this->enrichStopList($day['stops'], $cityName);
                $content['days'][$index] = $day;
            }
        }

        return $content;
    }

    public function photoName(string $token): ?string
    {
        $name = Cache::get(self::PHOTO_CACHE_PREFIX.$token);

        return is_string($name) && $this->isPhotoName($name) ? $name : null;
    }

    /**
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    private function enrichStopList(array $stops, string $city): array
    {
        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $stops[$index] = $this->enrichStop($stop, $city);
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    private function enrichStop(array $stop, string $city): array
    {
        $name = trim((string) ($stop['name'] ?? ''));

        if ($name === '') {
            return $stop;
        }

        $match = $this->match($name, $city);

        if ($match === null) {
            return $stop;
        }

        $stop = $this->fillEmpty($stop, 'hours', $match['hours']);
        $stop = $this->fillEmpty($stop, 'photo_url', $this->photoUrl($match));
        $stop = $this->fillEmpty($stop, 'external_url', $match['website']);
        $stop = $this->fillEmpty($stop, 'maps_url', $match['maps']);

        return $stop;
    }

    /**
     * @return array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string}|null
     */
    private function match(string $name, string $city): ?array
    {
        $cacheKey = self::MATCH_CACHE_PREFIX.sha1(strtolower($city.'|'.$name));
        $cached = Cache::get($cacheKey);

        if ($cached === false) {
            return null;
        }

        if (is_array($cached)) {
            $this->rememberPhoto($cached);

            return $cached;
        }

        $key = $this->settings->googlePlacesApiKey();

        if ($key === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.displayName,places.photos,places.regularOpeningHours,places.websiteUri,places.googleMapsUri',
            ])->timeout(8)->post(self::SEARCH_URL, [
                'textQuery' => $name.', '.$city,
                'pageSize' => 1,
            ]);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $place = $response->json('places.0');

        if (! is_array($place)) {
            Cache::put($cacheKey, false, now()->addDays(self::MATCH_TTL_DAYS));

            return null;
        }

        $displayName = $place['displayName']['text'] ?? null;

        if (! is_string($displayName) || trim($displayName) === '') {
            Cache::put($cacheKey, false, now()->addDays(self::MATCH_TTL_DAYS));

            return null;
        }

        $photoName = $place['photos'][0]['name'] ?? null;
        $photoName = is_string($photoName) && $this->isPhotoName($photoName) ? $photoName : null;
        $match = [
            'photo_name' => $photoName,
            'photo_token' => $photoName !== null ? Str::random(32) : null,
            'hours' => $this->hoursLine($place),
            'website' => $this->httpsUrl($place['websiteUri'] ?? null),
            'maps' => $this->httpsUrl($place['googleMapsUri'] ?? null),
        ];

        $this->rememberPhoto($match);
        Cache::put($cacheKey, $match, now()->addDays(self::MATCH_TTL_DAYS));

        return $match;
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function rememberPhoto(array $match): void
    {
        $token = $match['photo_token'] ?? null;
        $name = $match['photo_name'] ?? null;

        if (! is_string($token) || ! is_string($name) || ! $this->isPhotoName($name)) {
            return;
        }

        Cache::put(self::PHOTO_CACHE_PREFIX.$token, $name, now()->addDays(self::PHOTO_TTL_DAYS));
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function photoUrl(array $match): ?string
    {
        $token = $match['photo_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        return url('/api/v1/place-photos/'.$token);
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function hoursLine(array $place): ?string
    {
        $lines = $place['regularOpeningHours']['weekdayDescriptions'] ?? null;

        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $today = now()->format('l').':';

        foreach ($lines as $line) {
            if (is_string($line) && str_starts_with($line, $today)) {
                return $line;
            }
        }

        $first = $lines[0] ?? null;

        return is_string($first) && trim($first) !== '' ? $first : null;
    }

    private function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, 'https://')) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    private function fillEmpty(array $stop, string $field, ?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return $stop;
        }

        $current = $stop[$field] ?? null;

        if (is_string($current) && trim($current) !== '') {
            return $stop;
        }

        $stop[$field] = $value;

        return $stop;
    }

    private function isPhotoName(string $name): bool
    {
        return preg_match('/^places\/[A-Za-z0-9_-]+\/photos\/[A-Za-z0-9_-]+$/', $name) === 1;
    }
}
