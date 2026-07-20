<?php

namespace Tests\Unit\Services;

use App\Services\Events\Adapters\AlleventsEventAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlleventsEventAdapterTest extends TestCase
{
    public function test_maps_allevents_search_response(): void
    {
        config([
            'services.events.allevents.api_key' => 'test-key',
            'services.events.allevents.url' => 'https://allevents.in/api/events/list/',
        ]);

        Http::fake([
            'https://allevents.in/api/events/list/*' => Http::response(
                json_decode(
                    file_get_contents(base_path('tests/Fixtures/allevents/search_response.json')),
                    true,
                ),
            ),
        ]);

        $adapter = new AlleventsEventAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertCount(1, $events);
        $this->assertSame('allevents', $events[0]->source);
        $this->assertSame('ae-001', $events[0]->externalId);
        $this->assertSame('Austin Food Festival', $events[0]->title);
    }

    public function test_returns_empty_when_api_key_missing(): void
    {
        config([
            'services.events.allevents.api_key' => '',
        ]);

        $adapter = new AlleventsEventAdapter;

        $this->assertSame([], $adapter->fetch('Austin'));
        Http::assertNothingSent();
    }
}
