<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\InterestMatch;
use App\Models\InterestScan;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use App\Services\Events\CityListingInventory;
use App\Services\Interests\ScanUserInterests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterestMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.events.sources' => [],
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);
    }

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

    public function test_street_address_city_matches_austin_live_music(): void
    {
        $fan = User::factory()->create([
            'name' => 'Dawit Mogessie',
            'email' => 'dterefe0@gmail.com',
            'city' => '510 Guadalupe Street, Austin, Texas',
            'interests' => ['Live music', 'Comedy shows'],
        ]);
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Jazz at the Continental',
            'description' => 'A late set',
            'starts_at' => now()->addDay(),
            'url' => 'https://events.example/jazz',
        ]);

        $this->artisan('interests:scan')->assertSuccessful();

        $this->assertDatabaseHas('interest_matches', [
            'user_id' => $fan->id,
            'matched_interest' => 'Live music',
        ]);
    }

    public function test_scan_scouts_once_per_city_and_skips_open_to_suggestions(): void
    {
        Http::fake(fn () => Http::response([
            'id' => 'msg_interest',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'recommendations' => [[
                        'title' => 'Last year',
                        'venue' => 'Nowhere',
                        'starts_at' => now()->subYear()->toIso8601String(),
                        'url' => 'https://eventbrite.com/e/old',
                        'source' => 'eventbrite',
                    ], [
                        'title' => 'Harbor Concert',
                        'venue' => 'Harbor Stage',
                        'starts_at' => now()->addDays(2)->toIso8601String(),
                        'url' => 'https://eventbrite.com/e/harbor-concert',
                        'source' => 'eventbrite',
                    ]],
                ]),
            ]],
        ]));

        User::factory()->create([
            'city' => 'Austin',
            'interests' => ['Live music'],
        ]);
        User::factory()->create([
            'city' => 'Austin, Texas',
            'interests' => ['Live music'],
        ]);
        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $addis = User::factory()->create([
            'city' => 'Austin',
            'interests' => ['Theater'],
        ]);
        PlanSession::factory()->create([
            'user_id' => $addis->id,
            'plan_type_id' => $planType->id,
            'city' => 'Addis Ababa',
            'answers' => [
                'city' => 'Addis Ababa',
                'interests' => 'Club / nightlife',
            ],
        ]);

        Cache::flush();
        app(ScanUserInterests::class)->run();

        $this->assertDatabaseHas('events', [
            'city' => 'Austin',
            'title' => 'Harbor Concert',
        ]);
        $this->assertDatabaseMissing('events', [
            'title' => 'Last year',
        ]);
        $this->assertSame(1, collect(Http::recorded())->filter(function (array $pair): bool {
            $body = json_decode((string) $pair[0]->body(), true);

            return is_array($body) && ($body['tools'][0]['name'] ?? null) === 'web_search';
        })->count());
        $this->assertDatabaseMissing('interest_matches', [
            'matched_interest' => 'Club / nightlife',
        ]);
        $this->assertGreaterThanOrEqual(2, InterestMatch::query()->where('matched_interest', 'Live music')->count());
    }

    public function test_city_listing_inventory_normalizes_street_addresses(): void
    {
        $this->assertSame(
            'Austin',
            app(CityListingInventory::class)->cityKey('510 Guadalupe Street, Austin, Texas'),
        );
    }
}
