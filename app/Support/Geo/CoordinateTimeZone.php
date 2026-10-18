<?php

namespace App\Support\Geo;

use App\Services\Geocoding\NominatimClient;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Cache;

class CoordinateTimeZone
{
    public function __construct(private readonly NominatimClient $nominatim) {}

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function applyToAnswers(array $answers): array
    {
        if ($this->saved($answers) !== null) {
            return $answers;
        }

        $point = $this->pointFromAnswers($answers);

        if ($point === null) {
            return $answers;
        }

        $zone = $this->find($point['lat'], $point['lon']);

        if ($zone !== null) {
            $answers['timezone'] = $zone;
        }

        return $answers;
    }

    public function find(float $latitude, float $longitude): ?string
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        $cacheKey = sprintf('coord-tz:%0.2f:%0.2f', $latitude, $longitude);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $this->valid($cached);
        }

        $zone = $this->fromLocalLookup($latitude, $longitude);

        if ($zone !== null) {
            Cache::put($cacheKey, $zone, now()->addDays(30));
        }

        return $zone;
    }

    public function findForCity(?string $city): ?string
    {
        $city = LocationLabel::locality($city);

        if ($city === '') {
            return null;
        }

        $cacheKey = 'city-tz:'.sha1(strtolower($city));
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $this->valid($cached);
        }

        $results = $this->nominatim->search($city, 4);
        $first = $results[0] ?? null;

        if ($first === null) {
            return null;
        }

        $zone = $this->find($first['lat'], $first['lon']);

        if ($zone !== null) {
            Cache::put($cacheKey, $zone, now()->addDays(30));
        }

        return $zone;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{lat: float, lon: float}|null
     */
    private function pointFromAnswers(array $answers): ?array
    {
        if (is_numeric($answers['latitude'] ?? null) && is_numeric($answers['longitude'] ?? null)) {
            return [
                'lat' => (float) $answers['latitude'],
                'lon' => (float) $answers['longitude'],
            ];
        }

        $center = $answers['area_center'] ?? null;

        if (is_string($center)) {
            $decoded = json_decode($center, true);
            $center = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($center) || ! is_numeric($center['lat'] ?? null) || ! is_numeric($center['lon'] ?? null)) {
            return null;
        }

        return [
            'lat' => (float) $center['lat'],
            'lon' => (float) $center['lon'],
        ];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function saved(array $answers): ?string
    {
        $zone = $answers['timezone'] ?? null;

        return is_string($zone) ? $this->valid($zone) : null;
    }

    private function fromLocalLookup(float $latitude, float $longitude): ?string
    {
        $module = $this->modulePath();

        if ($module === null) {
            return null;
        }

        $script = <<<'JS'
const tz = require(process.env.TZ_MODULE);
const zone = tz(Number(process.env.TZ_LAT), Number(process.env.TZ_LON));
if (typeof zone === 'string') process.stdout.write(zone);
JS;

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['node', '-e', $script],
            $descriptors,
            $pipes,
            null,
            [
                'PATH' => (getenv('PATH') ?: '/usr/bin:/bin').':/opt/homebrew/bin:/usr/local/bin',
                'TZ_MODULE' => $module,
                'TZ_LAT' => (string) $latitude,
                'TZ_LON' => (string) $longitude,
            ],
        );

        if (! is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return is_string($output) ? $this->valid($output) : null;
    }

    private function modulePath(): ?string
    {
        foreach ([
            base_path('../web/node_modules/tz-lookup'),
            base_path('../app/node_modules/tz-lookup'),
        ] as $path) {
            if (is_file($path.'/package.json')) {
                return $path;
            }
        }

        return null;
    }

    private function valid(string $zone): ?string
    {
        $zone = trim($zone);

        if ($zone === '') {
            return null;
        }

        try {
            new DateTimeZone($zone);
        } catch (Exception) {
            return null;
        }

        return $zone;
    }
}
