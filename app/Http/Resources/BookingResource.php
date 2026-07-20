<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Booking */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];
        $actionUrl = $metadata['venue_url'] ?? $metadata['external_url'] ?? null;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'status' => $this->status,
            'fulfillment_status' => $this->fulfillment_status,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'venue_url' => $metadata['venue_url'] ?? null,
            'external_url' => $metadata['external_url'] ?? null,
            'action_url' => $actionUrl,
            'plan_session_uuid' => $this->whenLoaded(
                'planSession',
                fn () => $this->planSession?->uuid,
            ),
        ];
    }
}
