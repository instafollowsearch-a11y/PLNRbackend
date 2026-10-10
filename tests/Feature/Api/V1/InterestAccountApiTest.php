<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\InterestMatch;
use App\Models\InterestScan;
use App\Models\PlanSession;
use App\Models\User;
use App\Models\WeekendRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterestAccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_saved_interests_and_plan_mentions(): void
    {
        $fan = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@plnr.test',
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        PlanSession::factory()->create([
            'user_id' => $fan->id,
            'city' => 'Austin',
            'answers' => ['interests' => 'Live jazz'],
        ]);
        WeekendRecommendation::query()->create([
            'user_id' => $fan->id,
            'city' => 'Austin',
            'interests' => ['jazz'],
            'window_start' => now(),
            'window_end' => now()->addDays(2),
            'items' => [],
        ]);
        $event = Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'starts_at' => now()->addDay(),
            'url' => 'https://events.example/jazz',
        ]);
        $scan = InterestScan::query()->create([
            'started_at' => now(),
            'finished_at' => now(),
            'users_checked' => 1,
            'matches_kept' => 1,
            'users_skipped' => 1,
        ]);
        InterestMatch::query()->create([
            'interest_scan_id' => $scan->id,
            'user_id' => $fan->id,
            'event_id' => $event->id,
            'matched_interest' => 'jazz',
            'score' => 1,
        ]);

        User::factory()->create([
            'name' => 'No City',
            'email' => 'nocity@plnr.test',
            'city' => null,
            'interests' => ['comedy'],
        ]);

        Sanctum::actingAs(User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@plnr.test',
            'city' => null,
            'interests' => [],
        ]));

        $this->getJson('/api/v1/admin/interest-accounts')
            ->assertOk()
            ->assertJsonPath('data.summary.with_saved_interests', 2)
            ->assertJsonPath('data.summary.ready', 1)
            ->assertJsonPath('data.summary.matched', 1)
            ->assertJsonPath('data.summary.skipped', 2);

        $this->getJson('/api/v1/admin/interest-accounts?search=ada@plnr.test')
            ->assertOk()
            ->assertJsonPath('data.accounts.0.saved_interests.0', 'jazz')
            ->assertJsonPath('data.accounts.0.plan_interests.0.label', 'Live jazz')
            ->assertJsonPath('data.accounts.0.plan_interests.0.city', 'Austin')
            ->assertJsonPath('data.accounts.0.weekend_interests.0', 'jazz')
            ->assertJsonPath('data.accounts.0.status', 'matched')
            ->assertJsonPath('data.accounts.0.matches.0.event.title', 'Jazz at the Continental');

        $this->getJson('/api/v1/admin/interest-accounts?search=nocity@plnr.test')
            ->assertOk()
            ->assertJsonPath('data.accounts.0.status', 'skipped')
            ->assertJsonPath('data.accounts.0.skip_reason', 'no_city');

        $this->getJson('/api/v1/admin/interest-accounts?status=skipped')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_non_admin_cannot_list_interest_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/interest-accounts')->assertForbidden();
    }
}
