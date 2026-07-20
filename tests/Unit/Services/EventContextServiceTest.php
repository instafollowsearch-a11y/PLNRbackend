<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventContextService;
use App\Services\Events\EventSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_formats_upcoming_events_for_prompt(): void
    {
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Harbor Jazz Night',
            'source' => 'fixture',
            'starts_at' => now()->addDay(),
        ]);

        $service = new EventContextService;
        $prompt = $service->formatForPrompt('Austin');

        $this->assertStringContainsString('Local upcoming events:', $prompt);
        $this->assertStringContainsString('Harbor Jazz Night', $prompt);
        $this->assertStringContainsString('source=fixture', $prompt);
    }

    public function test_cities_to_sync_merges_fixture_and_user_cities(): void
    {
        config([
            'services.events.fixtures_path' => base_path('data/events'),
        ]);

        User::factory()->create(['city' => 'Denver']);

        $service = new EventContextService;
        $cities = $service->citiesToSync(new EventSourceResolver);

        $this->assertContains('Austin', $cities);
        $this->assertContains('Denver', $cities);
    }
}
