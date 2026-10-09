<?php

namespace App\Services\Places;

use App\Services\Settings\AppSettings;
use App\Support\Geo\LocationLabel;
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
    public function enrich(array $content, ?string $city, ?AreaLimit $area = null, bool $keepCoordinates = false): array
    {
        if ($this->settings->googleApiKey() === null) {
            return $content;
        }

        $cityName = LocationLabel::locality($city);

        if ($cityName === '') {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = $this->enrichStopList($content['stops'], $cityName, $area, $keepCoordinates);
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $index => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                $day['stops'] = $this->enrichStopList($day['stops'], $cityName, $area, false);
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
    private function enrichStopList(array $stops, string $city, ?AreaLimit $area, bool $keepCoordinates): array
    {
        $kept = [];
        $removed = 0;

        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                $kept[] = $stop;

                continue;
            }

            $enriched = $this->enrichStop($stop, $city, $area, $keepCoordinates);

            if ($enriched === null) {
                $removed++;

                continue;
            }

            $kept[] = $enriched;
        }

        if ($removed > 0 && $this->arrayStopCount($kept) === 0) {
            return $stops;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>|null
     */
    private function enrichStop(array $stop, string $city, ?AreaLimit $area, bool $keepCoordinates): ?array
    {
        $name = trim((string) ($stop['name'] ?? ''));

        if ($name === '') {
            return $stop;
        }

        $match = $this->match($name, $city, $area);

        if ($match === null) {
            return $stop;
        }

        if ($match['closed'] === true) {
            return null;
        }

        if ($area !== null && $match['latitude'] !== null && $match['longitude'] !== null && ! $area->contains($match['latitude'], $match['longitude'])) {
            return null;
        }

        $stop = $this->fillEmpty($stop, 'hours', $match['hours']);
        $stop = $this->fillEmpty($stop, 'photo_url', $this->photoUrl($match));
        $stop = $this->fillEmpty($stop, 'external_url', $match['website']);
        $stop = $this->fillEmpty($stop, 'maps_url', $match['maps']);
        $stop = $this->fillEmpty($stop, 'address', $match['address']);

        if ($keepCoordinates && $match['latitude'] !== null && $match['longitude'] !== null) {
            $stop['latitude'] = $match['latitude'];
            $stop['longitude'] = $match['longitude'];
        }

        return $stop;
    }

    /**
     * @return array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string, closed: bool, address: ?string, latitude: ?float, longitude: ?float}|null
     */
    private function match(string $name, string $city, ?AreaLimit $area): ?array
    {
        $cacheKey = self::MATCH_CACHE_PREFIX.sha1($this->cacheSubject($name, $city, $area));
        $cached = Cache::get($cacheKey);

        if ($cached === false) {
            return null;
        }

        if (is_array($cached)) {
            $this->rememberPhoto($cached);

            return $this->normalizeCachedMatch($cached);
        }

        $key = $this->settings->googleApiKey();

        if ($key === null) {
            return null;
        }

        $body = [
            'textQuery' => $name.', '.$city,
            'pageSize' => 1,
        ];

        if ($area !== null) {
            $body['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $area->latitude,
                        'longitude' => $area->longitude,
                    ],
                    'radius' => $area->radiusMeters(),
                ],
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.displayName,places.photos,places.regularOpeningHours,places.websiteUri,places.googleMapsUri,places.businessStatus,places.formattedAddress,places.location',
            ])->timeout(8)->post(self::SEARCH_URL, $body);
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
        $location = is_array($place['location'] ?? null) ? $place['location'] : [];
        $match = [
            'photo_name' => $photoName,
            'photo_token' => $photoName !== null ? Str::random(32) : null,
            'hours' => $this->hoursLine($place),
            'website' => $this->httpsUrl($place['websiteUri'] ?? null),
            'maps' => $this->httpsUrl($place['googleMapsUri'] ?? null),
            'closed' => ($place['businessStatus'] ?? null) === 'CLOSED_PERMANENTLY',
            'address' => $this->streetAddress($place['formattedAddress'] ?? null),
            'latitude' => $this->coordinate($location['latitude'] ?? null),
            'longitude' => $this->coordinate($location['longitude'] ?? null),
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

    private function cacheSubject(string $name, string $city, ?AreaLimit $area): string
    {
        $subject = strtolower($city.'|'.$name);

        if ($area === null) {
            return $subject;
        }

        return $subject.'|'.round($area->latitude, 3).'|'.round($area->longitude, 3).'|'.$area->miles;
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string, closed: bool, address: ?string, latitude: ?float, longitude: ?float}
     */
    private function normalizeCachedMatch(array $cached): array
    {
        return [
            'photo_name' => is_string($cached['photo_name'] ?? null) ? $cached['photo_name'] : null,
            'photo_token' => is_string($cached['photo_token'] ?? null) ? $cached['photo_token'] : null,
            'hours' => is_string($cached['hours'] ?? null) ? $cached['hours'] : null,
            'website' => is_string($cached['website'] ?? null) ? $cached['website'] : null,
            'maps' => is_string($cached['maps'] ?? null) ? $cached['maps'] : null,
            'closed' => ($cached['closed'] ?? false) === true,
            'address' => is_string($cached['address'] ?? null) ? $cached['address'] : null,
            'latitude' => $this->coordinate($cached['latitude'] ?? null),
            'longitude' => $this->coordinate($cached['longitude'] ?? null),
        ];
    }

    private function streetAddress(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $address = trim($value);

        if ($address === '' || preg_match('/\d/', $address) !== 1) {
            return null;
        }

        return $address;
    }

    private function coordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<int, mixed>  $stops
     */
    private function arrayStopCount(array $stops): int
    {
        return count(array_filter($stops, 'is_array'));
    }
}
