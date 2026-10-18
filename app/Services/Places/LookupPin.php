<?php

namespace App\Services\Places;

class LookupPin
{
    public ?float $latitude = null;

    public ?float $longitude = null;

    public function note(?float $latitude, ?float $longitude): void
    {
        if ($this->latitude !== null || $latitude === null || $longitude === null) {
            return;
        }

        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }
}
