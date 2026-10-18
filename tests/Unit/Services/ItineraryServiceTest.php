<?php

namespace Tests\Unit\Services;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Services\AI\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class ItineraryServiceTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);
    }

    public function test_generates_itinerary_from_anthropic_fixture(): void
    {
        $this->fakeAnthropicItinerary();

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_SELECTED,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'group_size' => 4,
                'budget_per_person' => 65,
                'dates' => 'Saturday',
                'start_time' => '8:00 PM',
            ],
        ]);

        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'selected_at' => now(),
            'payload' => [
                'name' => 'Jazz and Bites',
                'description' => 'Cocktails and jazz',
                'time_slot' => '8:00 PM – 12:30 AM',
                'venues' => ['Blue Note Bar'],
            ],
        ]);

        $service = app(ItineraryService::class);
        $itinerary = $service->generate($session, $suggestion);

        $this->assertSame('Saturday Night Out in Austin', $itinerary->content['title']);
        $this->assertCount(3, $itinerary->content['stops']);
        $this->assertSame(PlanSession::STATUS_ITINERARY, $session->fresh()->status);

        $this->assertSame(1, $this->itineraryCallCount());
    }

    public function test_a_draft_that_misses_the_picked_places_is_still_saved(): void
    {
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
                        ['time' => '6:00 PM', 'name' => 'Other Club', 'activity' => 'Drinks', 'notes' => ''],
                        ['time' => '8:30 PM', 'name' => 'Far Restaurant', 'activity' => 'Dinner', 'notes' => ''],
                    ],
                ]),
            ]],
        ];

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($wrong),
            config('services.anthropic.url') => Http::response($wrong),
        ]);

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
            'answers' => ['city' => 'Austin'],
        ]);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => [
                'name' => 'Jazz and Bites',
                'venues' => ['Blue Note Bar', 'Taco Row'],
            ],
        ]);

        $itinerary = app(ItineraryService::class)->generate($session, $suggestion);

        $this->assertSame('Blue Note Bar', $itinerary->content['stops'][0]['name']);
        $this->assertSame('Taco Row', $itinerary->content['stops'][1]['name']);
        $this->assertSame('7:15 PM', $itinerary->content['stops'][1]['time']);
        $this->assertSame(1, $this->itineraryCallCount());
    }

    public function test_the_picked_place_is_photographed_and_the_trip_zone_is_saved(): void
    {
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
                        ['time' => '6:00 PM', 'name' => 'Other Club', 'activity' => 'Drinks', 'notes' => ''],
                        ['time' => '8:30 PM', 'name' => 'Far Restaurant', 'activity' => 'Dinner', 'notes' => ''],
                    ],
                ]),
            ]],
        ];

        Http::fake(function ($request) use ($wrong) {
            $url = $request->url();

            if (str_contains($url, 'anthropic.com') || str_contains($url, 'messages')) {
                return Http::response($wrong);
            }

            if (str_contains($url, '/media')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            if (! str_contains($request->body(), 'Blue Note Bar')) {
                return Http::response(['places' => []], 200);
            }

            return Http::response([
                'places' => [[
                    'displayName' => ['text' => 'Blue Note Bar'],
                    'photos' => [[
                        'name' => 'places/ChIJbluenote/photos/AUacShh3photo',
                    ]],
                    'location' => [
                        'latitude' => 30.2672,
                        'longitude' => -97.7431,
                    ],
                    'formattedAddress' => '1 Congress Ave, Austin, TX 78701',
                ]],
            ]);
        });

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
            'answers' => ['city' => 'Austin'],
        ]);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => [
                'name' => 'Jazz and Bites',
                'venues' => ['Blue Note Bar', 'Taco Row'],
            ],
        ]);

        $itinerary = app(ItineraryService::class)->generate($session, $suggestion);

        $this->assertSame('Blue Note Bar', $itinerary->content['stops'][0]['name']);
        $this->assertStringContainsString('/api/v1/place-photos/', $itinerary->content['stops'][0]['photo_url']);
        $this->assertArrayNotHasKey('photo_url', $itinerary->content['stops'][1]);
        $this->assertSame('America/Chicago', $session->fresh()->answers['timezone']);

        $path = parse_url($itinerary->content['stops'][0]['photo_url'], PHP_URL_PATH);
        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(1, $this->itineraryCallCount());
    }

    public function test_draft_is_reused_and_copied_without_another_model_call(): void
    {
        $this->fakeAnthropicItinerary();

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_SUGGESTIONS,
            'user_id' => null,
            'city' => 'Austin',
            'answers' => ['city' => 'Austin'],
        ]);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => [
                'name' => 'Jazz and Bites',
                'description' => 'Cocktails and jazz',
                'venues' => ['Blue Note Bar'],
            ],
        ]);
        $untouched = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => ['name' => 'Other night', 'description' => 'Still here'],
        ]);

        $service = app(ItineraryService::class);
        $drafted = $service->draft($session, $suggestion);
        $service->draft($session, $drafted);

        $this->assertSame('Saturday Night Out in Austin', $drafted->itinerary_content['title']);
        $this->assertSame(PlanSession::STATUS_SUGGESTIONS, $session->fresh()->status);
        $this->assertNull($session->fresh()->itinerary);
        $this->assertSame('Other night', $untouched->fresh()->payload['name']);

        $itinerary = $service->generate($session->fresh(), $drafted->fresh());

        $this->assertSame('Saturday Night Out in Austin', $itinerary->content['title']);
        $this->assertSame(PlanSession::STATUS_ITINERARY, $session->fresh()->status);
        $this->assertSame(1, $this->itineraryCallCount());
    }

    private function itineraryCallCount(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->body(), 'Create a detailed evening itinerary'))
            ->count();
    }
}
