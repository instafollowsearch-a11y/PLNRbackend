<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\User;
use App\Models\WeekendRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class WeekendRecommendationApiTest extends TestCase
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

    public function test_requires_pro(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/weekend-recommendations', [
            'city' => 'Austin',
            'interests' => ['live music'],
        ])->assertForbidden();
    }

    public function test_pro_user_can_generate_and_email_recommendations(): void
    {
        Mail::fake();

        $events = Event::factory()->count(4)->create([
            'city' => 'Austin',
            'starts_at' => now()->addDays(2),
        ]);

        $payload = [
            'recommendations' => $events->take(3)->map(fn (Event $event) => [
                'event_id' => $event->id,
                'reason' => 'Great match',
            ])->values()->all(),
        ];

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            ]),
        ]);

        $user = User::factory()->pro()->create(['city' => 'Austin']);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/weekend-recommendations', [
            'city' => 'Austin',
            'interests' => ['live music', 'food'],
        ])->assertCreated();

        $uuid = $create->json('data.recommendation.uuid');
        $this->assertNotEmpty($uuid);
        $this->assertCount(3, $create->json('data.recommendation.items'));

        $this->postJson("/api/v1/weekend-recommendations/{$uuid}/send-email")
            ->assertOk()
            ->assertJsonPath('data.recommendation.email_sent_at', fn ($value) => $value !== null);

        Mail::assertSent(\App\Mail\WeekendRecommendationsMail::class);
        $this->assertDatabaseHas('weekend_recommendations', [
            'uuid' => $uuid,
            'user_id' => $user->id,
            'city' => 'Austin',
        ]);
    }

    public function test_falls_back_to_web_search_when_local_catalog_empty(): void
    {
        $payload = [
            'recommendations' => [
                [
                    'title' => 'Downtown Jazz Night',
                    'venue' => 'Blue Note Hall',
                    'starts_at' => now()->addDays(2)->toIso8601String(),
                    'url' => 'https://example.com/events/jazz-night',
                    'source' => 'eventbrite',
                    'reason' => 'Live jazz matches your interest',
                ],
                [
                    'title' => 'Food Truck Friday',
                    'venue' => 'Market Square',
                    'starts_at' => now()->addDays(3)->toIso8601String(),
                    'url' => 'https://example.com/events/food-truck',
                    'source' => 'allevents',
                    'reason' => 'Great for food lovers',
                ],
                [
                    'title' => 'Indie Showcase',
                    'venue' => 'Echo Lounge',
                    'starts_at' => now()->addDays(4)->toIso8601String(),
                    'url' => 'https://example.com/events/indie',
                    'source' => 'luma',
                    'reason' => 'Local live music',
                ],
            ],
        ];

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_web',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            ]),
        ]);

        $user = User::factory()->pro()->create(['city' => null]);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/weekend-recommendations', [
            'city' => 'Denver, Colorado, United States',
            'interests' => ['live music', 'food'],
        ])->assertCreated();

        $this->assertCount(3, $create->json('data.recommendation.items'));
        $this->assertSame('Denver', $create->json('data.recommendation.city'));
        $this->assertDatabaseHas('events', [
            'city' => 'Denver',
            'title' => 'Downtown Jazz Night',
            'source' => 'eventbrite',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'city' => 'Denver',
        ]);
    }

    public function test_matches_city_label_variants_against_catalog(): void
    {
        $events = Event::factory()->count(3)->create([
            'city' => 'Austin',
            'starts_at' => now()->addDays(1),
        ]);

        $payload = [
            'recommendations' => $events->map(fn (Event $event) => [
                'event_id' => $event->id,
                'reason' => 'Local match',
            ])->values()->all(),
        ];

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_city',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->pro()->create());

        $this->postJson('/api/v1/weekend-recommendations', [
            'city' => 'Austin, Travis County, Texas, United States',
            'interests' => ['jazz'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.recommendation.city', 'Austin')
            ->assertJsonCount(3, 'data.recommendation.items');
    }

    public function test_cannot_view_another_users_recommendation(): void
    {
        $owner = User::factory()->pro()->create();
        $other = User::factory()->pro()->create();
        $recommendation = WeekendRecommendation::query()->create([
            'user_id' => $owner->id,
            'city' => 'Austin',
            'interests' => ['jazz'],
            'window_start' => now(),
            'window_end' => now()->addDays(7),
            'items' => [['title' => 'Show', 'reason' => 'Fun']],
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/weekend-recommendations/{$recommendation->uuid}")
            ->assertForbidden();
    }
}
