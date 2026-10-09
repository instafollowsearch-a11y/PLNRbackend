<?php

namespace App\Support\Geo;

class LocationLabel
{
    private const STREET_SUFFIXES = [
        'street', 'st', 'drive', 'dr', 'avenue', 'ave', 'road', 'rd', 'boulevard', 'blvd',
        'lane', 'ln', 'way', 'court', 'ct', 'place', 'pl', 'parkway', 'pkwy', 'highway', 'hwy',
        'terrace', 'ter', 'circle', 'cir', 'trail', 'trl',
    ];

    public static function cityName(?string $label): string
    {
        $parts = self::parts($label);

        if ($parts === []) {
            return '';
        }

        if (count($parts) >= 2 && self::isStreet($parts[0])) {
            return $parts[1];
        }

        return $parts[0];
    }

    public static function locality(?string $label): string
    {
        $trimmed = trim((string) $label);
        $parts = self::parts($label);

        if (count($parts) >= 2 && self::isStreet($parts[0])) {
            return implode(', ', array_slice($parts, 1));
        }

        return $trimmed;
    }

    public static function eventCity(?string $label): string
    {
        $trimmed = trim((string) $label);
        $parts = self::parts($label);

        if (count($parts) >= 2 && self::isStreet($parts[0])) {
            return $parts[1];
        }

        return $trimmed;
    }

    public static function isStreet(string $segment): bool
    {
        if (preg_match('/^\d/', $segment) === 1) {
            return true;
        }

        foreach (preg_split('/\s+/', strtolower($segment)) ?: [] as $word) {
            if (in_array(rtrim($word, '.'), self::STREET_SUFFIXES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function parts(?string $label): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $label)),
            fn (string $part): bool => $part !== '',
        ));
    }
}
