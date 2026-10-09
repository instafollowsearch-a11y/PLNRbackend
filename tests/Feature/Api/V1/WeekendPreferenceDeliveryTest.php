<?php

namespace Tests\Feature\Api\V1;

use App\Mail\WeekendPickReminderMail;
use App\Mail\WeekendRecommendationsMail;
use App\Models\Event;
use App\Models\User;
use App\Models\WeekendPickReminder;
use App\Models\WeekendRecommendation;
use App\Services\AI\WeekendRecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeekendPreferenceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 15:00:00');

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pro_save_sends_once_until_interests_change(): void
    {
        Mail::fake();
        $user = User::factory()->pro()->create([
            'city' => null,
            'interests' => null,
        ]);
        Sanctum::actingAs($user);
        $this->fakeWeekendEvents();

        $this->patchJson('/api/v1/user', [
            'city' => 'Austin',
            'interests' => ['Live music', 'Jazz bars'],
        ])->assertOk()
            ->assertJsonPath('data.user.city', 'Austin')
            ->assertJsonPath('data.user.interests', ['Live music', 'Jazz bars'])
            ->assertJsonPath('data.weekend_delivery', 'sent');

        Mail::assertSent(WeekendRecommendationsMail::class, 1);
        $this->assertGreaterThan(0, WeekendPickReminder::query()->count());

        $this->patchJson('/api/v1/user', [
            'city' => 'Austin',
            'interests' => ['Jazz bars', 'Live music'],
        ])->assertOk()
            ->assertJsonPath('data.weekend_delivery', 'skipped');

        Mail::assertSent(WeekendRecommendationsMail::class, 1);

        $this->patchJson('/api/v1/user', [
            'city' => 'Austin',
            'interests' => ['Comedy shows'],
        ])->assertOk()
            ->assertJsonPath('data.weekend_delivery', 'sent');

        Mail::assertSent(WeekendRecommendationsMail::class, 2);
        $this->assertSame(2, WeekendRecommendation::query()->whereNotNull('email_sent_at')->count());
    }

    public function test_free_member_can_save_interests_without_a_send(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'city' => 'Austin',
            'interests' => null,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'interests' => ['Live music'],
        ])->assertOk()
            ->assertJsonPath('data.weekend_delivery', 'skipped');

        $this->assertSame(['Live music'], $user->fresh()->interests);
        Mail::assertNothingSent();
    }

    public function test_friday_skips_empty_interests_and_does_not_resend_a_window(): void
    {
        Mail::fake();
        User::factory()->pro()->create([
            'email' => 'empty@plnr.test',
            'city' => 'Austin',
            'interests' => [],
        ]);
        $ready = User::factory()->pro()->create([
            'email' => 'ready@plnr.test',
            'city' => 'Austin',
            'interests' => ['live music'],
        ]);
        $this->fakeWeekendEvents();

        $this->artisan('weekends:send', ['--email' => 'empty@plnr.test'])->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('weekends:send', ['--email' => 'ready@plnr.test'])->assertSuccessful();
        Mail::assertSent(WeekendRecommendationsMail::class, 1);

        $this->artisan('weekends:send', ['--email' => 'ready@plnr.test'])->assertSuccessful();
        Mail::assertSent(WeekendRecommendationsMail::class, 1);
        $this->assertNotNull($ready->weekendRecommendations()->first()?->email_sent_at);
    }

    public function test_invitation_does_not_mark_the_owners_email_as_sent(): void
    {
        Mail::fake();
        $user = User::factory()->pro()->create([
            'city' => 'Austin',
            'interests' => ['live music'],
        ]);
        Sanctum::actingAs($user);
        $this->fakeWeekendEvents();

        $this->postJson('/api/v1/weekend-recommendations', [
            'city' => 'Austin',
            'interests' => ['live music'],
        ])->assertCreated();

        $recommendation = WeekendRecommendation::query()->firstOrFail();
        $this->assertNull($recommendation->email_sent_at);

        $this->postJson('/api/v1/weekend-recommendations/'.$recommendation->uuid.'/invite', [
            'email' => 'friend@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'Invitation sent.');

        Mail::assertSent(WeekendRecommendationsMail::class, function (WeekendRecommendationsMail $mail): bool {
            return $mail->hasTo('friend@example.com');
        });
        $this->assertNull($recommendation->fresh()->email_sent_at);
    }

    public function test_due_nudge_is_emailed(): void
    {
        Mail::fake();
        $user = User::factory()->pro()->create([
            'city' => null,
            'interests' => null,
        ]);
        Sanctum::actingAs($user);
        $this->fakeWeekendEvents();

        $this->patchJson('/api/v1/user', [
            'city' => 'Austin',
            'interests' => ['Live music'],
        ])->assertOk();

        $reminder = WeekendPickReminder::query()->orderBy('remind_at')->firstOrFail();
        Carbon::setTestNow($reminder->remind_at);

        $this->artisan('weekends:send-pick-reminders')->assertSuccessful();

        Mail::assertSent(WeekendPickReminderMail::class, 1);
        $this->assertNotNull($reminder->fresh()->email_sent_at);
    }

    private function fakeWeekendEvents(): void
    {
        $window = app(WeekendRecommendationService::class)->comingWeekendWindow();
        $events = collect([
            $window[0]->copy()->setTime(19, 0),
            $window[0]->copy()->next(Carbon::SATURDAY)->setTime(20, 0),
            $window[1]->copy()->setTime(18, 0),
        ])->map(fn (Carbon $startsAt) => Event::factory()->create([
            'city' => 'Austin',
            'starts_at' => $startsAt,
        ]));

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_weekend',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'recommendations' => $events->map(fn (Event $event) => [
                            'event_id' => $event->id,
                            'reason' => 'Matches your interests.',
                        ])->values()->all(),
                    ]),
                ]],
            ]),
            'exp.host/*' => Http::response([
                'data' => [['status' => 'ok']],
            ]),
        ]);
    }
}
