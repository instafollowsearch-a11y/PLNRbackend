<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'city' => $this->city,
            'interests' => $this->interests ?? [],
            'role' => $this->role ?? User::ROLE_USER,
            'pro_status' => $this->pro_status ?? User::PRO_STATUS_INACTIVE,
            'pro_current_period_end' => $this->pro_current_period_end?->toIso8601String(),
            'is_pro' => $this->resource->isPro(),
        ];
    }
}
