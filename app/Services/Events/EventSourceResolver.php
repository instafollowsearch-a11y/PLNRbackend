<?php

namespace App\Services\Events;

use App\Services\Events\Adapters\AlleventsEventAdapter;
use App\Services\Events\Adapters\EventbriteEventAdapter;
use App\Services\Events\Adapters\FindLocalEventAdapter;
use App\Services\Events\Adapters\FixtureEventAdapter;
use App\Services\Events\Adapters\GrouponFixtureAdapter;
use App\Services\Events\Adapters\LumaEventAdapter;
use App\Services\Events\Adapters\PartifulFixtureAdapter;
use App\Services\Events\Adapters\PoshFixtureAdapter;
use App\Services\Settings\AppSettings;

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
            new FindLocalEventAdapter,
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

            if ($source === 'findlocal' && $this->findLocalKey() === '') {
                continue;
            }

            if (isset($this->adapters[$source])) {
                $enabled[] = $this->adapters[$source];
            }
        }

        if ($this->findLocalKey() !== '' && isset($this->adapters['findlocal'])) {
            $alreadyEnabled = false;

            foreach ($enabled as $adapter) {
                if ($adapter->source() === 'findlocal') {
                    $alreadyEnabled = true;
                    break;
                }
            }

            if (! $alreadyEnabled) {
                $enabled[] = $this->adapters['findlocal'];
            }
        }

        return $enabled;
    }

    private function findLocalKey(): string
    {
        return (string) app(AppSettings::class)->findLocalApiKey();
    }

    public function fixtureAdapter(): FixtureEventAdapter
    {
        /** @var FixtureEventAdapter $adapter */
        $adapter = $this->adapters['fixture'];

        return $adapter;
    }
}
