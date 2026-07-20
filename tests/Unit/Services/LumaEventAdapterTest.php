<?php

namespace Tests\Unit\Services;

use App\Services\Events\Adapters\LumaEventAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LumaEventAdapterTest extends TestCase
{
    public function test_maps_luma_search_response(): void
    {
        config([
            'services.events.luma.api_key' => 'test-key',
            'services.events.luma.url' => 'https://api.lu.ma/public/v1/event/search',
        ]);

        Http::fake([
            'https://api.lu.ma/public/v1/event/search*' => Http::response(
                json_decode(
                    file_get_contents(base_path('tests/Fixtures/luma/search_response.json')),
                    true,
                ),
            ),
        ]);

        $adapter = new LumaEventAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertCount(1, $events);
        $this->assertSame('luma', $events[0]->source);
        $this->assertSame('luma-evt-001', $events[0]->externalId);
        $this->assertSame('Design Meetup Austin', $events[0]->title);
    }

    public function test_returns_empty_when_api_key_missing(): void
    {
        config([
            'services.events.luma.api_key' => '',
        ]);

        $adapter = new LumaEventAdapter;

        $this->assertSame([], $adapter->fetch('Austin'));
        Http::assertNothingSent();
    }
}
