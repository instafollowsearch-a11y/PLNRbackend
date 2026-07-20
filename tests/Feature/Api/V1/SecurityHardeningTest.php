<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_per_page_over_50_returns_validation_error(): void
    {
        $user = User::factory()->create(['city' => 'Austin']);
        Sanctum::actingAs($user);

        Event::factory()->count(3)->create(['city' => 'Austin']);

        $this->getJson('/api/v1/events?per_page=100')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_sanctum_token_expiration_is_configurable(): void
    {
        config(['sanctum.expiration' => 60]);

        $this->assertSame(60, config('sanctum.expiration'));
    }
}
