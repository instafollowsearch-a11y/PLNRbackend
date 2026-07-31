<?php

namespace App\Services\Plans;

use App\Models\PlanSession;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class PlanQuotaService
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    public function dailyLimit(): int
    {
        return $this->settings->freePlansPerDay();
    }

    public function usedToday(?User $user, ?string $ip): int
    {
        $query = PlanSession::query()->where('created_at', '>=', now()->startOfDay());

        if ($user !== null) {
            $query->where('user_id', $user->id);
        } else {
            $query->whereNull('user_id')->where('creator_ip', $ip);
        }

        return $query->count();
    }

    public function remainingToday(?User $user, ?string $ip): int
    {
        return max(0, $this->dailyLimit() - $this->usedToday($user, $ip));
    }

    public function hasReachedLimit(?User $user, ?string $ip): bool
    {
        $limit = $this->dailyLimit();

        if ($limit <= 0) {
            return true;
        }

        return $this->usedToday($user, $ip) >= $limit;
    }

    public function assertCanCreate(Request $request): void
    {
        if (! $this->hasReachedLimit($request->user(), $request->ip())) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Daily free plan limit reached. Create an account or try again tomorrow.',
            'data' => [
                'limit' => $this->dailyLimit(),
                'remaining' => 0,
                'window' => 'day',
            ],
        ], 429));
    }
}
