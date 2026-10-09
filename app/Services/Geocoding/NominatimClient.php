<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class NominatimClient
{
    /**
     * @return list<array{label: string, lat: float, lon: float}>
     */
    public function search(string $query): array
    {
        $trimmed = trim($query);

        if (mb_strlen($trimmed) < 2) {
            return [];
        }

        $payload = $this->get('/search', [
            'q' => $trimmed,
            'format' => 'json',
            'limit' => 6,
            'addressdetails' => 1,
            'accept-language' => 'en',
        ]);

        if (! is_array($payload)) {
            return [];
        }

        $results = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $place = $this->place($item);

            if ($place !== null) {
                $results[] = $place;
            }
        }

        return $results;
    }

    /**
     * @return array{label: string, lat: float, lon: float}|null
     */
    public function reverse(float $lat, float $lon): ?array
    {
        $payload = $this->get('/reverse', [
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'json',
            'addressdetails' => 1,
            'accept-language' => 'en',
        ]);

        if (! is_array($payload)) {
            return null;
        }

        $place = $this->place($payload);

        if ($place === null) {
            return null;
        }

        return [
            'label' => $place['label'],
            'lat' => $lat,
            'lon' => $lon,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private function get(string $path, array $query): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => (string) config('services.nominatim.user_agent'),
                ])
                ->timeout(8)
                ->get(rtrim((string) config('services.nominatim.url'), '/').$path, $query);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<mixed>  $item
     * @return array{label: string, lat: float, lon: float}|null
     */
    private function place(array $item): ?array
    {
        $label = $this->label($item);
        $lat = isset($item['lat']) ? (float) $item['lat'] : null;
        $lon = isset($item['lon']) ? (float) $item['lon'] : null;

        if ($label === '' || $lat === null || $lon === null || ! is_finite($lat) || ! is_finite($lon)) {
            return null;
        }

        return [
            'label' => $label,
            'lat' => $lat,
            'lon' => $lon,
        ];
    }

    /**
     * @param  array<mixed>  $item
     */
    private function label(array $item): string
    {
        $address = is_array($item['address'] ?? null) ? $item['address'] : [];
        $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? null;
        $state = $address['state'] ?? null;
        $country = $address['country'] ?? null;
        $street = $this->street($address);

        if ($street !== '') {
            $region = is_string($state) && $state !== '' ? $state : (is_string($country) && $country !== '' ? $country : null);
            $parts = array_values(array_filter(
                [$street, is_string($city) && $city !== '' ? $city : null, $region],
                fn (?string $part): bool => $part !== null && $part !== '',
            ));

            if ($parts !== []) {
                return implode(', ', $parts);
            }
        }

        if (is_string($city) && is_string($state) && $city !== '' && $state !== '') {
            return $city.', '.$state;
        }

        if (is_string($city) && is_string($country) && $city !== '' && $country !== '') {
            return $city.', '.$country;
        }

        if (! is_string($item['display_name'] ?? null) || $item['display_name'] === '') {
            return '';
        }

        return implode(',', array_slice(explode(',', $item['display_name']), 0, 3));
    }

    /**
     * @param  array<mixed>  $address
     */
    private function street(array $address): string
    {
        $number = is_string($address['house_number'] ?? null) ? trim($address['house_number']) : '';
        $road = is_string($address['road'] ?? null) ? trim($address['road']) : '';

        if ($number !== '' && $road !== '') {
            return $number.' '.$road;
        }

        return $road;
    }
}
