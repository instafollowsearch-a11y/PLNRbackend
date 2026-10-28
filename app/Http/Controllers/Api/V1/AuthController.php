<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\SendPasswordResetCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\AppleSignInRequest;
use App\Http\Requests\Api\V1\GoogleSignInRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\UpdateUserProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\PlanShare;
use App\Models\User;
use App\Services\Accounts\AccountDeletionService;
use App\Services\Auth\AppleSignIn;
use App\Services\Auth\GoogleSignIn;
use App\Services\Plans\PlanShareService;
use App\Services\Weekend\DeliverWeekendPicks;
use App\Support\Email;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly PlanShareService $planShares,
        private readonly AccountDeletionService $accountDeletion,
        private readonly DeliverWeekendPicks $weekendPicks,
        private readonly GoogleSignIn $googleSignIn,
        private readonly AppleSignIn $appleSignIn,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => Email::normalize($request->string('email')->toString()),
            'password' => $request->string('password')->toString(),
        ]);

        $inviteToken = $request->validated('invite_token');
        if (is_string($inviteToken) && $inviteToken !== '') {
            $share = PlanShare::query()->where('token', $inviteToken)->first();
            if ($share !== null) {
                $this->planShares->accept($share, $user);
            }
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new UserResource($user->fresh()),
                'token' => $token,
            ],
            'message' => 'Registration successful.',
        ], 201);
    }

    /**
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::findByEmail($request->string('email')->toString());

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Login successful.',
        ]);
    }

    public function google(GoogleSignInRequest $request): JsonResponse
    {
        $dob = $request->input('date_of_birth');
        $idToken = $request->input('id_token');
        $accessToken = $request->input('access_token');
        $result = $this->googleSignIn->signIn(
            is_string($idToken) ? $idToken : null,
            is_string($dob) ? $dob : null,
            is_string($accessToken) ? $accessToken : null,
        );

        if ($result === null) {
            return response()->json([
                'message' => 'Google sign-in could not be verified.',
            ], 401);
        }

        [$user, $created] = $result;
        $this->acceptMatchingInvite($request->validated('invite_token'), $user);
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new UserResource($user->fresh()),
                'token' => $token,
            ],
            'message' => $created ? 'Registration successful.' : 'Login successful.',
        ], $created ? 201 : 200);
    }

    public function apple(AppleSignInRequest $request): JsonResponse
    {
        $identityToken = $request->input('identity_token');
        $name = $request->input('name');
        $email = $request->input('email');
        $result = $this->appleSignIn->signIn(
            is_string($identityToken) ? $identityToken : null,
            is_string($name) ? $name : null,
            is_string($email) ? $email : null,
        );

        if ($result === null) {
            return response()->json([
                'message' => 'Apple sign-in could not be verified.',
            ], 401);
        }

        [$user, $created] = $result;
        $this->acceptMatchingInvite($request->validated('invite_token'), $user);
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new UserResource($user->fresh()),
                'token' => $token,
            ],
            'message' => $created ? 'Registration successful.' : 'Login successful.',
        ], $created ? 201 : 200);
    }

    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetCode $codes): JsonResponse
    {
        $codes->send($request->string('email')->toString());

        return response()->json([
            'data' => null,
            'message' => 'If an account exists for that email, we sent a 6-digit code.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This code is invalid or has expired.'],
            ]);
        }

        return response()->json([
            'data' => null,
            'message' => 'Password updated. You can log in with the new password.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'data' => null,
            'message' => 'Logged out successfully.',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($request->user()),
            ],
            'message' => 'User retrieved successfully.',
        ]);
    }

    public function update(UpdateUserProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $previousCity = (string) $user->city;
        $previousInterests = is_array($user->interests) ? $user->interests : [];
        $payload = [];

        if ($request->exists('name')) {
            $payload['name'] = $request->string('name')->toString();
        }

        if ($request->exists('email')) {
            $payload['email'] = Email::normalize($request->string('email')->toString());
        }

        if ($request->exists('city')) {
            $payload['city'] = $request->string('city')->toString();
        }

        if ($request->exists('interests')) {
            $payload['interests'] = array_values(array_filter(array_map(
                static fn (mixed $interest): string => trim((string) $interest),
                $request->input('interests', []),
            )));
        }

        if ($payload !== []) {
            $user->update($payload);
        }

        $user = $user->fresh();
        $weekendDelivery = 'skipped';

        if ($this->weekendPreferencesChanged($previousCity, $previousInterests, $user)) {
            try {
                $weekendDelivery = $this->weekendPicks->deliver($user);
            } catch (\Throwable $exception) {
                $weekendDelivery = 'failed';
                Log::warning('weekend.preference_delivery_failed', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'weekend_delivery' => $weekendDelivery,
            ],
            'message' => 'Profile updated successfully.',
        ]);
    }

    /**
     * @param  list<string>  $previousInterests
     */
    private function weekendPreferencesChanged(string $previousCity, array $previousInterests, User $user): bool
    {
        $nextInterests = is_array($user->interests) ? $user->interests : [];

        return mb_strtolower(trim($previousCity)) !== mb_strtolower(trim((string) $user->city))
            || $this->interestKey($previousInterests) !== $this->interestKey($nextInterests);
    }

    /**
     * @param  list<mixed>  $interests
     */
    private function interestKey(array $interests): string
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn (mixed $interest): string => mb_strtolower(trim((string) $interest)),
            $interests,
        ))));
        sort($items);

        return implode('|', $items);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'password' => $request->string('password')->toString(),
        ]);

        $currentToken = $user->currentAccessToken();
        $tokens = $user->tokens();
        if ($currentToken instanceof PersonalAccessToken) {
            $tokens->whereKeyNot($currentToken->getKey());
        }
        $tokens->delete();

        return response()->json([
            'data' => null,
            'message' => 'Password updated.',
        ]);
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->accountDeletion->delete($user);

        return response()->json([
            'data' => null,
            'message' => 'Account deleted.',
        ]);
    }

    private function acceptMatchingInvite(mixed $inviteToken, User $user): void
    {
        if (! is_string($inviteToken) || $inviteToken === '') {
            return;
        }

        $share = PlanShare::query()->where('token', $inviteToken)->first();

        if ($share === null || ! $share->isPending()) {
            return;
        }

        if (Email::normalize((string) $user->email) !== Email::normalize((string) $share->invitee_email)) {
            return;
        }

        $this->planShares->accept($share, $user);
    }
}
