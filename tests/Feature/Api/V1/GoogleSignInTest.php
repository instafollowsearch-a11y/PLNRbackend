<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanMember;
use App\Models\PlanSession;
use App\Models\PlanShare;
use App\Models\PlanType;
use App\Models\User;
use App\Services\Auth\VerifiesGoogleIdToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeGoogleIdTokenVerifier;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleIdTokenVerifier $google;

    protected function setUp(): void
    {
        parent::setUp();

        $this->google = new FakeGoogleIdTokenVerifier(null);
        $this->app->instance(VerifiesGoogleIdToken::class, $this->google);
    }

    public function test_new_google_user_is_created_without_a_password(): void
    {
        $this->fakeGoogle([
            'sub' => 'google-sub-1',
            'email' => 'Ada@plnr.test',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-token',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@plnr.test')
            ->assertJsonPath('data.user.name', 'Ada Lovelace')
            ->assertJsonPath('message', 'Registration successful.');

        $user = User::query()->where('email', 'ada@plnr.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('google-sub-1', $user->google_sub);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotEmpty($user->tokens()->first());
    }

    public function test_existing_email_is_linked_and_keeps_its_password(): void
    {
        $existing = User::factory()->create([
            'email' => 'ada@plnr.test',
            'password' => 'password123',
        ]);
        $passwordHash = $existing->password;

        $this->fakeGoogle([
            'sub' => 'google-sub-2',
            'email' => 'ada@plnr.test',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-token',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'ada@plnr.test')
            ->assertJsonPath('message', 'Login successful.');

        $existing->refresh();
        $this->assertSame('google-sub-2', $existing->google_sub);
        $this->assertSame($passwordHash, $existing->password);
        $this->assertTrue(Hash::check('password123', (string) $existing->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@plnr.test',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_google_only_account_cannot_log_in_with_a_password(): void
    {
        User::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@plnr.test',
            'google_sub' => 'google-sub-1',
            'password' => null,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@plnr.test',
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_invalid_google_token_is_rejected(): void
    {
        $this->fakeGoogle([
            'sub' => 'google-sub-1',
            'email' => 'ada@plnr.test',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'invalid',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Google sign-in could not be verified.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogle([
            'sub' => 'google-sub-1',
            'email' => 'ada@plnr.test',
            'email_verified' => false,
            'name' => 'Ada Lovelace',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-token',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invite_token_accepts_only_when_emails_match(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'user_id' => $owner->id,
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_COMPLETED,
        ]);
        $share = PlanShare::query()->create([
            'plan_session_id' => $session->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'friend@plnr.test',
            'status' => PlanShare::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $this->fakeGoogle([
            'sub' => 'google-sub-other',
            'email' => 'other@plnr.test',
            'email_verified' => true,
            'name' => 'Other Person',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-token',
            'invite_token' => $share->token,
        ])->assertCreated();

        $this->assertSame(PlanShare::STATUS_PENDING, $share->fresh()->status);
        $this->assertDatabaseMissing('plan_members', [
            'plan_session_id' => $session->id,
            'role' => PlanMember::ROLE_VIEWER,
        ]);

        $this->fakeGoogle([
            'sub' => 'google-sub-friend',
            'email' => 'friend@plnr.test',
            'email_verified' => true,
            'name' => 'Invited Friend',
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-token',
            'invite_token' => $share->token,
        ])->assertCreated();

        $this->assertSame(PlanShare::STATUS_ACCEPTED, $share->fresh()->status);
        $this->assertDatabaseHas('plan_members', [
            'plan_session_id' => $session->id,
            'role' => PlanMember::ROLE_VIEWER,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function fakeGoogle(?array $payload): void
    {
        $this->google->payload = $payload;
    }
}
