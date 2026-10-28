<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Email;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppleSignIn
{
    public function __construct(
        private readonly VerifiesAppleIdToken $tokens,
    ) {}

    /**
     * @return array{0: User, 1: bool}|null
     *
     * @throws ValidationException
     */
    public function signIn(?string $idToken = null, ?string $name = null, ?string $email = null): ?array
    {
        if (! is_string($idToken) || trim($idToken) === '') {
            return null;
        }

        $payload = $this->tokens->verify(trim($idToken));

        if (! is_array($payload)) {
            return null;
        }

        $sub = trim((string) ($payload['sub'] ?? ''));

        if ($sub === '') {
            return null;
        }

        $tokenEmail = Email::normalize((string) ($payload['email'] ?? ''));
        $resolvedEmail = $tokenEmail !== '' ? $tokenEmail : Email::normalize($email);

        if ($resolvedEmail === '') {
            return null;
        }

        $bySub = User::query()->where('apple_sub', $sub)->first();

        if ($bySub !== null) {
            return [$bySub, false];
        }

        $byEmail = User::findByEmail($resolvedEmail);

        if ($byEmail !== null) {
            if (is_string($byEmail->apple_sub) && $byEmail->apple_sub !== '' && $byEmail->apple_sub !== $sub) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already linked to a different Apple account.'],
                ]);
            }

            $byEmail->forceFill([
                'apple_sub' => $sub,
                'email_verified_at' => $byEmail->email_verified_at ?? now(),
            ])->save();

            return [$byEmail->fresh() ?? $byEmail, false];
        }

        $resolvedName = trim((string) $name);

        if ($resolvedName === '') {
            $resolvedName = 'PLNR member';
        }

        $user = DB::transaction(function () use ($sub, $resolvedEmail, $resolvedName): User {
            $user = new User;
            $user->forceFill([
                'name' => $resolvedName,
                'email' => $resolvedEmail,
                'apple_sub' => $sub,
                'password' => null,
                'email_verified_at' => now(),
            ]);
            $user->save();

            return $user;
        });

        return [$user, true];
    }
}