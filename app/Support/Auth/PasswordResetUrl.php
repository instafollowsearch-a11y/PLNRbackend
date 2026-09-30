<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Services\Settings\AppSettings;

final class PasswordResetUrl
{
    public static function for(User $user, string $token): string
    {
        $configured = app(AppSettings::class)->webAppUrl() ?: config('services.pro.web_app_url');
        $base = rtrim(is_string($configured) ? $configured : '', '/');

        return $base.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);
    }
}
