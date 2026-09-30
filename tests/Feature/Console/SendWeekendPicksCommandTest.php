<?php

namespace Tests\Feature\Console;

use App\Mail\WeekendRecommendationsMail;
use App\Models\DevicePushToken;
use App\Models\Event;
use App\Models\User;
use App\Services\AI\WeekendRecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendWeekendPicksCommandTest extends TestCase
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

    public function test_pro_user_receives_one_email_and_one_push(): void
    {
        Mail::fake();
        $user = User::factory()->pro()->create([
            'email' => 'user@plnr.test',
            'city' => 'Austin',
            'interests' => ['live music'],
        ]);
        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[weekend]',
        ]);
        $this->fakeWeekendEvents();

        $this->artisan('weekends:send', ['--email' => 'user@plnr.test'])->assertSuccessful();

        Mail::assertSent(WeekendRecommendationsMail::class, 1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && ($request['0']['title'] ?? null) === 'Your weekend picks'
            && ($request['0']['data']['screen'] ?? null) === 'weekend');

        $this->artisan('weekends:send', ['--email' => 'user@plnr.test'])->assertSuccessful();

        Mail::assertSent(WeekendRecommendationsMail::class, 1);
    }

    public function test_free_user_and_pro_user_without_a_city_are_skipped(): void
    {
        Mail::fake();
        User::factory()->create([
            'email' => 'free@plnr.test',
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        User::factory()->pro()->create([
            'email' => 'nocity@plnr.test',
            'city' => null,
            'interests' => ['jazz'],
        ]);

        $this->artisan('weekends:send')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_missing_push_token_still_sends_the_email(): void
    {
        Mail::fake();
        User::factory()->pro()->create([
            'email' => 'user@plnr.test',
            'city' => 'Austin',
            'interests' => ['live music'],
        ]);
        $this->fakeWeekendEvents();

        $this->artisan('weekends:send', ['--email' => 'user@plnr.test'])->assertSuccessful();

        Mail::assertSent(WeekendRecommendationsMail::class, 1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'exp.host'));
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
