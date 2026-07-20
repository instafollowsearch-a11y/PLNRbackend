<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PlanSession */
class PlanSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'city' => $this->city,
            'answers' => $this->answers,
            'refinement_messages' => $this->refinement_messages,
            'plan_type' => $this->whenLoaded('planType', fn () => [
                'slug' => $this->planType?->slug,
                'label' => $this->planType?->label,
            ]),
            'suggestions' => SuggestionResource::collection($this->whenLoaded('suggestions')),
            'itinerary' => new ItineraryResource($this->whenLoaded('itinerary')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
