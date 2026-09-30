<?php

namespace Tests\Feature\Places;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\AI\AiChatClient;
use App\Services\AI\ItineraryService;
use App\Services\Places\PlaceListingLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceListingLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-02 18:00:00');
    }

    public function test_a_matched_listing_adds_a_photo_address_hours_and_keeps_existing_links(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'searchText')) {
                if (str_contains($request->body(), 'Missing Place')) {
                    return Http::response(['places' => []], 200);
                }

                return Http::response([
                    'places' => [[
                        'displayName' => ['text' => 'Elephant Room'],
                        'photos' => [[
                            'name' => 'places/ChIJelephant/photos/AUacShh3photo',
                        ]],
                        'regularOpeningHours' => [
                            'weekdayDescriptions' => [
                                'Monday: Closed',
                                'Friday: 5 PM–12 AM',
                            ],
                        ],
                        'websiteUri' => 'https://elephantroom.com',
                        'googleMapsUri' => 'https://maps.google.com/?cid=elephant',
                    ]],
                ]);
            }

            if (str_contains($url, '/media')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('missing', 404);
        });

        $this->app->instance(AiChatClient::class, new class implements AiChatClient
        {
            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                return [
                    'title' => 'Night in Austin',
                    'summary' => 'Jazz and a late bite',
                    'stops' => [
                        [
                            'time' => '8:00 PM',
                            'name' => 'Elephant Room',
                            'activity' => 'Live jazz',
                            'notes' => 'Downstairs',
                            'maps_url' => 'https://maps.example.com/kept',
                            'external_url' => 'https://example.com/kept',
                        ],
                        [
                            'time' => '10:00 PM',
                            'name' => 'Missing Place',
                            'activity' => 'A walk',
                            'notes' => '',
                        ],
                    ],
                ];
            }
        });

        $session = $this->sessionIn('Austin');
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_content' => null,
        ]);

        $draft = app(ItineraryService::class)->draft($session, $suggestion);
        $stops = $draft->itinerary_content['stops'];

        $this->assertStringContainsString('/api/v1/place-photos/', $stops[0]['photo_url']);
        $this->assertStringNotContainsString('test-places-key', $stops[0]['photo_url']);
        $this->assertStringNotContainsString('places.googleapis.com', $stops[0]['photo_url']);
        $this->assertSame('Friday: 5 PM–12 AM', $stops[0]['hours']);
        $this->assertSame('https://maps.example.com/kept', $stops[0]['maps_url']);
        $this->assertSame('https://example.com/kept', $stops[0]['external_url']);
        $this->assertArrayNotHasKey('photo_url', $stops[1]);
        $this->assertArrayNotHasKey('hours', $stops[1]);
        $this->assertArrayNotHasKey('maps_url', $stops[1]);

        $path = parse_url($stops[0]['photo_url'], PHP_URL_PATH);
        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'searchText')) {
                return false;
            }

            return str_contains($request->body(), 'Elephant Room, Austin')
                && ! str_contains($request->url(), 'test-places-key')
                && $request->hasHeader('X-Goog-Api-Key');
        });
    }

    public function test_a_stop_with_no_existing_maps_link_receives_the_listing_link(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'places' => [[
                    'displayName' => ['text' => 'Elephant Room'],
                    'googleMapsUri' => 'https://maps.google.com/?cid=elephant',
                    'websiteUri' => 'https://elephantroom.com',
                ]],
            ]),
        ]);

        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [[
                'time' => '8:00 PM',
                'name' => 'Elephant Room',
                'activity' => 'Live jazz',
                'notes' => '',
            ]],
        ], 'Austin');

        $this->assertSame('https://maps.google.com/?cid=elephant', $content['stops'][0]['maps_url']);
        $this->assertSame('https://elephantroom.com', $content['stops'][0]['external_url']);
        $this->assertArrayNotHasKey('photo_url', $content['stops'][0]);
    }

    public function test_no_key_and_a_miss_leave_the_stop_unchanged(): void
    {
        config(['services.google.places_key' => null]);
        Http::fake();

        $stop = [
            'time' => '8:00 PM',
            'name' => 'Elephant Room',
            'activity' => 'Live jazz',
            'notes' => '',
        ];
        $lookup = app(PlaceListingLookup::class);

        $withoutKey = $lookup->enrich(['stops' => [$stop]], 'Austin');

        $this->assertSame($stop, $withoutKey['stops'][0]);
        Http::assertNothingSent();

        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => []], 200),
        ]);

        $missed = app(PlaceListingLookup::class)->enrich(['stops' => [$stop]], 'Austin');

        $this->assertSame($stop, $missed['stops'][0]);
    }

    public function test_a_failed_lookup_leaves_that_stop_unchanged(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function (): void {
            throw new ConnectionException('timed out');
        });

        $stop = [
            'time' => '8:00 PM',
            'name' => 'Elephant Room',
            'activity' => 'Live jazz',
            'notes' => '',
        ];

        $content = app(PlaceListingLookup::class)->enrich(['stops' => [$stop]], 'Austin');

        $this->assertSame($stop, $content['stops'][0]);
    }

    private function sessionIn(string $city): PlanSession
    {
        $user = User::factory()->create();
        $planType = PlanType::factory()->create([
            'slug' => 'night_out',
            'label' => 'Plan My Night Out',
        ]);

        return PlanSession::factory()->create([
            'user_id' => $user->id,
            'plan_type_id' => $planType->id,
            'city' => $city,
        ]);
    }
}
