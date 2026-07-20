<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/events')->assertUnauthorized();
    }

    public function test_returns_paginated_events_for_user_city(): void
    {
        $user = User::factory()->create(['city' => 'Austin']);
        Sanctum::actingAs($user);

        Event::factory()->count(3)->create(['city' => 'Austin']);
        Event::factory()->create(['city' => 'Dallas']);

        $response = $this->getJson('/api/v1/events');

        $response->assertOk()
            ->assertJsonCount(3, 'data.events')
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_allows_city_override_query_param(): void
    {
        $user = User::factory()->create(['city' => 'Austin']);
        Sanctum::actingAs($user);

        Event::factory()->create(['city' => 'Barcelona', 'title' => 'Tapas Walk']);

        $this->getJson('/api/v1/events?city=Barcelona')
            ->assertOk()
            ->assertJsonPath('data.events.0.title', 'Tapas Walk');
    }

    public function test_returns_422_when_city_missing(): void
    {
        $user = User::factory()->create(['city' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/events')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city']);
    }

    public function test_empty_feed_is_valid(): void
    {
        $user = User::factory()->create(['city' => 'Austin']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(0, 'data.events')
            ->assertJsonPath('data.meta.total', 0);
    }
}
