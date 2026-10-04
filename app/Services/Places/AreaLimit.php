<?php

namespace App\Services\Places;

class AreaLimit
{
    public function __construct(
        public readonly string $label,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly int $miles,
    ) {}

    /**
     * @param  array<string, mixed>  $answers
     */
    public static function fromAnswers(array $answers): ?self
    {
        if (($answers['stay_in_area'] ?? null) !== 'Yes') {
            return null;
        }

        $miles = $answers['area_radius_miles'] ?? null;

        if (! in_array((string) $miles, ['1', '3', '5'], true)) {
            return null;
        }

        $center = self::center($answers['area_center'] ?? null);

        if ($center === null) {
            return null;
        }

        return new self($center['label'], $center['latitude'], $center['longitude'], (int) $miles);
    }

    public function radiusMeters(): float
    {
        return $this->miles * 1609.344;
    }

    public function instruction(): string
    {
        $place = $this->label !== '' ? $this->label : 'the chosen area';

        return 'Stay within '.$this->miles.' miles of '.$place.'. Do not suggest a place outside that circle.';
    }

    public function contains(float $latitude, float $longitude): bool
    {
        $earthMeters = 6371000;
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($latitude);
        $latDelta = deg2rad($latitude - $this->latitude);
        $lonDelta = deg2rad($longitude - $this->longitude);
        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;
        $distance = $earthMeters * (2 * atan2(sqrt($a), sqrt(1 - $a)));

        return $distance <= $this->radiusMeters() + 1;
    }

    /**
     * @return array{label: string, latitude: float, longitude: float}|null
     */
    private static function center(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($value) || ! is_numeric($value['lat'] ?? null) || ! is_numeric($value['lon'] ?? null)) {
            return null;
        }

        $label = $value['label'] ?? '';

        return [
            'label' => is_string($label) ? trim($label) : '',
            'latitude' => (float) $value['lat'],
            'longitude' => (float) $value['lon'],
        ];
    }
}
