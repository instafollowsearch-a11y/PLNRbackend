<?php

namespace App\Services\Places;

class VenuePhotoWebCheck
{
    public function __construct(
        private readonly PlaceWebLookup $lookup,
    ) {}

    /**
     * Direct image addresses Claude found on the public web. Empty when a place has no photo.
     *
     * @param  list<string>  $names
     * @return array<string, string> lowercase place name => https image URL
     */
    public function imageUrls(array $names, string $city): array
    {
        $found = [];

        foreach ($this->lookup->lookup($names, $city) as $name => $row) {
            if (isset($row['image_url'])) {
                $found[$name] = $row['image_url'];
            }
        }

        return $found;
    }
}
