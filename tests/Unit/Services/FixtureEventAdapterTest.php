<?php

namespace Tests\Unit\Services;

use App\Services\Events\Adapters\FixtureEventAdapter;
use Tests\TestCase;

class FixtureEventAdapterTest extends TestCase
{
    public function test_parses_fixture_json_for_city(): void
    {
        config([
            'services.events.fixtures_path' => base_path('data/events'),
        ]);

        $adapter = new FixtureEventAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertGreaterThanOrEqual(3, count($events));
        $this->assertSame('fixture', $events[0]->source);
        $this->assertSame('Austin', $events[0]->city);
        $this->assertNotEmpty($events[0]->title);
    }

    public function test_available_cities_reads_fixture_files(): void
    {
        config([
            'services.events.fixtures_path' => base_path('data/events'),
        ]);

        $adapter = new FixtureEventAdapter;
        $cities = $adapter->availableCities();

        $this->assertContains('Austin', $cities);
        $this->assertContains('Barcelona', $cities);
    }
}
