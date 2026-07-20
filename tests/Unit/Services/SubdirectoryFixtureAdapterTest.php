<?php

namespace Tests\Unit\Services;

use App\Services\Events\Adapters\GrouponFixtureAdapter;
use App\Services\Events\Adapters\PartifulFixtureAdapter;
use App\Services\Events\Adapters\PoshFixtureAdapter;
use Tests\TestCase;

class SubdirectoryFixtureAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.events.fixtures_path' => base_path('data/events'),
        ]);
    }

    public function test_partiful_fixture_adapter_parses_austin(): void
    {
        $adapter = new PartifulFixtureAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertGreaterThanOrEqual(2, count($events));
        $this->assertSame('partiful', $events[0]->source);
        $this->assertSame('Rooftop Day Party', $events[0]->title);
    }

    public function test_posh_fixture_adapter_parses_austin(): void
    {
        $adapter = new PoshFixtureAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertGreaterThanOrEqual(2, count($events));
        $this->assertSame('posh', $events[0]->source);
        $this->assertSame('Sunday Brunch Club', $events[0]->title);
    }

    public function test_groupon_fixture_adapter_parses_austin(): void
    {
        $adapter = new GrouponFixtureAdapter;
        $events = $adapter->fetch('Austin');

        $this->assertGreaterThanOrEqual(2, count($events));
        $this->assertSame('groupon', $events[0]->source);
        $this->assertSame('Austin Food Tour for Two', $events[0]->title);
    }

    public function test_subdirectory_adapters_list_available_cities(): void
    {
        $partiful = new PartifulFixtureAdapter;
        $posh = new PoshFixtureAdapter;
        $groupon = new GrouponFixtureAdapter;

        $this->assertContains('Austin', $partiful->availableCities());
        $this->assertContains('Austin', $posh->availableCities());
        $this->assertContains('Austin', $groupon->availableCities());
    }
}
