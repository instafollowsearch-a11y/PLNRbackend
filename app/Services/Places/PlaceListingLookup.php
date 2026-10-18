<?php

namespace App\Services\Places;

use App\Models\Itinerary;
use App\Models\PlacePhoto;
use App\Models\Suggestion;
use App\Services\Settings\AppSettings;
use App\Support\Geo\LocationLabel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PlaceListingLookup
{
    public const PHOTO_CACHE_PREFIX = 'place-photo:';

    private const MATCH_CACHE_PREFIX = 'place-match:';

    private const MATCH_TTL_DAYS = 7;

    private const PHOTO_TTL_DAYS = 30;

    private const SEARCH_URL = 'https://places.googleapis.com/v1/places:searchText';

    public function __construct(
        private readonly AppSettings $settings,
        private readonly OpenStreetMapOpeningHours $openStreetMapHours,
        private readonly OpeningHoursWebCheck $openingHoursWebCheck,
        private readonly VenuePhotoWebCheck $venuePhotoWebCheck,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function enrich(array $content, ?string $city, ?AreaLimit $area = null, bool $keepCoordinates = false, ?LookupPin $pin = null): array
    {
        $cityName = LocationLabel::locality($city);

        if ($cityName === '') {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = $this->enrichStopList($content['stops'], $cityName, $area, $keepCoordinates, $pin);
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $index => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                $day['stops'] = $this->enrichStopList($day['stops'], $cityName, $area, $keepCoordinates, $pin);
                $content['days'][$index] = $day;
            }
        }

        return $this->fillVenuePhotos($content, $cityName);
    }

    /**
     * Download listing photos while the plan is still being written, so the first view is a local file.
     *
     * @param  array<string, mixed>  $content
     */
    public function warmPhotos(array $content): void
    {
        $key = $this->settings->googleApiKey();

        if ($key === null) {
            return;
        }

        $jobs = [];

        foreach ($this->photoTokens($content) as $token) {
            if ($this->storedImage($token) !== null) {
                continue;
            }

            $name = $this->photoName($token);

            if ($name === null) {
                continue;
            }

            $jobs[$token] = $name;
        }

        if ($jobs === []) {
            return;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($jobs, $key): void {
                foreach ($jobs as $token => $name) {
                    $pool->as($token)
                        ->withHeaders([
                            'X-Goog-Api-Key' => $key,
                            'Accept' => 'image/*',
                        ])
                        ->timeout(8)
                        ->get('https://places.googleapis.com/v1/'.$name.'/media', [
                            'maxWidthPx' => 800,
                        ]);
                }
            });
        } catch (Throwable) {
            return;
        }

        foreach ($jobs as $token => $name) {
            $response = $responses[$token] ?? null;

            if ($response instanceof Response) {
                $this->storeImageResponse($token, $response);
            }
        }
    }

    /**
     * @return array{body: string, type: string}|null
     */
    public function storedImage(string $token): ?array
    {
        if (preg_match('/\A[A-Za-z0-9]{8,64}\z/', $token) !== 1) {
            return null;
        }

        $path = 'place-photos/'.$token;

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $body = Storage::disk('local')->get($path);
        $type = Storage::disk('local')->get($path.'.type');

        if (! is_string($body) || $body === '' || ! is_string($type) || ! str_starts_with($type, 'image/')) {
            return null;
        }

        return [
            'body' => $body,
            'type' => $type,
        ];
    }

    public function storeImage(string $token, string $body, string $type): void
    {
        if (preg_match('/\A[A-Za-z0-9]{8,64}\z/', $token) !== 1 || $body === '' || ! str_starts_with($type, 'image/')) {
            return;
        }

        Storage::disk('local')->put('place-photos/'.$token, $body);
        Storage::disk('local')->put('place-photos/'.$token.'.type', $type);
    }

    /**
     * Google Places photos are already attached. Claude web search covers the stops that still have none.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function fillVenuePhotos(array $content, string $city): array
    {
        $names = [];
        $this->walkStops($content, function (array $stop) use (&$names): void {
            $name = trim((string) ($stop['name'] ?? ''));

            if ($name !== '' && trim((string) ($stop['photo_url'] ?? '')) === '') {
                $names[] = $name;
            }
        });
        $names = array_slice(array_values(array_unique($names)), 0, 8);

        if ($names === []) {
            return $content;
        }

        $saved = $this->storeFoundImages($this->venuePhotoWebCheck->imageUrls($names, $city));

        if ($saved === []) {
            return $content;
        }

        return $this->walkStops($content, function (array $stop) use ($saved): array {
            $name = strtolower(trim((string) ($stop['name'] ?? '')));

            if ($name === '' || trim((string) ($stop['photo_url'] ?? '')) !== '' || ! isset($saved[$name])) {
                return $stop;
            }

            $stop['photo_url'] = $saved[$name];

            return $stop;
        });
    }

    /**
     * @param  array<string, string>  $urls
     * @return array<string, string> lowercase place name => local photo URL
     */
    private function storeFoundImages(array $urls): array
    {
        $pending = [];

        foreach ($urls as $name => $url) {
            if (! is_string($name) || ! is_string($url) || ! $this->isPublicImageUrl($url)) {
                continue;
            }

            $pending[] = ['name' => $name, 'url' => $url];
        }

        if ($pending === []) {
            return [];
        }

        $responses = $this->fetchImages($pending);
        $saved = [];

        foreach ($pending as $index => $item) {
            $response = $responses[(string) $index] ?? $responses[$index] ?? null;

            if (! $response instanceof Response) {
                continue;
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $next = $response->header('Location');
                $next = is_array($next) ? ($next[0] ?? '') : $next;
                $next = $this->absoluteUrl($item['url'], trim((string) $next));
                $followed = $next !== null && $this->isPublicImageUrl($next)
                    ? $this->fetchImages([['name' => $item['name'], 'url' => $next]])
                    : [];
                $response = $followed['0'] ?? $followed[0] ?? null;
            }

            if (! $response instanceof Response) {
                continue;
            }

            $image = $this->imageFromResponse($response);

            if ($image === null) {
                continue;
            }

            $token = Str::random(32);
            $this->storeImage($token, $image['body'], $image['type']);
            $saved[$item['name']] = url('/api/v1/place-photos/'.$token);
        }

        return $saved;
    }

    /**
     * @param  list<array{name: string, url: string}>  $urls
     * @return array<int|string, Response|ConnectionException>
     */
    private function fetchImages(array $urls): array
    {
        try {
            return Http::pool(function (Pool $pool) use ($urls): void {
                foreach ($urls as $index => $item) {
                    $pool->as((string) $index)
                        ->withHeaders([
                            'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                            'User-Agent' => 'PLNR/1.0',
                        ])
                        ->withOptions(['allow_redirects' => false])
                        ->timeout(6)
                        ->get($item['url']);
                }
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{body: string, type: string}|null
     */
    private function imageFromResponse(Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        $type = $this->imageType($body);

        if ($type === null || strlen($body) < 200 || strlen($body) > 4_000_000) {
            return null;
        }

        return [
            'body' => $body,
            'type' => $type,
        ];
    }

    private function imageType(string $body): ?string
    {
        if (str_starts_with($body, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) {
            return 'image/gif';
        }

        if (strlen($body) >= 12 && str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    private function isPublicImageUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        $records = @gethostbynamel($host);

        if (! is_array($records) || $records === []) {
            return false;
        }

        foreach ($records as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function absoluteUrl(string $current, string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        if (str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        if (str_starts_with($location, '/')) {
            return 'https://'.$parts['host'].$location;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  callable(array<string, mixed>): (array<string, mixed>|void)  $callback
     * @return array<string, mixed>
     */
    private function walkStops(array $content, callable $callback): array
    {
        if (isset($content['stops']) && is_array($content['stops'])) {
            foreach ($content['stops'] as $index => $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $next = $callback($stop);

                if (is_array($next)) {
                    $content['stops'][$index] = $next;
                }
            }
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $dayIndex => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                foreach ($day['stops'] as $index => $stop) {
                    if (! is_array($stop)) {
                        continue;
                    }

                    $next = $callback($stop);

                    if (is_array($next)) {
                        $content['days'][$dayIndex]['stops'][$index] = $next;
                    }
                }
            }
        }

        return $content;
    }

    public function photoName(string $token): ?string
    {
        $embedded = $this->embeddedPhotoName($token);

        if ($embedded !== null) {
            return $embedded;
        }

        $stored = PlacePhoto::query()->where('token', $token)->value('photo_name');

        if (is_string($stored) && $this->isPhotoName($stored)) {
            return $stored;
        }

        $cached = Cache::get(self::PHOTO_CACHE_PREFIX.$token);

        if (is_string($cached) && $this->isPhotoName($cached)) {
            $this->storePhoto($token, $cached);

            return $cached;
        }

        return $this->recoverPhotoName($token);
    }

    public function replacePhotoName(string $token): ?string
    {
        return $this->recoverPhotoName($token);
    }

    /**
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    private function enrichStopList(array $stops, string $city, ?AreaLimit $area, bool $keepCoordinates, ?LookupPin $pin): array
    {
        $matches = $this->matchesFor($stops, $city, $area);
        $kept = [];
        $removed = 0;

        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                $kept[] = $stop;

                continue;
            }

            $match = $matches[$index] ?? null;

            if (is_array($match)) {
                $pin?->note($match['latitude'], $match['longitude']);
            }

            $enriched = $this->applyMatch($stop, $match, $area, $keepCoordinates);

            if ($enriched === null) {
                $removed++;

                continue;
            }

            $kept[] = $enriched;
        }

        if ($removed > 0 && $this->arrayStopCount($kept) === 0) {
            return $stops;
        }

        return $this->fillMissingHours($kept, $city);
    }

    /**
     * Google Places hours win. OpenStreetMap, then a similar place nearby, then a web search.
     *
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    private function fillMissingHours(array $stops, string $city): array
    {
        foreach ($stops as $index => $stop) {
            if (! is_array($stop) || trim((string) ($stop['hours'] ?? '')) !== '') {
                continue;
            }

            $name = trim((string) ($stop['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $activity = trim((string) ($stop['activity'] ?? ''));
            $hours = $this->openStreetMapHours->hoursFor($name, $city)
                ?? $this->openStreetMapHours->similarHours($name, $activity, $city);

            if ($hours === null) {
                continue;
            }

            $stop['hours'] = $hours;
            $stops[$index] = $stop;
        }

        $stops = $this->openingHoursWebCheck->fill($stops, $city);

        return $this->replicateHoursFromSimilarStops($stops);
    }

    /**
     * When every outside lookup missed, copy hours from another stop in this plan.
     *
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    private function replicateHoursFromSimilarStops(array $stops): array
    {
        $donor = null;

        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $hours = trim((string) ($stop['hours'] ?? ''));

            if ($hours !== '') {
                $donor = $hours;

                break;
            }
        }

        if ($donor === null) {
            return $stops;
        }

        foreach ($stops as $index => $stop) {
            if (! is_array($stop) || trim((string) ($stop['name'] ?? '')) === '') {
                continue;
            }

            if (trim((string) ($stop['hours'] ?? '')) !== '') {
                continue;
            }

            $stop['hours'] = $donor;
            $stops[$index] = $stop;
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $stop
     * @param  array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string, closed: bool, address: ?string, latitude: ?float, longitude: ?float}|null  $match
     * @return array<string, mixed>|null
     */
    private function applyMatch(array $stop, ?array $match, ?AreaLimit $area, bool $keepCoordinates): ?array
    {
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
     * @param  array<int, mixed>  $stops
     * @return array<int, array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string, closed: bool, address: ?string, latitude: ?float, longitude: ?float}|null>
     */
    private function matchesFor(array $stops, string $city, ?AreaLimit $area): array
    {
        $matches = [];
        $pending = [];

        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $name = trim((string) ($stop['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $recalled = $this->recall($name, $city, $area);

            if ($recalled['fetch'] === true) {
                $pending[$index] = $recalled;

                continue;
            }

            $matches[$index] = $recalled['match'];
        }

        if ($pending === [] || $this->settings->googleApiKey() === null) {
            return $matches;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($pending): void {
                $key = $this->settings->googleApiKey();

                foreach ($pending as $index => $item) {
                    $pool->as((string) $index)
                        ->withHeaders([
                            'X-Goog-Api-Key' => $key,
                            'X-Goog-FieldMask' => 'places.displayName,places.photos,places.regularOpeningHours,places.websiteUri,places.googleMapsUri,places.businessStatus,places.formattedAddress,places.location',
                        ])
                        ->timeout(8)
                        ->post(self::SEARCH_URL, $item['body']);
                }
            });
        } catch (Throwable) {
            return $matches;
        }

        foreach ($pending as $index => $item) {
            $response = $responses[(string) $index] ?? null;
            $matches[$index] = $response instanceof Response
                ? $this->matchFromResponse($response, $item['cacheKey'])
                : null;
        }

        return $matches;
    }

    /**
     * @return array{fetch: bool, match: array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string, closed: bool, address: ?string, latitude: ?float, longitude: ?float}|null, cacheKey?: string, body?: array<string, mixed>}
     */
    private function recall(string $name, string $city, ?AreaLimit $area): array
    {
        $cacheKey = self::MATCH_CACHE_PREFIX.sha1($this->cacheSubject($name, $city, $area));
        $cached = Cache::get($cacheKey);

        if ($cached === false) {
            return ['fetch' => false, 'match' => null];
        }

        if (is_array($cached)) {
            $this->rememberPhoto($cached);

            return ['fetch' => false, 'match' => $this->normalizeCachedMatch($cached)];
        }

        if ($this->settings->googleApiKey() === null) {
            return ['fetch' => false, 'match' => null];
        }

        return [
            'fetch' => true,
            'match' => null,
            'cacheKey' => $cacheKey,
            'body' => $this->searchBody($name, $city, $area),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchBody(string $name, string $city, ?AreaLimit $area): array
    {
        $cleaned = trim((string) preg_replace('/\s+[–—-]\s+.+$/u', '', $name));
        $body = [
            'textQuery' => ($cleaned !== '' ? $cleaned : $name).', '.$city,
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

        return $body;
    }

    /**
     * @return array{photo_name: ?string, photo_token: ?string, hours: ?string, website: ?string, maps: ?string, closed: bool, address: ?string, latitude: ?float, longitude: ?float}|null
     */
    private function matchFromResponse(Response $response, string $cacheKey): ?array
    {
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

        $this->storePhoto($token, $name);
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function photoUrl(array $match): ?string
    {
        $name = $match['photo_name'] ?? null;

        if (! is_string($name) || ! $this->isPhotoName($name)) {
            return null;
        }

        $token = $match['photo_token'] ?? null;

        if (! is_string($token) || preg_match('/\A[A-Za-z0-9]+\z/', $token) !== 1) {
            $token = bin2hex($name);
        }

        return url('/api/v1/place-photos/'.$token);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    private function photoTokens(array $content): array
    {
        $tokens = [];

        $collect = function (mixed $stops) use (&$tokens): void {
            if (! is_array($stops)) {
                return;
            }

            foreach ($stops as $stop) {
                if (! is_array($stop) || ! is_string($stop['photo_url'] ?? null)) {
                    continue;
                }

                if (preg_match('#/place-photos/([A-Za-z0-9]+)\z#', $stop['photo_url'], $match) === 1) {
                    $tokens[] = $match[1];
                }
            }
        };

        $collect($content['stops'] ?? null);

        foreach ($content['days'] ?? [] as $day) {
            if (is_array($day)) {
                $collect($day['stops'] ?? null);
            }
        }

        return array_values(array_unique($tokens));
    }

    private function storeImageResponse(string $token, Response $response): void
    {
        $header = $response->header('Content-Type');
        $header = is_array($header) ? ($header[0] ?? '') : $header;
        $type = strtolower(trim(explode(';', (string) $header)[0]));

        if (! $response->successful() || ! str_starts_with($type, 'image/')) {
            return;
        }

        $this->storeImage($token, $response->body(), $type);
    }

    private function embeddedPhotoName(string $token): ?string
    {
        if (strlen($token) < 32 || strlen($token) % 2 !== 0 || preg_match('/\A[A-Fa-f0-9]+\z/', $token) !== 1) {
            return null;
        }

        $name = hex2bin($token);

        return is_string($name) && $this->isPhotoName($name) ? $name : null;
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
    private function storePhoto(string $token, string $name): void
    {
        try {
            PlacePhoto::query()->updateOrCreate(
                ['token' => $token],
                ['photo_name' => $name],
            );
        } catch (Throwable $exception) {
            Log::warning('place_photo.store_failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        Cache::put(self::PHOTO_CACHE_PREFIX.$token, $name, now()->addDays(self::PHOTO_TTL_DAYS));
    }

    private function recoverPhotoName(string $token): ?string
    {
        $place = $this->placeForPhotoToken($token);

        if ($place === null) {
            return null;
        }

        $name = $this->fetchPhotoName($place['name'], $place['city']);

        if ($name === null) {
            return null;
        }

        $this->storePhoto($token, $name);

        return $name;
    }

    /**
     * @return array{name: string, city: string}|null
     */
    private function placeForPhotoToken(string $token): ?array
    {
        $itinerary = Itinerary::query()
            ->with('planSession')
            ->whereRaw($this->columnContains('content'), [$token])
            ->first();
        $content = $itinerary?->content;
        $city = $itinerary?->planSession?->city;

        if (! is_array($content)) {
            $suggestion = Suggestion::query()
                ->with('planSession')
                ->whereRaw($this->columnContains('itinerary_content'), [$token])
                ->first();
            $content = $suggestion?->itinerary_content;
            $city = $suggestion?->planSession?->city;
        }

        if (! is_array($content)) {
            return null;
        }

        $name = $this->stopNameForToken($content, $token);

        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'city' => LocationLabel::locality(is_string($city) ? $city : null),
        ];
    }

    /**
     * Find a token inside a JSON column.
     *
     * Postgres must not use a :: cast. PDO reads :text as a named parameter,
     * the lookup matches nothing, and the photo request ends before Google.
     */
    private function columnContains(string $column): string
    {
        if (! in_array($column, ['content', 'itinerary_content'], true)) {
            return '0 = 1';
        }

        $driver = Itinerary::query()->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return 'strpos(cast('.$column.' as text), ?) > 0';
        }

        return 'instr('.$column.', ?) > 0';
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function stopNameForToken(array $content, string $token): ?string
    {
        $fromStops = $this->nameFromStops($content['stops'] ?? null, $token);

        if ($fromStops !== null) {
            return $fromStops;
        }

        foreach ($content['days'] ?? [] as $day) {
            if (! is_array($day)) {
                continue;
            }

            $name = $this->nameFromStops($day['stops'] ?? null, $token);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function nameFromStops(mixed $stops, string $token): ?string
    {
        if (! is_array($stops)) {
            return null;
        }

        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $url = $stop['photo_url'] ?? null;

            if (! is_string($url) || ! str_contains($url, '/place-photos/'.$token)) {
                continue;
            }

            $name = trim((string) ($stop['name'] ?? ''));

            return $name !== '' ? $name : null;
        }

        return null;
    }

    private function fetchPhotoName(string $placeName, string $city): ?string
    {
        $key = $this->settings->googleApiKey();

        if ($key === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.photos.name',
            ])->timeout(8)->post(self::SEARCH_URL, [
                'textQuery' => $city !== '' ? $placeName.', '.$city : $placeName,
                'pageSize' => 1,
            ]);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $photoName = $response->json('places.0.photos.0.name');

        return is_string($photoName) && $this->isPhotoName($photoName) ? $photoName : null;
    }

    /**
     * @param  array<int, mixed>  $stops
     */
    private function arrayStopCount(array $stops): int
    {
        return count(array_filter($stops, 'is_array'));
    }
}
