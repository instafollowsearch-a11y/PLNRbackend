<?php

namespace App\Http\Resources;

use App\Services\Events\FindLocalCredit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WeekendRecommendation */
class WeekendRecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'city' => $this->city,
            'interests' => $this->interests ?? [],
            'window_start' => $this->window_start?->toIso8601String(),
            'window_end' => $this->window_end?->toIso8601String(),
            'items' => $this->items ?? [],
            'saturday_plan' => $this->saturday_plan,
            'event_credits' => FindLocalCredit::forCity($this->city),
            'email_sent_at' => $this->email_sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
