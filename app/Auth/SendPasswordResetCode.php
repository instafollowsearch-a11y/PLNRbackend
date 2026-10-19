<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SendPasswordResetCode
{
    public function send(string $email): void
    {
        $user = User::findByEmail($email);

        if ($user === null) {
            return;
        }

        $tokens = $this->tokens();

        if ($tokens->recentlyCreatedToken($user)) {
            return;
        }

        $user->sendPasswordResetNotification($tokens->create($user));
    }

    private function tokens(): PinTokenRepository
    {
        $config = config('auth.passwords.users');
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new PinTokenRepository(
            DB::connection($config['connection'] ?? null),
            app('hash'),
            $config['table'],
            $key,
            ((int) ($config['expire'] ?? 60)) * 60,
            (int) ($config['throttle'] ?? 0),
        );
    }
}
