<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoogleSignIn
{
    public function __construct(
        private readonly VerifiesGoogleIdToken $tokens,
    ) {}

    /**
     * @return array{0: User, 1: bool}|null
     *
     * @throws ValidationException
     */
    public function signIn(string $idToken): ?array
    {
        $payload = $this->tokens->verify($idToken);

        if (! is_array($payload)) {
            return null;
        }

        $sub = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($sub === '' || $email === '' || ! $this->emailIsVerified($payload)) {
            return null;
        }

        $bySub = User::query()->where('google_sub', $sub)->first();

        if ($bySub !== null) {
            return [$bySub, false];
        }

        $byEmail = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($byEmail !== null) {
            if (is_string($byEmail->google_sub) && $byEmail->google_sub !== '' && $byEmail->google_sub !== $sub) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already linked to a different Google account.'],
                ]);
            }

            $byEmail->forceFill([
                'google_sub' => $sub,
                'email_verified_at' => $byEmail->email_verified_at ?? now(),
            ])->save();

            return [$byEmail->fresh() ?? $byEmail, false];
        }

        $user = DB::transaction(function () use ($payload, $sub, $email): User {
            $user = new User;
            $user->forceFill([
                'name' => $this->nameFrom($payload),
                'email' => $email,
                'google_sub' => $sub,
                'password' => null,
                'email_verified_at' => now(),
            ]);
            $user->save();

            return $user;
        });

        return [$user, true];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emailIsVerified(array $payload): bool
    {
        $verified = $payload['email_verified'] ?? false;

        return $verified === true || $verified === 'true';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nameFrom(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $given = trim((string) ($payload['given_name'] ?? ''));

        return $given !== '' ? $given : 'PLNR member';
    }
}
