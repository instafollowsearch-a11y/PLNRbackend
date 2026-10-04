<?php

namespace App\Services\Places;

use App\Services\Settings\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class RoadTripDriveEstimate
{
    private const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    private const MILES_PER_GALLON = [
        'Sedan' => 30,
        'SUV' => 22,
        'Truck' => 18,
        'Van' => 20,
        'Other' => 25,
    ];

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function apply(array $content, ?string $carType): array
    {
        $stops = $content['stops'] ?? null;

        if (! is_array($stops)) {
            return $content;
        }

        $totals = $this->measure($stops);
        $content['stops'] = $this->stripCoordinates($stops);

        if ($totals === null) {
            return $content;
        }

        $content['total_drive_time'] = $this->formatDuration($totals['seconds']);
        $content['estimated_gas_cost'] = $this->gasCost($totals['meters'], $carType);

        return $content;
    }

    /**
     * @param  array<int, mixed>  $stops
     * @return array{seconds: int, meters: int}|null
     */
    private function measure(array $stops): ?array
    {
        $seconds = 0;
        $meters = 0;
        $legs = 0;
        $previous = null;

        foreach ($stops as $stop) {
            $point = $this->point($stop);

            if ($point === null) {
                $previous = null;

                continue;
            }

            if ($previous !== null) {
                $leg = $this->leg($previous, $point);

                if ($leg === null) {
                    return null;
                }

                $seconds += $leg['seconds'];
                $meters += $leg['meters'];
                $legs++;
            }

            $previous = $point;
        }

        if ($legs === 0) {
            return null;
        }

        return [
            'seconds' => $seconds,
            'meters' => $meters,
        ];
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function point(mixed $stop): ?array
    {
        if (! is_array($stop) || ! is_numeric($stop['latitude'] ?? null) || ! is_numeric($stop['longitude'] ?? null)) {
            return null;
        }

        return [
            'latitude' => (float) $stop['latitude'],
            'longitude' => (float) $stop['longitude'],
        ];
    }

    /**
     * @param  array{latitude: float, longitude: float}  $origin
     * @param  array{latitude: float, longitude: float}  $destination
     * @return array{seconds: int, meters: int}|null
     */
    private function leg(array $origin, array $destination): ?array
    {
        $key = $this->settings->googleApiKey();

        if ($key === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters',
            ])->timeout(8)->post(self::ROUTES_URL, [
                'origin' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $origin['latitude'],
                            'longitude' => $origin['longitude'],
                        ],
                    ],
                ],
                'destination' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $destination['latitude'],
                            'longitude' => $destination['longitude'],
                        ],
                    ],
                ],
                'travelMode' => 'DRIVE',
            ]);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            return null;
        }

        $seconds = $this->durationSeconds($route['duration'] ?? null);
        $meters = $route['distanceMeters'] ?? null;

        if ($seconds === null || ! is_numeric($meters)) {
            return null;
        }

        return [
            'seconds' => $seconds,
            'meters' => (int) $meters,
        ];
    }

    private function durationSeconds(mixed $duration): ?int
    {
        if (! is_string($duration) || ! preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $matches)) {
            return null;
        }

        return (int) round((float) $matches[1]);
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = (int) max(1, round($seconds / 60));
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($hours === 0) {
            return $minutes.' min';
        }

        if ($remainder === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$remainder.'m';
    }

    private function gasCost(int $meters, ?string $carType): float
    {
        if (is_string($carType) && strcasecmp($carType, 'Electric') === 0) {
            return 0.0;
        }

        $milesPerGallon = self::MILES_PER_GALLON[$carType ?? ''] ?? self::MILES_PER_GALLON['Other'];
        $price = (float) config('services.google.gas_price_per_gallon', 3.5);
        $miles = $meters / 1609.344;

        return round($miles / $milesPerGallon * $price, 2);
    }

    /**
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    private function stripCoordinates(array $stops): array
    {
        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            unset($stop['latitude'], $stop['longitude']);
            $stops[$index] = $stop;
        }

        return $stops;
    }
}
