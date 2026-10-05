<?php

namespace App\Http\Resources;

use App\Services\Events\FindLocalStopLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Itinerary */
class ItineraryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = is_array($this->content) ? $this->content : [];
        $city = $this->planSession?->city;

        return [
            'id' => $this->id,
            'content' => app(FindLocalStopLinks::class)->attach($content, is_string($city) ? $city : null),
            'email_sent_at' => $this->email_sent_at?->toIso8601String(),
        ];
    }
}
