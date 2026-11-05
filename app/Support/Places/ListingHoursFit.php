<?php

namespace App\Support\Places;

final class ListingHoursFit
{
    /**
     * Unknown hours are a fit. Known hours must cover the stop window.
     */
    public static function coversWindow(?string $hours, ?string $window): bool
    {
        $hoursLine = trim((string) $hours);
        $windowLine = trim((string) $window);

        if ($hoursLine === '' || $windowLine === '') {
            return true;
        }

        if (preg_match('/:\s*closed\s*$/i', $hoursLine) === 1) {
            return false;
        }

        if (preg_match('/open 24 hours/i', $hoursLine) === 1) {
            return true;
        }

        return ! self::windowPastClosing($windowLine, $hoursLine);
    }

    public static function windowPastClosing(?string $window, ?string $hours): bool
    {
        $closeMinutes = self::lastClockMinutes($hours);
        $endMinutes = self::lastClockMinutes($window);

        if ($closeMinutes === null || $endMinutes === null) {
            return false;
        }

        $close = $closeMinutes;
        $end = $endMinutes;

        if ($close < 6 * 60 && $end >= 12 * 60) {
            $close += 24 * 60;
        }

        if ($end < 6 * 60 && $close >= 12 * 60) {
            $end += 24 * 60;
        }

        return $end > $close;
    }

    public static function lastClockMinutes(?string $text): ?int
    {
        $source = trim((string) $text);

        if ($source === '' || preg_match('/^closed$/i', $source) === 1 || preg_match('/open 24 hours/i', $source) === 1) {
            return null;
        }

        if (preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?)?/i', $source, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $period = null;

        foreach (array_reverse($matches) as $match) {
            if (($match[3] ?? '') !== '') {
                $period = strtolower(str_replace('.', '', $match[3]));
            }

            $hour = (int) $match[1];
            $minute = (int) (($match[2] ?? '') !== '' ? $match[2] : 0);

            if ($minute > 59 || $hour > 23) {
                continue;
            }

            if ($period === null && $hour > 12) {
                return $hour * 60 + $minute;
            }

            if ($hour > 12) {
                continue;
            }

            $hour24 = $hour % 12;

            if ($period === 'pm') {
                $hour24 += 12;
            }

            return $hour24 * 60 + $minute;
        }

        return null;
    }
}
