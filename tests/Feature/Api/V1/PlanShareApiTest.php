<?php

namespace Tests\Feature\Api\V1;

use App\Mail\PlanShareAcceptedMail;
use App\Mail\PlanShareInviteMail;
use App\Mail\SharedPlanItineraryMail;
use App\Models\Itinerary;
use App\Models\PlanMember;
use App\Models\PlanSession;
use App\Models\PlanShare;
use App\Models\PlanType;
use App\Models\User;
use App\Services\Sms\SendsPlanInviteSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesAnthropic;
use Tests\Fakes\FakePlanInviteSms;
use Tests\TestCase;

class PlanShareApiTest extends TestCase
{
    use FakesAnthropic;
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
            ->assertJsonPath('data.account_exists', true)
            ->assertJsonPath('data.itinerary.title', 'Austin Night')
            ->assertJsonPath('data.itinerary.stops.0.name', 'Bar');

        Mail::assertSent(PlanShareInviteMail::class, function (PlanShareInviteMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'View the plans shared with you')
                && ! str_contains($html, 'Bar');
        });

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/plan-shares/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.member.role', PlanMember::ROLE_VIEWER);

        Mail::assertSent(PlanShareAcceptedMail::class);
        Mail::assertSent(SharedPlanItineraryMail::class, function (SharedPlanItineraryMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'View the plans shared with you')
                && ! str_contains($html, 'Bar');
        });

        Sanctum::actingAs($invitee);
        $this->getJson('/api/v1/plan-sessions')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $session->uuid, 'access_role' => 'viewer']);

        $this->getJson("/api/v1/plan-sessions/{$session->uuid}")
            ->assertOk()
            ->assertJsonPath('data.plan_session.access_role', 'viewer')
            ->assertJsonPath('data.plan_session.shared_by.name', $owner->name);
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

    public function test_viewer_can_refine_but_cannot_share_or_delete(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $this->fakeAnthropicSuggestions();

        $owner = User::factory()->pro()->create();
        $viewer = User::factory()->pro()->create();
        $session = $this->ownedCompletedSession($owner);
        $session->answers = ['city' => 'Austin', 'interests' => 'Live jazz'];
        $session->save();
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
        ])->assertOk();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'someone@plnr.test',
        ])->assertStatus(422);

        $this->assertFalse($viewer->can('delete', $session->fresh()));
        $this->assertTrue($viewer->can('update', $session->fresh()));

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/plan-sessions/{$session->uuid}")
            ->assertOk()
            ->assertJsonFragment(['content' => 'Something else please']);
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

    public function test_free_owner_can_share_and_invitee_can_view(): void
    {
        Mail::fake();

        $owner = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        $invitee = User::factory()->create(['email' => 'friend@plnr.test']);
        $session = $this->ownedCompletedSession($owner);

        $this->assertFalse($owner->isPro());

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/weekend-recommendations')->assertForbidden();

        $token = $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
        ])->assertCreated()->json('data.share.token');

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/plan-shares/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.member.role', PlanMember::ROLE_VIEWER);

        $this->getJson("/api/v1/plan-sessions/{$session->uuid}")
            ->assertOk()
            ->assertJsonPath('data.plan_session.access_role', 'viewer')
            ->assertJsonPath('data.plan_session.shared_by.name', $owner->name);
    }

    public function test_email_only_share_does_not_send_sms(): void
    {
        Mail::fake();
        $sms = new FakePlanInviteSms;
        $this->app->instance(SendsPlanInviteSms::class, $sms);

        $owner = User::factory()->create();
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
        ])->assertCreated()
            ->assertJsonPath('data.share.sms_sent', false)
            ->assertJsonPath('message', 'Plan invite sent.');

        $this->assertSame([], $sms->messages);
        Mail::assertSent(PlanShareInviteMail::class);
    }

    public function test_share_with_phone_sends_sms_and_stores_the_number(): void
    {
        Mail::fake();
        config(['services.pro.web_app_url' => 'https://myplnr.app']);
        $sms = new FakePlanInviteSms;
        $this->app->instance(SendsPlanInviteSms::class, $sms);

        $owner = User::factory()->create(['name' => 'Alex']);
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
            'phone' => '+1 (555) 123-4567',
        ])->assertCreated()
            ->assertJsonPath('data.share.invitee_phone', '+15551234567')
            ->assertJsonPath('data.share.sms_sent', true);

        $this->assertDatabaseHas('plan_shares', [
            'invitee_email' => 'friend@plnr.test',
            'invitee_phone' => '+15551234567',
        ]);
        $this->assertCount(1, $sms->messages);
        $this->assertSame('+15551234567', $sms->messages[0]['to']);
        $this->assertStringContainsString('Alex invited you to a plan on PLNR.', $sms->messages[0]['body']);
        $this->assertStringContainsString('https://myplnr.app/invite/'.$response->json('data.share.token'), $sms->messages[0]['body']);
        $this->assertStringContainsString('Reply STOP to opt out.', $sms->messages[0]['body']);
        Mail::assertSent(PlanShareInviteMail::class);
    }

    public function test_sms_failure_keeps_the_email_invite(): void
    {
        Mail::fake();
        $sms = new FakePlanInviteSms(shouldFail: true);
        $this->app->instance(SendsPlanInviteSms::class, $sms);

        $owner = User::factory()->create();
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
            'phone' => '+15551234567',
        ])->assertCreated()
            ->assertJsonPath('data.share.sms_sent', false)
            ->assertJsonPath('message', 'Invite email sent. The text could not be sent.');

        $this->assertDatabaseHas('plan_shares', [
            'invitee_email' => 'friend@plnr.test',
            'invitee_phone' => '+15551234567',
        ]);
        Mail::assertSent(PlanShareInviteMail::class);
    }

    public function test_phone_without_twilio_configuration_still_sends_email(): void
    {
        Mail::fake();
        $sms = new FakePlanInviteSms(isConfigured: false);
        $this->app->instance(SendsPlanInviteSms::class, $sms);

        $owner = User::factory()->create();
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
            'phone' => '+15551234567',
        ])->assertCreated()
            ->assertJsonPath('data.share.sms_sent', false)
            ->assertJsonPath('message', 'Invite email sent. Text messaging is not set up.');

        $this->assertSame([], $sms->messages);
        Mail::assertSent(PlanShareInviteMail::class);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $session = $this->ownedCompletedSession($owner);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/shares", [
            'email' => 'friend@plnr.test',
            'phone' => '5551234567',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('plan_shares', 0);
        Mail::assertNothingSent();
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
