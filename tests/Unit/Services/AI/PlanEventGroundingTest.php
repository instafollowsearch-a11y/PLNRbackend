<?php

namespace Tests\Unit\Services\AI;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Services\AI\AnthropicClient;
use App\Services\AI\ItineraryService;
use App\Services\AI\PlanConsistency;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\AI\SuggestionService;
use App\Services\Events\PlanEventInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\Concerns\SeedsPlanEventCatalog;
use Tests\TestCase;

class PlanEventGroundingTest extends TestCase
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

    public function test_invalid_event_ids_are_stripped_and_catalog_titles_are_kept(): void
    {
        $events = $this->seedPlanEventCatalog();
        $this->fakeAnthropicSuggestions();

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'interests' => 'jazz and tacos',
                'group_size' => 4,
                'budget_per_person' => 65,
                'dates' => 'Saturday',
                'start_time' => '8:00 PM',
            ],
        ]);

        $suggestions = app(SuggestionService::class)->generate($session);

        $this->assertGreaterThanOrEqual(3, $suggestions->count());

        foreach ($suggestions as $suggestion) {
            $ids = $suggestion->payload['event_ids'] ?? [];
            $this->assertNotEmpty($ids);
            foreach ($ids as $id) {
                $this->assertTrue($events->contains('id', $id));
            }
        }

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! isset($body['tools']);
        });
    }

    public function test_itinerary_keeps_the_selected_event_and_drops_unmatched_fillers(): void
    {
        $events = $this->seedPlanEventCatalog();
        $event = $events->first();
        config(['services.google.places_key' => 'test-places-key']);

        $wrong = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'title' => 'A different night',
                    'summary' => 'Somewhere else.',
                    'stops' => [
                        ['time' => '6:00 PM', 'name' => 'Made Up Club', 'activity' => 'Drinks', 'notes' => ''],
                        ['time' => '8:30 PM', 'name' => 'Fake Kitchen', 'activity' => 'Dinner', 'notes' => ''],
                    ],
                ]),
            ]],
        ];

        Http::fake(function ($request) use ($wrong) {
            $url = $request->url();

            if (str_contains($url, 'anthropic.com') || str_contains($url, 'messages')) {
                return Http::response($wrong);
            }

            return Http::response(['places' => []], 200);
        });

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
            'answers' => ['city' => 'Austin', 'dates' => 'Saturday'],
        ]);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => [
                'name' => 'Jazz and Bites',
                'venues' => [$event->title],
                'event_ids' => [$event->id],
            ],
        ]);

        $itinerary = app(ItineraryService::class)->generate($session, $suggestion);
        $names = array_column($itinerary->content['stops'], 'name');

        $this->assertContains($event->title, $names);
        $this->assertSame($event->url, collect($itinerary->content['stops'])->firstWhere('name', $event->title)['venue_url'] ?? null);
        $this->assertNotContains('Made Up Club', $names);
        $this->assertNotContains('Fake Kitchen', $names);
        $this->assertTrue(PlanConsistency::usesAllPlaces($itinerary->content, [$event->title]));
    }

    public function test_suggestion_service_can_be_constructed_with_inventory(): void
    {
        $this->assertInstanceOf(
            SuggestionService::class,
            new SuggestionService(
                app(AnthropicClient::class),
                new PlanPromptBuilderResolver,
                app(PlanEventInventory::class),
            ),
        );
    }
}
