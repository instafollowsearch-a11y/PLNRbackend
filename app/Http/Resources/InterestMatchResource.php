<?php

namespace App\Http\Resources;

use App\Models\InterestMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InterestMatch */
class InterestMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matched_interest' => $this->matched_interest,
            'score' => $this->score,
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'city' => $this->user->city,
            ],
            'event' => $this->event === null ? null : [
                'id' => $this->event->id,
                'title' => $this->event->title,
                'city' => $this->event->city,
                'starts_at' => $this->event->starts_at?->toIso8601String(),
                'url' => $this->event->url,
            ],
        ];
    }
}
