<?php

namespace App\Policies;

use App\Models\PlanMember;
use App\Models\PlanSession;
use App\Models\User;

class PlanSessionPolicy
{
    public function view(?User $user, PlanSession $planSession): bool
    {
        return $this->canView($user, $planSession);
    }

    public function update(?User $user, PlanSession $planSession): bool
    {
        return $this->canEdit($user, $planSession);
    }

    public function delete(?User $user, PlanSession $planSession): bool
    {
        return $this->canEdit($user, $planSession);
    }

    public function share(?User $user, PlanSession $planSession): bool
    {
        return $user !== null && $planSession->isOwnedBy($user);
    }

    private function canView(?User $user, PlanSession $planSession): bool
    {
        if ($planSession->user_id === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($planSession->isOwnedBy($user)) {
            return true;
        }

        return $planSession->isMember($user);
    }

    private function canEdit(?User $user, PlanSession $planSession): bool
    {
        if ($planSession->user_id === null) {
            return true;
        }

        return $user !== null && $planSession->isOwnedBy($user);
    }
}
