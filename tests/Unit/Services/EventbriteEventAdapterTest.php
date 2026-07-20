<?php

namespace Tests\Unit\Services;

use App\Services\Events\Adapters\EventbriteEventAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventbriteEventAdapterTest extends TestCase
{
    public function test_maps_eventbrite_search_response(): void
    {
        config([
            'services.events.eventbrite.token' => 'test-token',
        ]);

        Http::fake([
            'https://www.eventbriteapi.com/v3/events/search/*' => Http::response(
                json_decode(
                    file_get_contents(base_path('tests/Fixtures/eventbrite/search_response.json')),
                    true,
                ),
            ),
        ]);

        $adapter = new EventbriteEventAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertCount(1, $events);
        $this->assertSame('eventbrite', $events[0]->source);
        $this->assertSame('123456789', $events[0]->externalId);
        $this->assertSame('Live Music Showcase', $events[0]->title);
        $this->assertSame('Downtown Music Hall', $events[0]->venueName);
    }

    public function test_returns_empty_when_token_missing(): void
    {
        config([
            'services.events.eventbrite.token' => '',
        ]);

        $adapter = new EventbriteEventAdapter;

        $this->assertSame([], $adapter->fetch('Austin'));
        Http::assertNothingSent();
    }
}
