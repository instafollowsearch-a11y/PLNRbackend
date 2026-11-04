<?php

namespace Tests\Feature\Live;

use App\Models\Event;
use App\Models\InterestMatch;
use App\Models\InterestPing;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\AI\ItineraryService;
use App\Services\AI\PlanConsistency;
use App\Services\AI\SuggestionService;
use App\Services\AI\WeekendRecommendationService;
use App\Services\Events\CityListingInventory;
use App\Services\Events\FindLocalMetros;
use App\Services\Interests\ScanUserInterests;
use App\Services\Settings\AppSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('live')]
class AtlantaListingLiveTest extends TestCase
{
    use RefreshDatabase;

    private const STREET = '840 Westview Drive Southwest, Atlanta, Georgia';

    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $settings = app(AppSettings::class);
        $anthropic = trim((string) ($settings->anthropicApiKey() ?? ''));
        $findLocal = trim((string) ($settings->findLocalApiKey() ?? ''));

        if ($anthropic === '' || $findLocal === '') {
            $this->markTestSkipped('Live Atlanta tests need Anthropic and Find Local keys in backend/.env or admin settings.');
        }

        $sources = config('services.events.sources', ['fixture']);
        if (! is_array($sources)) {
            $sources = ['fixture'];
        }
        if (! in_array('findlocal', $sources, true)) {
            $sources[] = 'findlocal';
        }
        config(['services.events.sources' => array_values($sources)]);

