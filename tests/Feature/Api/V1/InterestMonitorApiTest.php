<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\InterestMatch;
use App\Models\InterestScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterestMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_matches_city_interests_and_skips_empty_accounts(): void
    {
        $fan = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@plnr.test',
            'city' => 'Austin',
            'interests' => ['jazz', 'live music'],
        ]);
        User::factory()->create([
            'email' => 'empty@plnr.test',
            'city' => 'Austin',
            'interests' => [],
        ]);

        $jazz = Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'description' => 'A late set',
            'starts_at' => now()->addDay(),
            'url' => 'https://events.example/jazz',
        ]);
        $festival = Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Music Festival',
            'description' => 'Bands all afternoon',
            'starts_at' => now()->addDays(2),
            'url' => 'https://events.example/festival',
        ]);
        Event::factory()->create([
            'city' => 'Dallas',
            'title' => 'Jazz brunch',
            'description' => 'Jazz downtown',
            'starts_at' => now()->addDay(),
        ]);
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz last week',
            'description' => 'Already happened',
            'starts_at' => now()->subDay(),
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        $scan = InterestScan::query()->first();
        $this->assertNotNull($scan);
        $this->assertSame(1, $scan->users_checked);
        $this->assertSame(2, $scan->matches_kept);
        $this->assertSame(1, $scan->users_skipped);
        $this->assertNotNull($scan->finished_at);

        $this->assertDatabaseHas('interest_matches', [
            'user_id' => $fan->id,
            'event_id' => $jazz->id,
            'matched_interest' => 'jazz',
        ]);
        $this->assertDatabaseHas('interest_matches', [
            'user_id' => $fan->id,
            'event_id' => $festival->id,
            'matched_interest' => 'live music',
        ]);
        $this->assertSame(2, InterestMatch::query()->count());

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/interest-matches?search=ada@plnr.test')
            ->assertOk()
            ->assertJsonPath('data.scan.matches_kept', 2)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonFragment(['matched_interest' => 'jazz'])
            ->assertJsonFragment([
                'title' => 'Jazz at the Continental',
                'url' => 'https://events.example/jazz',
            ])
            ->assertJsonMissing([
                'title' => 'Jazz brunch',
            ]);
    }

    public function test_second_scan_replaces_previous_matches(): void
    {
        $fan = User::factory()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        $jazz = Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'description' => 'A late set',
            'starts_at' => now()->addDay(),
        ]);

        $this->artisan('interests:scan')->assertSuccessful();
        $firstId = InterestMatch::query()->where('event_id', $jazz->id)->value('id');
        $this->assertNotNull($firstId);

        $jazz->update([
            'title' => 'Pottery workshop',
            'description' => 'Clay and wheels',
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        $this->assertDatabaseMissing('interest_matches', ['id' => $firstId]);
        $this->assertDatabaseMissing('interest_matches', [
            'user_id' => $fan->id,
            'event_id' => $jazz->id,
        ]);
        $this->assertSame(0, InterestMatch::query()->count());
        $this->assertSame(0, InterestScan::query()->latest('id')->value('matches_kept'));
    }

    public function test_scan_keeps_at_most_five_matches_per_account(): void
    {
        $fan = User::factory()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);

        foreach (range(1, 6) as $index) {
            Event::factory()->create([
                'city' => 'Austin',
                'title' => "Jazz night {$index}",
                'description' => 'A jazz set',
                'starts_at' => now()->addDay()->addHours($index),
            ]);
        }

        $this->artisan('interests:scan')->assertSuccessful();

        $this->assertSame(5, InterestMatch::query()->where('user_id', $fan->id)->count());
    }

    public function test_admin_can_scan_now_and_non_admin_is_forbidden(): void
    {
        User::factory()->create([
            'city' => 'Austin',
            'interests' => ['jazz'],
        ]);
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'description' => 'A late set',
            'starts_at' => now()->addDay(),
        ]);

        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/admin/interest-matches')->assertForbidden();
        $this->postJson('/api/v1/admin/interest-scans')->assertForbidden();
        $this->assertSame(0, InterestScan::query()->count());

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/interest-scans')
            ->assertOk()
            ->assertJsonPath('data.scan.matches_kept', 1)
            ->assertJsonPath('message', 'Interest scan finished.');

        $this->assertSame(1, InterestMatch::query()->count());
    }
}
