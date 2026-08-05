<?php

namespace Tests\Feature\Api\V1;

use App\Mail\PlanShareAcceptedMail;
use App\Mail\PlanShareInviteMail;
use App\Models\Itinerary;
use App\Models\PlanMember;
use App\Models\PlanSession;
use App\Models\PlanShare;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanShareApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pro_owner_can_share_and_invitee_can_accept(): void
    {
        Mail::fake();

        $owner = User::factory()->pro()->create();
        $invitee = User::factory()->create(['email' => 'friend@plnr.test']);
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $shareResponse = $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
        ])->assertCreated();

        $token = $shareResponse->json('data.share.token');
        Mail::assertSent(PlanShareInviteMail::class);

        $this->getJson("/api/v1/plan-shares/{$token}")
            ->assertOk()
            ->assertJsonPath('data.invitee_email', 'friend@plnr.test')
            ->assertJsonPath('data.account_exists', true);

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/plan-shares/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.member.role', PlanMember::ROLE_VIEWER);

        Mail::assertSent(PlanShareAcceptedMail::class);

        Sanctum::actingAs($invitee);
        $this->getJson('/api/v1/plan-sessions')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $session->uuid, 'access_role' => 'viewer']);
    }

    public function test_register_with_invite_token_accepts_share(): void
    {
        Mail::fake();

        $owner = User::factory()->pro()->create();
        $session = $this->ownedCompletedSession($owner);
        $share = PlanShare::query()->create([
            'plan_session_id' => $session->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'newfriend@plnr.test',
            'status' => PlanShare::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Friend',
            'email' => 'newfriend@plnr.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_token' => $share->token,
        ])->assertCreated();

        $this->assertDatabaseHas('plan_members', [
            'plan_session_id' => $session->id,
            'role' => PlanMember::ROLE_VIEWER,
        ]);
        $this->assertSame(PlanShare::STATUS_ACCEPTED, $share->fresh()->status);
    }

    public function test_viewer_cannot_refine(): void
    {
        $owner = User::factory()->pro()->create();
        $viewer = User::factory()->create();
        $session = $this->ownedCompletedSession($owner);
        $session->ensureOwnerMembership();
        PlanMember::query()->create([
            'plan_session_id' => $session->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/refine", [
            'message' => 'Something else please',
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/plan-sessions/{$session->uuid}")
            ->assertOk();
    }

    public function test_wrong_email_cannot_accept(): void
    {
        $owner = User::factory()->pro()->create();
        $other = User::factory()->create(['email' => 'other@plnr.test']);
        $session = $this->ownedCompletedSession($owner);
        $share = PlanShare::query()->create([
            'plan_session_id' => $session->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'friend@plnr.test',
            'status' => PlanShare::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/plan-shares/{$share->token}/accept")
            ->assertStatus(422);
    }

    public function test_non_pro_cannot_share(): void
    {
        $owner = User::factory()->create();
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
        ])->assertForbidden();
    }

    private function ownedCompletedSession(User $owner): PlanSession
    {
        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'user_id' => $owner->id,
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_COMPLETED,
            'city' => 'Austin',
        ]);
        Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'email_sent_at' => now(),
            'content' => [
                'title' => 'Austin Night',
                'summary' => 'Fun',
                'stops' => [
                    ['name' => 'Bar', 'time' => '8:00 PM', 'activity' => 'Drinks'],
                ],
            ],
        ]);

        return $session;
    }
}
