<?php

namespace App\Services\Pro;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProAccess
{
    public function isActive(User $user): bool
    {
        if ($user->pro_status === User::PRO_STATUS_ACTIVE) {
            return true;
        }

        if ($user->pro_status === User::PRO_STATUS_PAST_DUE
            && $user->pro_current_period_end !== null
            && $user->pro_current_period_end->isFuture()) {
            return true;
        }

        return false;
    }

    public function assertActive(User $user): void
    {
        if ($this->isActive($user)) {
            return;
        }

        throw new HttpException(403, 'Pro subscription required.');
    }
}
