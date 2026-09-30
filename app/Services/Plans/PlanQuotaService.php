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

    public function monthlyLimit(): int
    {
        return $this->settings->freePlansPerMonth();
    }

    public function usedThisMonth(?User $user, ?string $ip): int
    {
        $query = PlanSession::query()->where('created_at', '>=', now()->startOfMonth());

        if ($user !== null) {
            $query->where('user_id', $user->id);
        } else {
            $query->whereNull('user_id')->where('creator_ip', $ip);
        }

        return $query->count();
    }

    public function remainingThisMonth(?User $user, ?string $ip): int
    {
        if ($user?->isPro()) {
            return $this->monthlyLimit();
        }

        return max(0, $this->monthlyLimit() - $this->usedThisMonth($user, $ip));
    }

    public function hasReachedLimit(?User $user, ?string $ip): bool
    {
        if ($user?->isPro()) {
            return false;
        }

        $limit = $this->monthlyLimit();

        if ($limit <= 0) {
            return true;
        }

        return $this->usedThisMonth($user, $ip) >= $limit;
    }

    public function assertCanCreate(Request $request): void
    {
        $user = $request->user();

        if ($user?->isPro()) {
            return;
        }

        if (! $this->hasReachedLimit($user, $request->ip())) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Monthly free plan limit reached. Create an account or try again next month.',
            'data' => [
                'limit' => $this->monthlyLimit(),
                'remaining' => 0,
                'window' => 'month',
            ],
        ], 429));
    }
}
