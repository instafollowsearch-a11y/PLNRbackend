<?php

namespace App\Services\Places;

class OpeningHoursWebCheck
{
    public function __construct(
        private readonly PlaceWebLookup $lookup,
    ) {}

    /**
     * @param  array<int, mixed>  $stops
     * @return array<int, mixed>
     */
    public function fill(array $stops, string $city): array
    {
        $names = [];

        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $name = trim((string) ($stop['name'] ?? ''));
            $hours = trim((string) ($stop['hours'] ?? ''));

            if ($name !== '' && $hours === '') {
                $names[] = $name;
            }
        }

        $found = [];

        foreach ($this->lookup->lookup($names, $city) as $name => $row) {
            if (isset($row['hours'])) {
                $found[$name] = $row['hours'];
            }
        }

        return $this->applyHours($stops, $found);
    }

    /**
     * @param  array<int, mixed>  $stops
     * @param  array<string, string>  $found
     * @return array<int, mixed>
     */
    private function applyHours(array $stops, array $found): array
    {
        if ($found === []) {
            return $stops;
        }

        foreach ($stops as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $name = strtolower(trim((string) ($stop['name'] ?? '')));
            $hours = trim((string) ($stop['hours'] ?? ''));

            if ($name === '' || $hours !== '' || ! isset($found[$name])) {
                continue;
            }

            $stop['hours'] = $found[$name];
            $stops[$index] = $stop;
        }

        return $stops;
    }
}
