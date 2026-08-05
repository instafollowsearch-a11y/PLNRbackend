<?php

namespace App\Http\Resources;

use App\Models\PlanMember;
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
        $user = $request->user();
        $accessRole = $this->resource->memberRole($user);
        if ($accessRole === null && $this->resource->user_id === null) {
            $accessRole = 'guest';
        }

        $sharedBy = null;
        if ($accessRole === PlanMember::ROLE_VIEWER && $this->relationLoaded('user') && $this->user) {
            $sharedBy = [
                'name' => $this->user->name,
                'email' => $this->user->email,
            ];
        }

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'city' => $this->city,
            'answers' => $this->answers,
            'refinement_messages' => $this->refinement_messages,
            'access_role' => $accessRole,
            'shared_by' => $sharedBy,
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
