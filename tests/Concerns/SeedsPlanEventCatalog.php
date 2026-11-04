<?php

namespace Tests\Concerns;

use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

trait SeedsPlanEventCatalog
{
    /**
     * @param  list<string>|null  $titles
     * @return Collection<int, Event>
     */
    protected function seedPlanEventCatalog(
        string $city = 'Austin',
        int $count = 8,
        ?CarbonInterface $startsAt = null,
        ?array $titles = null,
    ): Collection {
        $startsAt ??= $this->catalogStart($city);
        $titles ??= $this->catalogTitles($city);
        $events = collect();

        for ($index = 0; $index < $count; $index++) {
            $title = $titles[$index] ?? $city.' listing '.($index + 1);
            $events->push(Event::factory()->create([
                'city' => $city,
                'title' => $title,
                'venue_name' => $title.' Venue',
                'starts_at' => $startsAt->copy()->addMinutes($index * 30),
                'url' => 'https://example.test/events/'.$city.'/'.$index,
                'source' => 'fixture',
            ]));
        }

        return $events;
    }

    private function catalogStart(string $city): CarbonInterface
    {
        if ($city === 'Barcelona') {
            return now()->year < 2027
                ? now()->setDate(2027, 6, 12)->setTime(18, 0)
                : now()->setDate((int) now()->year, 6, 12)->setTime(18, 0);
        }

        if (strtolower((string) now()->englishDayOfWeek) === 'saturday') {
            return now()->setTime(20, 0);
        }

        return now()->next('saturday')->setTime(20, 0);
    }

    /**
     * @return list<string>
     */
    private function catalogTitles(string $city): array
    {
        return match ($city) {
            'Barcelona' => [
                'Sagrada Familia',
                'Gothic Quarter',
                'Beach day',
                'Barceloneta Beach',
                'Sunset cruise',
                'Montserrat hike',
                'Coastal bike path',
                'Tapas market',
            ],
            default => [
                'Blue Note Bar',
                'Harbor Jazz Club',
                'Taco Row',
                'Skyline Lounge',
                'Laugh Factory',
                'Market Hall',
                'Riverside Eats',
                'Modern Art Museum',
            ],
        };
    }
}
