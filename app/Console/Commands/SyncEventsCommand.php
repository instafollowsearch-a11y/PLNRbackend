<?php

namespace App\Console\Commands;

use App\Services\Events\EventContextService;
use App\Services\Events\EventIngestionService;
use App\Services\Events\EventSourceResolver;
use Illuminate\Console\Command;

class SyncEventsCommand extends Command
{
    protected $signature = 'events:sync {city? : City to sync; omit to sync fixture and user profile cities}';

    protected $description = 'Import local events from configured sources';

    public function handle(
        EventIngestionService $ingestion,
        EventSourceResolver $resolver,
        EventContextService $eventContext,
    ): int {
        $cityArgument = $this->argument('city');
        $cities = $cityArgument !== null
            ? [(string) $cityArgument]
            : $eventContext->citiesToSync($resolver);

        if ($cities === []) {
            $this->warn('No cities found to sync.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($cities as $city) {
            $count = $ingestion->syncCity($city, $resolver);
            $total += $count;
            $this->info("Synced {$count} events for {$city}.");
        }

        $this->info("Done. {$total} events upserted.");

        return self::SUCCESS;
    }
}
