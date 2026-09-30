<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_email_sends_nothing_and_uses_the_same_message(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@plnr.test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, we sent a reset link.');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_known_email_sends_one_link_and_stores_only_a_hash(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'user@plnr.test',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'user@plnr.test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, we sent a reset link.');

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token): bool {
            $token = $notification->token;
            $mail = $notification->toMail($user);
            $url = (string) $mail->actionUrl;

            return str_contains($url, '/reset-password?')
                && str_contains($url, 'token=')
                && str_contains($url, 'email=user%40plnr.test');
        });

        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        $stored = DB::table('password_reset_tokens')->where('email', 'user@plnr.test')->value('token');

        $this->assertIsString($token);
        $this->assertIsString($stored);
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
    }

    public function test_bad_or_used_token_is_rejected_and_a_valid_token_changes_the_password(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'user@plnr.test',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'user@plnr.test',
        ])->assertOk();

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@plnr.test',
            'token' => 'not-a-real-token',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@plnr.test',
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@plnr.test',
            'token' => $token,
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated. You can log in with the new password.');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@plnr.test',
            'token' => $token,
            'password' => 'anotherpass',
            'password_confirmation' => 'anotherpass',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@plnr.test',
            'password' => 'password123',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@plnr.test',
            'password' => 'newpassword',
        ])->assertOk()->assertJsonPath('data.user.email', 'user@plnr.test');
    }
}
