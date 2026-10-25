<?php

namespace Tests\Feature\Api\V1;

use App\Models\DevicePushToken;
use App\Models\Event;
use App\Models\InterestPing;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterestPingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_schedules_day_before_and_morning_pings_for_pro_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00'));

        $pro = User::factory()->pro()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        $free = User::factory()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);

        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'description' => 'A late set',
            'starts_at' => Carbon::parse('2026-10-22 20:00:00'),
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        $this->assertSame(2, InterestPing::query()->where('user_id', $pro->id)->count());
        $this->assertSame(0, InterestPing::query()->where('user_id', $free->id)->count());

        $dayBefore = InterestPing::query()
            ->where('user_id', $pro->id)
            ->where('kind', InterestPing::KIND_DAY_BEFORE)
            ->firstOrFail();
        $morning = InterestPing::query()
            ->where('user_id', $pro->id)
            ->where('kind', InterestPing::KIND_MORNING_OF)
            ->firstOrFail();

        $this->assertSame('jazz', $dayBefore->matched_interest);
        $this->assertNull($dayBefore->push_sent_at);
        $this->assertTrue($dayBefore->remind_at->equalTo(Carbon::parse('2026-10-21 10:00:00')));
        $this->assertTrue($morning->remind_at->equalTo(Carbon::parse('2026-10-22 10:00:00')));
    }

    public function test_same_day_event_only_schedules_morning_ping(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 14:00:00'));

        $pro = User::factory()->pro()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz tonight',
            'description' => 'Jazz set',
            'starts_at' => Carbon::parse('2026-10-25 20:00:00'),
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        $this->assertSame(1, InterestPing::query()->where('user_id', $pro->id)->count());
        $ping = InterestPing::query()->where('user_id', $pro->id)->firstOrFail();
        $this->assertSame(InterestPing::KIND_MORNING_OF, $ping->kind);
        $this->assertTrue($ping->remind_at->equalTo(now()));
    }

    public function test_send_pings_pushes_once_with_interest_screen_payload(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'ok']]]),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00'));

        $user = User::factory()->pro()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[interest-ping]',
        ]);

        $event = Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz Night Out',
            'description' => 'Jazz downtown',
            'starts_at' => Carbon::parse('2026-10-22 19:00:00'),
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        $ping = InterestPing::query()
            ->where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->where('kind', InterestPing::KIND_DAY_BEFORE)
            ->firstOrFail();
        Carbon::setTestNow($ping->remind_at);

        $this->artisan('interests:send-pings')->assertSuccessful();
        $this->assertNotNull($ping->fresh()->push_sent_at);

        Http::assertSent(function ($request) use ($event): bool {
            if ($request->url() !== 'https://exp.host/--/api/v2/push/send') {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, 'interest-ping')
                && str_contains($body, 'interest_pings')
                && str_contains($body, 'Jazz Night Out')
                && str_contains($body, 'Tomorrow')
                && str_contains($body, (string) $event->id);
        });

        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'ok']]]),
        ]);

        $this->artisan('interests:send-pings')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_pro_can_list_own_interest_matches_and_free_is_forbidden(): void
    {
        $pro = User::factory()->pro()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'description' => 'A late set',
            'starts_at' => now()->addDay(),
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        Sanctum::actingAs($pro);
        $this->getJson('/api/v1/interest-matches')
            ->assertOk()
            ->assertJsonPath('data.matches.0.matched_interest', 'jazz')
            ->assertJsonPath('data.matches.0.event.title', 'Jazz at the Continental');

        $free = User::factory()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        Sanctum::actingAs($free);
        $this->getJson('/api/v1/interest-matches')->assertForbidden();
    }
}
