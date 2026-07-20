<?php

namespace App\Services\Events;

use Carbon\CarbonInterface;

class NormalizedEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $city,
        public readonly ?string $venueName,
        public readonly CarbonInterface $startsAt,
        public readonly ?CarbonInterface $endsAt,
        public readonly ?string $url,
        public readonly ?string $imageUrl,
        public readonly ?float $priceMin,
        public readonly ?float $priceMax,
        public readonly array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'source' => $this->source,
            'external_id' => $this->externalId,
            'title' => $this->title,
            'description' => $this->description,
            'city' => $this->city,
            'venue_name' => $this->venueName,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'url' => $this->url,
            'image_url' => $this->imageUrl,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'payload' => $this->payload,
        ];
    }
}