        foreach (['night_out', 'date_night', 'vacation', 'road_trip'] as $slug) {
            PlanType::factory()->create([
                'slug' => $slug,
                'label' => str_replace('_', ' ', $slug),
            ]);
        }
    }

    public function test_atlanta_street_address_normalizes_and_has_a_findlocal_metro(): void
    {
        $listings = app(CityListingInventory::class);

        $this->assertSame('Atlanta', $listings->cityKey(self::STREET));
        $this->assertSame('atlanta', FindLocalMetros::slugFor('Atlanta'));
        $this->assertSame('atlanta', FindLocalMetros::slugFor('Atlanta, Georgia'));
    }

    public function test_fill_returns_dated_atlanta_listings_from_apis_and_scout(): void
    {
        $events = $this->fillAtlanta('Atlanta');

        $this->assertListingsAreUsable($events);
        $sources = $events->pluck('source')->unique()->values()->all();
        $this->assertNotEmpty(array_intersect($sources, ['findlocal', 'web_ai', 'eventbrite', 'allevents', 'luma', 'ticketmaster', 'web']));
    }

    public function test_street_address_fill_still_returns_atlanta_listings(): void
    {
        $this->assertListingsAreUsable($this->fillAtlanta(self::STREET));
    }

    #[DataProvider('planTypes')]
    public function test_plan_cards_and_itinerary_lock_to_atlanta_listings(string $slug): void
    {
        $session = $this->sessionFor($slug);
        $suggestions = app(SuggestionService::class)->generate($session);

        $this->assertGreaterThanOrEqual(3, $suggestions->count());
        $this->assertLessThanOrEqual(5, $suggestions->count());

        $catalogTitles = Event::query()->pluck('title')->all();
        $this->assertNotEmpty($catalogTitles);

        foreach ($suggestions as $suggestion) {
            $this->assertSuggestionUsesCatalog($suggestion, $catalogTitles);
        }

        /** @var Suggestion $picked */
        $picked = $suggestions->first();
        $itinerary = app(ItineraryService::class)->generate($session->fresh(), $picked);
        $content = is_array($itinerary->content) ? $itinerary->content : [];
        $requiredNames = $this->requiredNames($picked);

        $this->assertNotEmpty($requiredNames);
        $this->assertTrue(PlanConsistency::usesAllPlaces($content, $requiredNames));
        $this->assertTrue($this->notesAreDetailed($content), 'Full itinerary notes should be more than a stub.');
    }

    public function test_weekend_picks_use_catalog_event_ids_in_the_weekend_window(): void
    {
        $user = User::factory()->pro()->create([
            'city' => 'Atlanta',
            'interests' => ['live music'],
        ]);
        $service = app(WeekendRecommendationService::class);
        [$windowStart, $windowEnd] = $service->comingWeekendWindow();
        $recommendation = $service->generate($user, 'Atlanta', ['live music']);
        $items = is_array($recommendation->items) ? $recommendation->items : [];

        $this->assertGreaterThanOrEqual(3, count($items));
        $this->assertLessThanOrEqual(5, count($items));

        foreach ($items as $item) {
            $eventId = (int) ($item['event_id'] ?? 0);
            $this->assertGreaterThan(0, $eventId);
            $event = Event::query()->find($eventId);
            $this->assertNotNull($event);
            $this->assertNotEmpty(trim((string) ($item['title'] ?? '')));
            $startsAt = Carbon::parse((string) ($item['starts_at'] ?? ''));
            $this->assertTrue($startsAt->betweenIncluded($windowStart, $windowEnd));
            $this->assertTrue($this->cityLooksLikeAtlanta((string) $event->city));

            $url = $item['url'] ?? $event->url;
            if (is_string($url) && $url !== '') {
                $this->assertStringStartsWith('https://', $url);
            }
        }
    }

    public function test_interest_scan_matches_street_address_pro_and_skips_free_pings(): void
    {
        $pro = User::factory()->pro()->create([
            'city' => self::STREET,
            'interests' => ['Live music'],
        ]);
        $free = User::factory()->create([
            'city' => self::STREET,
            'interests' => ['Live music'],
        ]);

        app(ScanUserInterests::class)->run();

        $proMatches = InterestMatch::query()->where('user_id', $pro->id)->with('event')->get();
        $freeMatches = InterestMatch::query()->where('user_id', $free->id)->count();

        $this->assertGreaterThan(0, $proMatches->count());
        $this->assertGreaterThan(0, $freeMatches);

        foreach ($proMatches as $match) {
            $this->assertNotNull($match->event);
            $this->assertTrue($this->cityLooksLikeAtlanta((string) $match->event->city));
        }

        $this->assertGreaterThan(0, InterestPing::query()->where('user_id', $pro->id)->whereNull('push_sent_at')->count());
        $this->assertSame(0, InterestPing::query()->where('user_id', $free->id)->count());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function planTypes(): array
    {
        return [
            ['night_out'],
            ['date_night'],
            ['vacation'],
            ['road_trip'],
        ];
    }

    /**
     * @return Collection<int, Event>
     */
    private function fillAtlanta(string $city): Collection
    {
        return app(CityListingInventory::class)->fill(
            $city,
            now()->startOfDay(),
            now()->addDays(7)->endOfDay(),
            ['live music'],
            true,
            'live_atlanta_test',
            false,
        );
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function assertListingsAreUsable(Collection $events): void
    {
        $this->assertTrue($events->isNotEmpty(), 'Atlanta listing fill returned no events.');
        $this->assertTrue(
            $events->contains(fn (Event $event): bool => $this->cityLooksLikeAtlanta((string) $event->city)),
            'No listing city looked like Atlanta.',
        );
        $this->assertTrue(
            $events->contains(function (Event $event): bool {
                $url = (string) ($event->url ?? '');

                return str_starts_with($url, 'https://');
            }),
            'No listing had an https URL.',
        );
        $this->assertTrue(
            $events->every(fn (Event $event): bool => $event->starts_at !== null && $event->starts_at->gte(now()->startOfDay())),
        );
    }

    private function sessionFor(string $slug): PlanSession
    {
        $planType = PlanType::query()->where('slug', $slug)->firstOrFail();
        $saturday = strtolower((string) now()->englishDayOfWeek) === 'saturday'
            ? now()->toDateString()
            : now()->next('saturday')->toDateString();
        $sunday = Carbon::parse($saturday)->addDay()->toDateString();
        $monday = Carbon::parse($saturday)->addDays(2)->toDateString();
        $city = $slug === 'night_out' ? self::STREET : 'Atlanta';

        $answers = match ($slug) {
            'night_out' => [
                'city' => $city,
                'interests' => 'live music',
                'group_size' => 2,
                'budget_per_person' => 80,
                'dates' => 'Saturday',
                'start_time' => '8:00 PM',
            ],
            'date_night' => [
                'city' => $city,
                'self_gender' => 'man',
                'partner_gender' => 'woman',
                'timeframe' => 'Saturday',
                'partner_interests' => 'live music',
                'budget' => 120,
                'event_count' => 3,
            ],
            'vacation' => [
                'destination' => $city,
                'city' => $city,
                'dates' => json_encode(['start' => $saturday, 'end' => $monday]),
                'budget' => 400,
                'age_range' => '30s',
                'interests' => 'live music',
                'group_size' => 2,
                'activity_mix' => 'going out',
            ],
            default => [
                'start_location' => $city,
                'end_location' => 'Athens, Georgia',
                'city' => $city,
                'departure_date' => $saturday,
                'arrival_date' => $sunday,
                'departure_time' => '9 AM',
                'car_type' => 'sedan',
                'stop_preference' => 'direct',
                'interests' => 'live music',
                'food_preferences' => 'local',
                'group_size' => 2,
            ],
        };

        return PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => $city,
            'status' => PlanSession::STATUS_READY,
            'answers' => $answers,
        ]);
    }

    /**
     * @param  list<string>  $catalogTitles
     */
    private function assertSuggestionUsesCatalog(Suggestion $suggestion, array $catalogTitles): void
    {
        $payload = is_array($suggestion->payload) ? $suggestion->payload : [];
        $ids = array_values(array_filter(array_map('intval', $payload['event_ids'] ?? [])));
        $this->assertNotEmpty($ids, 'Suggestion missing catalog event_ids.');

        foreach ($ids as $id) {
            $this->assertNotNull(Event::query()->find($id));
        }

        $haystack = strtolower(json_encode($payload) ?: '');
        $matched = false;

        foreach ($catalogTitles as $title) {
            if (PlanConsistency::namesOverlap($title, $haystack) || PlanConsistency::namesOverlap($title, (string) ($payload['name'] ?? ''))) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'Suggestion did not obviously include a catalog listing.');
    }

    /**
     * @return list<string>
     */
    private function requiredNames(Suggestion $suggestion): array
    {
        $payload = is_array($suggestion->payload) ? $suggestion->payload : [];
        $ids = array_values(array_filter(array_map('intval', $payload['event_ids'] ?? [])));
        $names = Event::query()->whereIn('id', $ids)->pluck('title')->all();

        return array_values(array_filter(array_map('strval', $names)));
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function notesAreDetailed(array $content): bool
    {
        $notes = [];

        foreach ($content['stops'] ?? [] as $stop) {
            if (is_array($stop)) {
                $notes[] = trim((string) ($stop['notes'] ?? '').' '.(string) ($stop['activity'] ?? ''));
            }
        }

        foreach ($content['days'] ?? [] as $day) {
            if (! is_array($day)) {
                continue;
            }

            foreach ($day['stops'] ?? [] as $stop) {
                if (is_array($stop)) {
                    $notes[] = trim((string) ($stop['notes'] ?? '').' '.(string) ($stop['activity'] ?? ''));
                }
            }
        }

        foreach ($notes as $note) {
            if (mb_strlen($note) > 40) {
                return true;
            }
        }

        return false;
    }

    private function cityLooksLikeAtlanta(string $city): bool
    {
        return str_contains(mb_strtolower($city), 'atlanta');
    }
}
