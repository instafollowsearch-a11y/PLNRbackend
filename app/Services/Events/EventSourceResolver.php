<?php

namespace App\Services\Events;

use App\Services\Events\Adapters\AlleventsEventAdapter;
use App\Services\Events\Adapters\EventbriteEventAdapter;
use App\Services\Events\Adapters\FixtureEventAdapter;
use App\Services\Events\Adapters\GrouponFixtureAdapter;
use App\Services\Events\Adapters\LumaEventAdapter;
use App\Services\Events\Adapters\PartifulFixtureAdapter;
use App\Services\Events\Adapters\PoshFixtureAdapter;

class EventSourceResolver
{
    /** @var array<string, EventSourceAdapter> */
    private array $adapters;

    public function __construct()
    {
        $instances = [
            new FixtureEventAdapter,
            new PartifulFixtureAdapter,
            new PoshFixtureAdapter,
            new GrouponFixtureAdapter,
            new EventbriteEventAdapter,
            new LumaEventAdapter,
            new AlleventsEventAdapter,
        ];

        $this->adapters = [];

        foreach ($instances as $adapter) {
            $this->adapters[$adapter->source()] = $adapter;
        }
    }

    /**
     * @return list<EventSourceAdapter>
     */
    public function enabled(): array
    {
        $sources = config('services.events.sources', ['fixture']);
        $enabled = [];

        foreach ($sources as $source) {
            if ($source === 'eventbrite' && (string) config('services.events.eventbrite.token') === '') {
                continue;
            }

            if ($source === 'luma' && (string) config('services.events.luma.api_key') === '') {
                continue;
            }

            if ($source === 'allevents' && (string) config('services.events.allevents.api_key') === '') {
                continue;
            }

            if (isset($this->adapters[$source])) {
                $enabled[] = $this->adapters[$source];
            }
        }

        return $enabled;
    }

    public function fixtureAdapter(): FixtureEventAdapter
    {
        /** @var FixtureEventAdapter $adapter */
        $adapter = $this->adapters['fixture'];

        return $adapter;
    }
}
