<?php

namespace App\Policies;

use App\Models\PlanSession;
use App\Models\User;

class PlanSessionPolicy
{
    public function view(?User $user, PlanSession $planSession): bool
    {
        return $this->canAccess($user, $planSession);
    }

    public function update(?User $user, PlanSession $planSession): bool
    {
        return $this->canAccess($user, $planSession);
    }

    public function delete(?User $user, PlanSession $planSession): bool
    {
        return $this->canAccess($user, $planSession);
    }

    private function canAccess(?User $user, PlanSession $planSession): bool
    {
        if ($planSession->user_id === null) {
            return true;
        }

        return $user !== null && $planSession->user_id === $user->id;
    }
}
