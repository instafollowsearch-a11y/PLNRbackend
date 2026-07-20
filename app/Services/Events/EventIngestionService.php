<?php

namespace App\Services\Events;

use App\Models\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventIngestionService
{
    public function upsert(NormalizedEvent $normalized): Event
    {
        return Event::query()->updateOrCreate(
            [
                'source' => $normalized->source,
                'external_id' => $normalized->externalId,
            ],
            $normalized->toAttributes(),
        );
    }

    public function syncCity(string $city, EventSourceResolver $resolver): int
    {
        $count = 0;

        foreach ($resolver->enabled() as $adapter) {
            try {
                foreach ($adapter->fetch($city) as $normalized) {
                    $this->upsert($normalized);
                    $count++;
                }
            } catch (Throwable $exception) {
                Log::warning('events.sync_adapter_failed', [
                    'city' => $city,
                    'source' => $adapter->source(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
