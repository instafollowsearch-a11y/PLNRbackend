<?php

namespace Tests\Feature\Places;

use App\Models\Itinerary;
use App\Models\PlacePhoto;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\AI\AiChatClient;
use App\Services\AI\ItineraryService;
use App\Services\AI\Prompts\NightOutPromptBuilder;
use App\Services\Places\AreaLimit;
use App\Services\Places\PlaceListingLookup;
use App\Services\Places\RoadTripDriveEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceListingLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-02 18:00:00');
        config(['services.anthropic.key' => null]);
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
        $this->assertSame('Friday: 5 PM–12 AM', $stops[1]['hours']);
        $this->assertArrayNotHasKey('maps_url', $stops[1]);

        $path = parse_url($stops[0]['photo_url'], PHP_URL_PATH);
        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertDatabaseCount('place_photos', 1);
        Cache::flush();

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

    public function test_a_saved_plan_reloads_its_photo_when_the_id_was_lost(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response([
                'places' => [[
                    'photos' => [[
                        'name' => 'places/ChIJelephant/photos/AUacShh3photo',
                    ]],
                ]],
            ]);
        });

        $token = 'dqLdzwOtbEPw9VXBCTS1MmDRfRaiAg3P';
        $session = $this->sessionIn('Austin');
        Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'content' => [
                'title' => 'Night in Austin',
                'stops' => [[
                    'time' => '8:00 PM',
                    'name' => 'Elephant Room',
                    'activity' => 'Live jazz',
                    'notes' => '',
                    'photo_url' => url('/api/v1/place-photos/'.$token),
                ]],
            ],
        ]);

        $response = $this->get('/api/v1/place-photos/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertDatabaseHas('place_photos', [
            'token' => $token,
            'photo_name' => 'places/ChIJelephant/photos/AUacShh3photo',
        ]);

        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'searchText')) {
                return Http::response('should-not-search', 500);
            }

            return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
        });

        $this->get('/api/v1/place-photos/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_an_unknown_photo_token_stays_missing(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake();

        $this->get('/api/v1/place-photos/notarealtoken')
            ->assertNotFound()
            ->assertHeader('X-Plnr-Photo', 'missing');

        Http::assertNothingSent();
    }

    public function test_a_draft_reloads_its_photo_when_the_itinerary_was_not_saved(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/media')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response([
                'places' => [[
                    'photos' => [[
                        'name' => 'places/ChIJdraft/photos/AUacShh3photo',
                    ]],
                ]],
            ]);
        });

        $token = 'jx7zCgWqOCHYvB82hub3opaK659AW0fZ';
        $session = $this->sessionIn('Atlanta, Georgia');
        Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_content' => [
                'stops' => [[
                    'name' => "Dad's Garage Theatre",
                    'photo_url' => url('/api/v1/place-photos/'.$token),
                ]],
            ],
        ]);

        $this->get('/api/v1/place-photos/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'searchText')
                && str_contains($request->body(), "Dad's Garage Theatre, Atlanta, Georgia");
        });
    }

    public function test_a_rejected_saved_photo_is_loaded_again_from_the_place(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        $mediaCalls = 0;
        Http::fake(function ($request) use (&$mediaCalls) {
            if (str_contains($request->url(), '/media')) {
                $mediaCalls++;

                if ($mediaCalls === 1) {
                    return Http::response([
                        'error' => ['message' => 'Request contains an invalid argument.'],
                    ], 400);
                }

                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response([
                'places' => [[
                    'photos' => [[
                        'name' => 'places/ChIJfresh/photos/AUacShh3photo',
                    ]],
                ]],
            ]);
        });

        $token = 'aDGlROLDTgGNShC2wrDv1W7PmvyxPRjm';
        $session = $this->sessionIn('Austin');
        Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'content' => [
                'stops' => [[
                    'name' => 'Elephant Room',
                    'photo_url' => url('/api/v1/place-photos/'.$token),
                ]],
            ],
        ]);
        PlacePhoto::query()->create([
            'token' => $token,
            'photo_name' => 'places/ChIJold/photos/stalephoto',
        ]);

        $this->get('/api/v1/place-photos/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertDatabaseHas('place_photos', [
            'token' => $token,
            'photo_name' => 'places/ChIJfresh/photos/AUacShh3photo',
        ]);
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
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
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
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'places.googleapis.com'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'nominatim.openstreetmap.org'));

        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
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

    public function test_a_permanently_closed_place_is_removed_and_a_temporary_closure_stays(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            $body = $request->body();

            if (str_contains($body, 'Closed Bar')) {
                return Http::response([
                    'places' => [[
                        'displayName' => ['text' => 'Closed Bar'],
                        'businessStatus' => 'CLOSED_PERMANENTLY',
                    ]],
                ]);
            }

            return Http::response([
                'places' => [[
                    'displayName' => ['text' => 'Paused Cafe'],
                    'businessStatus' => 'CLOSED_TEMPORARILY',
                    'formattedAddress' => 'Austin, TX, USA',
                ]],
            ]);
        });

        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [
                ['time' => '8:00 PM', 'name' => 'Closed Bar', 'activity' => 'Drinks', 'notes' => ''],
                ['time' => '9:00 PM', 'name' => 'Paused Cafe', 'activity' => 'Coffee', 'notes' => ''],
            ],
        ], 'Austin');

        $this->assertCount(1, $content['stops']);
        $this->assertSame('Paused Cafe', $content['stops'][0]['name']);
        $this->assertArrayNotHasKey('address', $content['stops'][0]);
    }

    public function test_removing_every_stop_keeps_the_original_plan(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'places' => [[
                    'displayName' => ['text' => 'Closed Bar'],
                    'businessStatus' => 'CLOSED_PERMANENTLY',
                ]],
            ]),
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $stops = [
            ['time' => '8:00 PM', 'name' => 'Closed Bar', 'activity' => 'Drinks', 'notes' => ''],
        ];

        $content = app(PlaceListingLookup::class)->enrich(['stops' => $stops], 'Austin');

        $this->assertSame($stops, $content['stops']);
    }

    public function test_a_street_address_is_saved_and_a_stop_outside_the_radius_is_removed(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'places.googleapis.com')) {
                return Http::response([], 200);
            }

            $body = $request->body();
            $this->assertStringContainsString('locationBias', $body);

            if (str_contains($body, 'Near Bar')) {
                return Http::response([
                    'places' => [[
                        'displayName' => ['text' => 'Near Bar'],
                        'businessStatus' => 'OPERATIONAL',
                        'formattedAddress' => '12 Rainey St, Austin, TX 78701',
                        'location' => ['latitude' => 30.260, 'longitude' => -97.738],
                    ]],
                ]);
            }

            return Http::response([
                'places' => [[
                    'displayName' => ['text' => 'Far Bar'],
                    'businessStatus' => 'OPERATIONAL',
                    'formattedAddress' => '900 Far Rd, Austin, TX 78701',
                    'location' => ['latitude' => 30.400, 'longitude' => -97.900],
                ]],
            ]);
        });

        $area = new AreaLimit('East Austin', 30.260, -97.738, 1);
        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [
                ['time' => '8:00 PM', 'name' => 'Near Bar', 'activity' => 'Drinks', 'notes' => ''],
                ['time' => '9:00 PM', 'name' => 'Far Bar', 'activity' => 'Drinks', 'notes' => ''],
            ],
        ], 'Austin', $area);

        $this->assertCount(1, $content['stops']);
        $this->assertSame('12 Rainey St, Austin, TX 78701', $content['stops'][0]['address']);
        $this->assertArrayNotHasKey('latitude', $content['stops'][0]);
    }

    public function test_road_trip_drive_time_and_gas_replace_the_guess(): void
    {
        config([
            'services.google.places_key' => 'test-places-key',
            'services.google.gas_price_per_gallon' => 3.5,
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'computeRoutes')) {
                return Http::response([
                    'routes' => [[
                        'duration' => '5400s',
                        'distanceMeters' => 16093,
                    ]],
                ]);
            }

            $near = str_contains($request->body(), 'Start Diner');

            return Http::response([
                'places' => [[
                    'displayName' => ['text' => $near ? 'Start Diner' : 'End Lookout'],
                    'businessStatus' => 'OPERATIONAL',
                    'formattedAddress' => $near ? '1 Main St, Austin, TX 78701' : '2 Hill Rd, Austin, TX 78701',
                    'location' => [
                        'latitude' => $near ? 30.27 : 30.40,
                        'longitude' => $near ? -97.74 : -97.70,
                    ],
                ]],
            ]);
        });

        $this->app->instance(AiChatClient::class, new class implements AiChatClient
        {
            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                return [
                    'title' => 'Austin drive',
                    'summary' => 'One leg',
                    'stops' => [
                        ['time' => '9:00 AM', 'name' => 'Start Diner', 'activity' => 'Breakfast', 'notes' => ''],
                        ['time' => '11:00 AM', 'name' => 'End Lookout', 'activity' => 'View', 'notes' => ''],
                    ],
                ];
            }
        });

        $session = $this->sessionIn('Austin', 'road_trip', ['car_type' => 'Sedan']);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_content' => null,
            'payload' => [
                'name' => 'Hill drive',
                'description' => 'Out and around',
                'estimated_gas_cost' => 80,
                'total_drive_time' => '6h',
                'stops' => [],
            ],
        ]);

        $draft = app(ItineraryService::class)->draft($session, $suggestion);

        $this->assertSame('1h 30m', $draft->itinerary_content['total_drive_time']);
        $this->assertEqualsWithDelta(1.17, $draft->itinerary_content['estimated_gas_cost'], 0.02);
        $this->assertSame('1h 30m', $draft->payload['total_drive_time']);
        $this->assertEqualsWithDelta(1.17, $draft->payload['estimated_gas_cost'], 0.02);
        $this->assertSame('1 Main St, Austin, TX 78701', $draft->itinerary_content['stops'][0]['address']);
        $this->assertArrayNotHasKey('latitude', $draft->itinerary_content['stops'][0]);
    }

    public function test_an_electric_car_has_no_gas_cost(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'duration' => '3600s',
                    'distanceMeters' => 16093,
                ]],
            ]),
        ]);

        $content = app(RoadTripDriveEstimate::class)->apply([
            'stops' => [
                ['name' => 'A', 'latitude' => 30.2, 'longitude' => -97.7],
                ['name' => 'B', 'latitude' => 30.3, 'longitude' => -97.8],
            ],
        ], 'Electric');

        $this->assertSame(0.0, $content['estimated_gas_cost']);
        $this->assertSame('1h', $content['total_drive_time']);
        $this->assertArrayNotHasKey('latitude', $content['stops'][0]);
    }

    public function test_a_failed_route_keeps_the_guessed_drive_time(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'computeRoutes')) {
                return Http::response(['error' => 'down'], 500);
            }

            return Http::response([
                'places' => [[
                    'displayName' => ['text' => 'Stop'],
                    'businessStatus' => 'OPERATIONAL',
                    'formattedAddress' => '1 Main St, Austin, TX 78701',
                    'location' => ['latitude' => 30.27, 'longitude' => -97.74],
                ]],
            ]);
        });

        $this->app->instance(AiChatClient::class, new class implements AiChatClient
        {
            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                return [
                    'title' => 'Austin drive',
                    'summary' => 'One leg',
                    'stops' => [
                        ['time' => '9:00 AM', 'name' => 'Start Diner', 'activity' => 'Breakfast', 'notes' => ''],
                        ['time' => '11:00 AM', 'name' => 'End Lookout', 'activity' => 'View', 'notes' => ''],
                    ],
                ];
            }
        });

        $session = $this->sessionIn('Austin', 'road_trip', ['car_type' => 'Electric']);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_content' => null,
            'payload' => [
                'name' => 'Hill drive',
                'description' => 'Out and around',
                'estimated_gas_cost' => 80,
                'total_drive_time' => '6h',
            ],
        ]);

        $draft = app(ItineraryService::class)->draft($session, $suggestion);

        $this->assertArrayNotHasKey('total_drive_time', $draft->itinerary_content);
        $this->assertSame('6h', $draft->payload['total_drive_time']);
        $this->assertSame(80, $draft->payload['estimated_gas_cost']);
    }

    public function test_saying_no_to_one_area_does_not_limit_the_search(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $limit = AreaLimit::fromAnswers([
            'stay_in_area' => 'No',
            'area_radius_miles' => '3',
            'area_center' => json_encode(['label' => 'East Austin', 'lat' => 30.26, 'lon' => -97.74]),
        ]);

        $this->assertNull($limit);

        app(PlaceListingLookup::class)->enrich([
            'stops' => [
                ['time' => '8:00 PM', 'name' => 'Any Bar', 'activity' => 'Drinks', 'notes' => ''],
            ],
        ], 'Austin', $limit);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'searchText')
                && ! str_contains($request->body(), 'locationBias');
        });
    }

    public function test_openstreetmap_hours_fill_a_stop_google_could_not_time(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'name' => 'Elephant Room',
                    'display_name' => 'Elephant Room, Austin, Texas',
                    'extratags' => ['opening_hours' => 'Fr 17:00-00:00'],
                ],
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

        $this->assertSame('Friday: 5 PM–12 AM', $content['stops'][0]['hours']);
    }

    public function test_claude_web_check_fills_hours_when_the_maps_do_not(): void
    {
        config([
            'services.google.places_key' => null,
            'services.anthropic.key' => 'test-anthropic-key',
        ]);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'name' => 'Quiet Bar',
                    'display_name' => 'Quiet Bar, Austin, Texas',
                ],
            ]),
        ]);
        $this->app->instance(AiChatClient::class, new class implements AiChatClient
        {
            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                if (($options['web_search'] ?? false) !== true) {
                    return [];
                }

                return [
                    'places' => [
                        ['name' => 'Quiet Bar', 'hours' => 'Friday: 6 PM–2 AM'],
                    ],
                ];
            }
        });

        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [[
                'time' => '9:00 PM',
                'name' => 'Quiet Bar',
                'activity' => 'A drink',
                'notes' => '',
            ]],
        ], 'Austin');

        $this->assertSame('Friday: 6 PM–2 AM', $content['stops'][0]['hours']);
        $this->assertSame('9:00 PM', $content['stops'][0]['time']);
    }

    public function test_a_stop_with_no_hours_copies_a_similar_place_in_the_city(): void
    {
        config(['services.google.places_key' => 'test-places-key']);
        Http::fake(function ($request) {
            $query = (string) ($request['q'] ?? '');

            if (str_contains($query, 'Elephant Room')) {
                return Http::response([
                    [
                        'name' => 'Elephant Room',
                        'display_name' => 'Elephant Room, Austin, Texas',
                    ],
                ]);
            }

            return Http::response([
                [
                    'name' => 'Other Jazz Bar',
                    'display_name' => 'Other Jazz Bar, Austin, Texas',
                    'extratags' => ['opening_hours' => 'Fr 18:00-02:00'],
                ],
            ]);
        });

        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [[
                'time' => '8:00 PM',
                'name' => 'Elephant Room',
                'activity' => 'Live jazz',
                'notes' => '',
            ]],
        ], 'Austin');

        $this->assertSame('Friday: 6 PM–2 AM', $content['stops'][0]['hours']);
    }

    public function test_a_stop_with_no_listing_reuses_hours_from_another_stop(): void
    {
        config(['services.google.places_key' => null]);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [
                [
                    'time' => '8:00 PM',
                    'name' => 'Elephant Room',
                    'activity' => 'Live jazz',
                    'notes' => '',
                    'hours' => 'Friday: 5 PM–12 AM',
                ],
                [
                    'time' => '10:00 PM',
                    'name' => 'Missing Place',
                    'activity' => 'A walk',
                    'notes' => '',
                ],
            ],
        ], 'Austin');

        $this->assertSame('Friday: 5 PM–12 AM', $content['stops'][1]['hours']);
    }

    public function test_the_night_prompt_tells_the_model_to_stay_inside_the_radius(): void
    {
        $builder = new NightOutPromptBuilder;
        $session = $this->sessionIn('Austin', 'night_out', [
            'stay_in_area' => 'Yes',
            'area_radius_miles' => '3',
            'area_center' => json_encode(['label' => 'East Austin', 'lat' => 30.26, 'lon' => -97.74]),
        ]);

        $this->assertStringContainsString(
            'Stay within 3 miles of East Austin.',
            $builder->suggestionUserPrompt($session),
        );

        $open = $this->sessionIn('Austin', 'night_out', ['stay_in_area' => 'No']);

        $this->assertStringNotContainsString('Stay within', $builder->suggestionUserPrompt($open));
    }

    public function test_a_web_search_photo_is_kept_and_a_private_address_is_ignored(): void
    {
        config([
            'services.google.places_key' => null,
            'services.anthropic.key' => 'test-anthropic-key',
        ]);
        $jpeg = "\xFF\xD8\xFF".str_repeat('a', 240);
        Http::fake([
            'example.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->app->instance(AiChatClient::class, new class implements AiChatClient
        {
            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                if (($options['web_search'] ?? false) !== true) {
                    return [];
                }

                return [
                    'places' => [
                        ['name' => 'Quiet Bar', 'image_url' => 'https://example.com/quiet-bar.jpg'],
                        ['name' => 'Open Field', 'image_url' => 'http://127.0.0.1/secret.jpg'],
                    ],
                ];
            }
        });

        $content = app(PlaceListingLookup::class)->enrich([
            'stops' => [
                ['time' => '9:00 PM', 'name' => 'Quiet Bar', 'activity' => 'A drink', 'notes' => ''],
                ['time' => '10:00 PM', 'name' => 'Open Field', 'activity' => 'Stars', 'notes' => ''],
            ],
        ], 'Austin');

        $this->assertStringContainsString('/api/v1/place-photos/', $content['stops'][0]['photo_url']);
        $this->assertArrayNotHasKey('photo_url', $content['stops'][1]);

        $path = parse_url($content['stops'][0]['photo_url'], PHP_URL_PATH);
        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function sessionIn(string $city, string $slug = 'night_out', array $answers = []): PlanSession
    {
        $user = User::factory()->create();
        $planType = PlanType::query()->firstOrCreate(
            ['slug' => $slug],
            ['label' => $slug, 'description' => $slug],
        );

        return PlanSession::factory()->create([
            'user_id' => $user->id,
            'plan_type_id' => $planType->id,
            'city' => $city,
            'answers' => $answers,
        ]);
    }
}
