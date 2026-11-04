<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Services\Events\PlanEventInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\Concerns\SeedsPlanEventCatalog;
use Tests\TestCase;

class PlanEventInventoryTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;
    use SeedsPlanEventCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
            'services.events.sources' => ['fixture'],
        ]);
    }

    public function test_a_rich_catalog_still_scouts_and_merges_listings(): void
    {
        $this->seedPlanEventCatalog();
        $windowStart = strtolower((string) now()->englishDayOfWeek) === 'saturday'
            ? now()->startOfDay()
            : now()->next('saturday')->startOfDay();

        Http::fake([
            config('services.anthropic.url') => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'recommendations' => [[
                            'title' => 'Live listing',
                            'venue' => 'Stubb\'s',
                            'starts_at' => $windowStart->copy()->setTime(21, 0)->toIso8601String(),
                            'url' => 'https://eventbrite.com/e/live-listing',
                            'source' => 'eventbrite',
                        ]],
                    ]),
                ]],
            ]),
        ]);

        $rows = app(PlanEventInventory::class)->forSession($this->nightOutSession());
        $titles = array_column($rows, 'title');

        $this->assertGreaterThanOrEqual(PlanEventInventory::MIN_CATALOG, count($rows));
        $this->assertContains('Blue Note Bar', $titles);
        $this->assertContains('Live listing', $titles);
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return is_array($body)
                && ($body['tools'][0]['name'] ?? null) === 'web_search'
                && (int) ($body['tools'][0]['max_uses'] ?? 0) === 3;
        });
    }

    public function test_a_thin_catalog_scouts_with_three_web_searches(): void
    {
        $windowStart = now()->next('saturday')->startOfDay();
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'One jazz night',
            'starts_at' => $windowStart->copy()->setTime(20, 0),
        ]);

        Http::fake([
            config('services.anthropic.url') => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'recommendations' => [[
                            'title' => 'Live listing',
                            'venue' => 'Stubb\'s',
                            'starts_at' => $windowStart->copy()->setTime(21, 0)->toIso8601String(),
                            'url' => 'https://eventbrite.com/e/live-listing',
                            'source' => 'eventbrite',
                        ]],
                    ]),
                ]],
            ]),
        ]);

        $rows = app(PlanEventInventory::class)->forSession($this->nightOutSession());

        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => $row['title'] === 'Live listing'));
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return is_array($body)
                && ($body['tools'][0]['name'] ?? null) === 'web_search'
                && (int) ($body['tools'][0]['max_uses'] ?? 0) === 3;
        });
    }

    public function test_scout_hits_outside_the_date_window_are_discarded(): void
    {
        $windowStart = now()->next('saturday')->startOfDay();

        Http::fake([
            config('services.anthropic.url') => Http::response([
                'id' => 'msg_test',
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
                            'title' => 'This Saturday',
                            'venue' => 'Stubb\'s',
                            'starts_at' => $windowStart->copy()->setTime(21, 0)->toIso8601String(),
                            'url' => 'https://eventbrite.com/e/saturday',
                            'source' => 'eventbrite',
                        ]],
                    ]),
                ]],
            ]),
        ]);

        $rows = app(PlanEventInventory::class)->forSession($this->nightOutSession());
        $titles = array_column($rows, 'title');

        $this->assertContains('This Saturday', $titles);
        $this->assertNotContains('Last year', $titles);
    }

    private function nightOutSession(): PlanSession
    {
        $planType = PlanType::factory()->create(['slug' => 'night_out']);

        return PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'interests' => 'jazz',
                'dates' => 'Saturday',
            ],
        ]);
    }
}
