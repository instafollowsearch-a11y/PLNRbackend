<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountDeletionService
{
    public function delete(User $user): void
    {
        if ($this->isLastAdmin($user)) {
            throw ValidationException::withMessages([
                'account' => ['The last admin account cannot be deleted.'],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $user->planSessions()->delete();
            $user->pageVisits()->delete();
            $user->tokens()->delete();
            $user->delete();
        });
    }

    private function isLastAdmin(User $user): bool
    {
        if ($user->role !== User::ROLE_ADMIN) {
            return false;
        }

        return User::query()->where('role', User::ROLE_ADMIN)->count() <= 1;
    }
}
