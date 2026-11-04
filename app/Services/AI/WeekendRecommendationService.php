<?php

namespace App\Services\AI;

use App\Models\Event;
use App\Models\User;
use App\Models\WeekendRecommendation;
use App\Services\AI\Prompts\AbstractPlanPromptBuilder;
use App\Services\Events\CityListingInventory;
use App\Services\Events\FindLocalStopLinks;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class WeekendRecommendationService
{
    private const MIN_RECOMMENDATIONS = 3;

    private const MAX_RECOMMENDATIONS = 5;

    public function __construct(
        private readonly AiChatClient $client,
        private readonly CityListingInventory $listings,
    ) {}

    /**
     * @param  list<string>  $interests
     */
    public function generate(User $user, string $city, array $interests): WeekendRecommendation
    {
        $interests = array_values(array_filter(array_map(
            static fn (mixed $interest): string => trim((string) $interest),
            $interests,
        )));

        if ($interests === []) {
            throw ValidationException::withMessages([
                'interests' => ['Select at least one interest.'],
            ]);
        }

        $city = trim($city);
        if ($city === '') {
            throw ValidationException::withMessages([
                'city' => ['City is required.'],
            ]);
        }

        $cityKey = $this->listings->cityKey($city);
        [$windowStart, $windowEnd] = $this->comingWeekendWindow();
        $events = $this->listings->fill(
            $city,
            $windowStart,
            $windowEnd,
            $interests,
            true,
            'weekend_web_search',
        );

        $items = $this->picksFromCatalog($city, $interests, $windowStart, $windowEnd, $events);

        if (count($items) < self::MIN_RECOMMENDATIONS) {
            throw ValidationException::withMessages([
                'events' => ['We could not find enough upcoming events for this city. Try another city or different interests.'],
            ]);
        }

        $user->forceFill([
            'city' => $cityKey,
            'interests' => $interests,
        ])->save();

        usort($items, function (array $left, array $right): int {
            return strcmp((string) ($left['starts_at'] ?? ''), (string) ($right['starts_at'] ?? ''));
        });

        return WeekendRecommendation::query()->create([
            'user_id' => $user->id,
            'city' => $cityKey,
            'interests' => $interests,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'items' => $items,
            'saturday_plan' => $this->saturdayPlan($cityKey, $items),
        ]);
    }

    /**
     * Friday through Sunday. On Saturday or Sunday, the window starts today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function comingWeekendWindow(): array
    {
        $now = now()->timezone((string) config('app.timezone'));

        if ($now->isSunday()) {
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        }

        $start = $now->isFriday() || $now->isSaturday()
            ? $now->copy()->startOfDay()
            : $now->copy()->next(Carbon::FRIDAY)->startOfDay();
        $end = $now->copy()->next(Carbon::SUNDAY)->endOfDay();

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $interests
     * @return array<string, mixed>
     */
    private function weekendRequestPayload(array $payload, array $interests): array
    {
        foreach ($interests as $interest) {
            if (str_contains($interest, AbstractPlanPromptBuilder::OPEN_TO_SUGGESTIONS)) {
                $payload['open_to_suggestions'] = AbstractPlanPromptBuilder::OPEN_TO_SUGGESTIONS_LINE;

                return $payload;
            }
        }

        return $payload;
    }

    /**
     * @param  list<string>  $interests
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function recommendFromCatalog(
        string $city,
        array $interests,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $events,
    ): array {
        $catalog = $events->map(fn (Event $event): array => [
            'event_id' => $event->id,
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => app(FindLocalStopLinks::class)->urlFor($event),
            'source' => $event->source,
            'description' => mb_substr((string) ($event->description ?? ''), 0, 240),
        ])->values()->all();

        $response = $this->client->chat([
            [
                'role' => 'system',
                'content' => 'You are PLNR weekend recommender. Pick 3 to 5 real events from the provided catalog that best match the user interests for the coming Friday, Saturday, and Sunday. Include at least one event on each of those days when the catalog has one. Respond ONLY with JSON: {"recommendations":[{"event_id":number,"reason":"short why"}]}. Never invent event_id values.',
            ],
            [
                'role' => 'user',
                'content' => json_encode($this->weekendRequestPayload([
                    'city' => $city,
                    'interests' => $interests,
                    'window_start' => $windowStart->toIso8601String(),
                    'window_end' => $windowEnd->toIso8601String(),
                    'events' => $catalog,
                ], $interests), JSON_THROW_ON_ERROR),
            ],
        ]);

        $items = $this->normalizeCatalogItems($response, $events);

        if (count($items) < self::MIN_RECOMMENDATIONS) {
            throw new InvalidArgumentException('AI did not return enough matching event recommendations.');
        }

        return $items;
    }

    /**
     * @param  list<string>  $interests
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function picksFromCatalog(
        string $city,
        array $interests,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $events,
    ): array {
        $items = [];

        if ($events->count() >= self::MIN_RECOMMENDATIONS) {
            try {
                $items = $this->coverWeekendDays(
                    $this->recommendFromCatalog($city, $interests, $windowStart, $windowEnd, $events),
                    $events,
                    $windowStart,
                    $windowEnd,
                );
            } catch (Throwable $exception) {
                Log::warning('weekend.catalog_recommend_failed', [
                    'city' => $city,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if (count($items) < self::MIN_RECOMMENDATIONS) {
            $items = $this->coverWeekendDays(
                $this->itemsFromEvents($events, $items),
                $events,
                $windowStart,
                $windowEnd,
            );
        }

        return $items;
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function itemsFromEvents(Collection $events, array $existing): array
    {
        $items = $existing;
        $seen = [];

        foreach ($existing as $item) {
            $seen[(int) ($item['event_id'] ?? 0)] = true;
        }

        foreach ($events as $event) {
            if (isset($seen[$event->id])) {
                continue;
            }

            $items[] = $this->itemFromEvent($event, trim((string) ($event->description ?? 'Matches your interests.')));
            $seen[$event->id] = true;

            if (count($items) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function normalizeCatalogItems(array $response, Collection $events): array
    {
        $raw = $response['recommendations'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $byId = $events->keyBy('id');
        $items = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $eventId = (int) ($row['event_id'] ?? 0);
            $event = $byId->get($eventId);
            if (! $event instanceof Event) {
                continue;
            }

            $items[] = $this->itemFromEvent(
                $event,
                trim((string) ($row['reason'] ?? 'Matches your interests.')),
            );

            if (count($items) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFromEvent(Event $event, string $reason): array
    {
        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'venue' => $event->venue_name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'day' => $this->dayName($event->starts_at),
            'url' => app(FindLocalStopLinks::class)->urlFor($event),
            'image_url' => $event->image_url,
            'source' => $event->source,
            'reason' => $reason !== '' ? $reason : 'Matches your interests.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function coverWeekendDays(array $items, Collection $events, Carbon $windowStart, Carbon $windowEnd): array
    {
        foreach ($this->weekendDayNames($windowStart, $windowEnd) as $dayName) {
            if ($this->itemsIncludeDay($items, $dayName)) {
                continue;
            }

            $event = $events->first(function (Event $candidate) use ($dayName, $items): bool {
                if ($this->dayName($candidate->starts_at) !== $dayName) {
                    return false;
                }

                foreach ($items as $item) {
                    if ((int) ($item['event_id'] ?? 0) === $candidate->id) {
                        return false;
                    }
                }

                return true;
            });

            if (! $event instanceof Event) {
                continue;
            }

            $covered = $this->itemFromEvent($event, 'Matches your interests.');

            if (count($items) < self::MAX_RECOMMENDATIONS) {
                $items[] = $covered;

                continue;
            }

            foreach ($items as $index => $item) {
                $itemDay = is_string($item['day'] ?? null) ? $item['day'] : null;

                if ($itemDay !== null && $this->countItemsOnDay($items, $itemDay) > 1) {
                    $items[$index] = $covered;
                    break;
                }
            }
        }

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{title: string, summary: string, stops: list<array{time: string, name: string, detail: string}>}|null
     */
    private function saturdayPlan(string $city, array $items): ?array
    {
        $saturday = array_values(array_filter(
            $items,
            fn (array $item): bool => ($item['day'] ?? null) === 'Saturday',
        ));

        if ($saturday === []) {
            return null;
        }

        $stops = [];

        foreach ($saturday as $item) {
            $startsAt = $this->parseStartsAt($item['starts_at'] ?? null);
            $detail = trim((string) ($item['venue'] ?? ''));

            if ($detail === '') {
                $detail = trim((string) ($item['reason'] ?? ''));
            }

            $stops[] = [
                'time' => $startsAt?->timezone((string) config('app.timezone'))->format('g:i A') ?? 'Time TBA',
                'name' => (string) ($item['title'] ?? 'Stop'),
                'detail' => $detail,
            ];
        }

        $first = (string) ($saturday[0]['title'] ?? 'the first stop');
        $summary = count($stops) === 1
            ? 'Saturday starts with '.$first.'.'
            : 'A Saturday in '.$city.' with '.count($stops).' stops, starting with '.$first.'.';

        return [
            'title' => 'Saturday in '.$city,
            'summary' => $summary,
            'stops' => $stops,
        ];
    }

    /**
     * @return list<string>
     */
    private function weekendDayNames(Carbon $windowStart, Carbon $windowEnd): array
    {
        $names = [];
        $cursor = $windowStart->copy()->startOfDay();
        $last = $windowEnd->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $names[] = $cursor->format('l');
            $cursor->addDay();
        }

        return $names;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemsIncludeDay(array $items, string $dayName): bool
    {
        return $this->countItemsOnDay($items, $dayName) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countItemsOnDay(array $items, string $dayName): int
    {
        return count(array_filter(
            $items,
            fn (array $item): bool => ($item['day'] ?? null) === $dayName,
        ));
    }

    private function dayName(mixed $startsAt): ?string
    {
        if (! $startsAt instanceof CarbonInterface) {
            $startsAt = $this->parseStartsAt($startsAt);
        }

        if ($startsAt === null) {
            return null;
        }

        return $startsAt->timezone((string) config('app.timezone'))->format('l');
    }

    private function parseStartsAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
