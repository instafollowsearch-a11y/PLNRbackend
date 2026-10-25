<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class AgeGate
{
    public const MINIMUM_AGE = 13;

    public static function maximumAllowedBirthDate(?CarbonInterface $now = null): Carbon
    {
        return ($now ? Carbon::instance($now) : now())->copy()->startOfDay()->subYears(self::MINIMUM_AGE);
    }

    public static function isOldEnough(CarbonInterface|string $dateOfBirth, ?CarbonInterface $now = null): bool
    {
        $dob = $dateOfBirth instanceof CarbonInterface
            ? Carbon::instance($dateOfBirth)->startOfDay()
            : Carbon::parse($dateOfBirth)->startOfDay();

        return $dob->lessThanOrEqualTo(self::maximumAllowedBirthDate($now));
    }
}
