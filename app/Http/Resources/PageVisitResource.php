<?php

namespace App\Http\Resources;

use App\Models\PageVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PageVisit */
class PageVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'path' => $this->path,
            'referrer' => $this->referrer,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'browser' => $this->browser,
            'browser_version' => $this->browser_version,
            'platform' => $this->platform,
            'platform_version' => $this->platform_version,
            'device' => $this->device,
            'device_type' => $this->device_type,
            'is_robot' => $this->is_robot,
            'language' => $this->language,
            'timezone' => $this->timezone,
            'screen' => $this->screen,
            'plan' => $this->plan,
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
        ];
    }
}
