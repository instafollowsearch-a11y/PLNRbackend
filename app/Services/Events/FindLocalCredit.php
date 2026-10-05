<?php

namespace App\Services\Events;

class FindLocalCredit
{

    /**
     * The event card links to the Find Local event page. No homepage credit.
     *
     * @return list<array{source: string, label: string, url: string}>
     */
    public static function forCity(?string $city): array
    {
        return [];
    }
}
