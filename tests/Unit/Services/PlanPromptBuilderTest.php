<?php

namespace Tests\Unit\Services;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Services\AI\Prompts\AbstractPlanPromptBuilder;
use App\Services\AI\Prompts\DateNightPromptBuilder;
use App\Services\AI\Prompts\NightOutPromptBuilder;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\AI\Prompts\RoadTripPromptBuilder;
use App\Services\AI\Prompts\VacationPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_night_out_builder_includes_nightlife_prompt_fragments(): void
    {
        $builder = new NightOutPromptBuilder;

        $this->assertSame('night_out', $builder->slug());
        $this->assertStringContainsString('nightlife', $builder->suggestionSystemPrompt());
        $this->assertStringContainsString('estimated_cost_per_person', $builder->suggestionSystemPrompt());

        $payload = $builder->normalizeSuggestionPayload([
            'name' => 'Jazz Night',
            'description' => 'Live music',
            'estimated_cost_per_person' => 50,
            'time_slot' => '8 PM',
            'venues' => ['Club A'],
        ]);

        $this->assertSame(50.0, $payload['estimated_cost_per_person']);
        $this->assertSame(['Club A'], $payload['venues']);
    }

    public function test_date_night_builder_includes_romantic_prompt_fragments(): void
    {
        $builder = new DateNightPromptBuilder;

        $this->assertSame('date_night', $builder->slug());
        $this->assertStringContainsString('romantic', $builder->suggestionSystemPrompt());
        $this->assertStringContainsString('estimated_cost', $builder->suggestionSystemPrompt());
    }

    public function test_vacation_builder_normalizes_highlights_and_days(): void
    {
        $builder = new VacationPromptBuilder;

        $this->assertSame('vacation', $builder->slug());
        $this->assertStringContainsString('estimated_cost_total', $builder->suggestionSystemPrompt());

        $payload = $builder->normalizeSuggestionPayload([
            'name' => 'Culture Week',
            'description' => 'Museums',
            'estimated_cost_total' => 2000,
            'highlights' => ['Museum', ''],
        ]);

        $this->assertSame(['Museum'], $payload['highlights']);

        $content = $builder->normalizeItineraryContent([
            'title' => 'Trip',
            'summary' => 'Fun',
            'days' => [
                [
                    'date' => 'Day 1',
                    'theme' => 'Arrive',
                    'stops' => [
                        ['time' => '10:00', 'name' => 'Hotel', 'activity' => 'Check in', 'notes' => ''],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('days', $content);
        $this->assertCount(1, $content['days']);
    }

    public function test_vacation_day_dates_use_the_trip_year_and_keep_a_per_person_price(): void
    {
        $builder = new VacationPromptBuilder;
        $session = new PlanSession([
            'answers' => [
                'dates' => json_encode(['start' => '2026-06-10', 'end' => '2026-06-12']),
            ],
        ]);
        $suggestion = new Suggestion([
            'payload' => ['name' => 'June trip', 'description' => 'A short stay'],
        ]);

        $this->assertStringContainsString('2026', $builder->itineraryUserPrompt($session, $suggestion));
        $this->assertStringContainsString('cost_per_person', $builder->itinerarySystemPrompt());

        $content = $builder->applyTripDates($session, $builder->normalizeItineraryContent([
            'title' => 'Trip',
            'summary' => 'Fun',
            'days' => [
                [
                    'date' => 'June 10, 2025',
                    'theme' => 'Arrive',
                    'stops' => [
                        ['time' => '10:00', 'name' => 'Market', 'activity' => 'Browse', 'notes' => '', 'cost_per_person' => 18],
                    ],
                ],
                [
                    'date' => 'June 11, 2025',
                    'theme' => 'Walk',
                    'stops' => [
                        ['time' => '11:00', 'name' => 'Park', 'activity' => 'Sit', 'notes' => '', 'cost_per_person' => 0],
                    ],
                ],
            ],
        ]));

        $this->assertSame('Wednesday, June 10, 2026', $content['days'][0]['date']);
        $this->assertSame('Thursday, June 11, 2026', $content['days'][1]['date']);
        $this->assertSame(18.0, $content['days'][0]['stops'][0]['cost_per_person']);
        $this->assertArrayNotHasKey('cost_per_person', $content['days'][1]['stops'][0]);

        $longStay = new PlanSession([
            'answers' => [
                'dates' => json_encode(['start' => '2026-06-01', 'end' => '2026-07-12']),
            ],
        ]);
        $weekly = $builder->applyTripDates($longStay, [
            'days' => [
                ['date' => 'Week 1, 2025', 'theme' => 'Start', 'stops' => []],
                ['date' => 'Week 2, 2025', 'theme' => 'Next', 'stops' => []],
            ],
        ]);

        $this->assertSame('Monday, June 1, 2026', $weekly['days'][0]['date']);
        $this->assertSame('Monday, June 8, 2026', $weekly['days'][1]['date']);
    }

    public function test_vacation_month_prompt_asks_for_one_stop_per_day(): void
    {
        $builder = new VacationPromptBuilder;
        $suggestion = new Suggestion([
            'payload' => ['name' => 'Month in Barcelona', 'description' => 'A long stay'],
        ]);

        $jsonPrompt = $builder->itineraryUserPrompt(new PlanSession([
            'answers' => [
                'dates' => json_encode(['start' => '2026-09-01', 'end' => '2026-09-30']),
            ],
        ]), $suggestion);
        $displayPrompt = $builder->itineraryUserPrompt(new PlanSession([
            'answers' => [
                'dates' => 'September 28 – October 28',
            ],
        ]), $suggestion);

        $this->assertStringContainsString('exactly 1 stop', $jsonPrompt);
        $this->assertStringContainsString('one days entry per day', $jsonPrompt);
        $this->assertStringContainsString('exactly 1 stop', $displayPrompt);
        $this->assertStringNotContainsString('3000 tokens', $jsonPrompt);
        $this->assertStringNotContainsString('3000 tokens', $builder->itinerarySystemPrompt());
    }

    public function test_vacation_week_prompt_allows_up_to_three_stops(): void
    {
        $builder = new VacationPromptBuilder;
        $prompt = $builder->itineraryUserPrompt(new PlanSession([
            'answers' => [
                'dates' => json_encode(['start' => '2026-06-10', 'end' => '2026-06-16']),
            ],
        ]), new Suggestion([
            'payload' => ['name' => 'Week in Barcelona', 'description' => 'A short stay'],
        ]));

        $this->assertStringContainsString('up to 3 stops', $prompt);
        $this->assertStringNotContainsString('exactly 1 stop', $prompt);
    }

    public function test_road_trip_builder_normalizes_gas_food_and_stops(): void
    {
        $builder = new RoadTripPromptBuilder;

        $this->assertSame('road_trip', $builder->slug());
        $this->assertStringContainsString('estimated_gas_cost', $builder->suggestionSystemPrompt());

        $payload = $builder->normalizeSuggestionPayload([
            'name' => 'Scenic Route',
            'description' => 'Hill country',
            'estimated_gas_cost' => 85,
            'estimated_food_cost' => 120,
            'total_drive_time' => '6h',
            'stops' => [
                ['name' => 'Viewpoint', 'closing_time' => 'sunset', 'duration' => '45 min'],
            ],
        ]);

        $this->assertSame(85.0, $payload['estimated_gas_cost']);
        $this->assertSame(120.0, $payload['estimated_food_cost']);
        $this->assertCount(1, $payload['stops']);
    }

    public function test_night_out_prompt_mixes_stops_only_when_open_to_suggestions_is_selected(): void
    {
        $builder = new NightOutPromptBuilder;
        $line = AbstractPlanPromptBuilder::OPEN_TO_SUGGESTIONS_LINE;
        $suggestion = new Suggestion([
            'payload' => ['name' => 'Jazz Night', 'description' => 'Live music', 'venues' => []],
        ]);
        $open = new PlanSession([
            'answers' => ['interests' => 'Open to suggestions, Live jazz', 'city' => 'Austin'],
        ]);
        $jazz = new PlanSession([
            'answers' => ['interests' => 'Live jazz', 'city' => 'Austin'],
        ]);

        $this->assertStringContainsString($line, $builder->suggestionUserPrompt($open));
        $this->assertStringContainsString($line, $builder->itineraryUserPrompt($open, $suggestion));
        $this->assertStringNotContainsString($line, $builder->suggestionUserPrompt($jazz));
        $this->assertStringNotContainsString($line, $builder->itineraryUserPrompt($jazz, $suggestion));
    }

    public function test_vacation_prompt_uses_the_hotel_only_when_a_stay_or_flight_is_answered(): void
    {
        $builder = new VacationPromptBuilder;
        $suggestion = new Suggestion([
            'payload' => ['name' => 'Week in Barcelona', 'description' => 'A short stay'],
        ]);
        $withStay = new PlanSession([
            'answers' => [
                'needs_hotel' => "I need a hotel",
                'hotel_location' => 'Hotel Arts',
                'hotel_shuttle' => 'Yes',
                'flying' => 'Yes',
            ],
        ]);
        $withoutStay = new PlanSession([
            'answers' => [
                'destination' => 'Barcelona',
                'needs_hotel' => "I don't need a hotel",
                'flying' => 'No',
            ],
        ]);

        $prompt = $builder->suggestionUserPrompt($withStay);

        $this->assertStringContainsString('Use the hotel at Hotel Arts as the base for the plan.', $prompt);
        $this->assertStringContainsString('Account for the hotel shuttle: Yes.', $prompt);
        $this->assertStringContainsString('They are flying, so account for the flight in the plan.', $prompt);
        $this->assertStringContainsString('Use the hotel at Hotel Arts as the base for the plan.', $builder->itineraryUserPrompt($withStay, $suggestion));
        $needsSuggestion = new PlanSession([
            'answers' => [
                'needs_hotel' => "I need a hotel",
                'hotel_pick' => '__suggest__',
                'flying' => 'No',
            ],
        ]);

        $this->assertStringContainsString(
            'Suggest 2 or 3 real hotels with https website links',
            $builder->suggestionUserPrompt($needsSuggestion),
        );
        $this->assertStringContainsString('Do not book the hotel.', $builder->suggestionUserPrompt($needsSuggestion));
        $this->assertStringNotContainsString('as the base for the plan', $builder->suggestionUserPrompt($withoutStay));
        $this->assertStringNotContainsString('account for the flight', $builder->itineraryUserPrompt($withoutStay, $suggestion));
    }

    public function test_road_trip_prompt_bases_gas_on_the_car_and_asks_for_a_stay_length(): void
    {
        $builder = new RoadTripPromptBuilder;
        $prompt = $builder->suggestionSystemPrompt();

        $this->assertStringContainsString('estimated_gas_cost', $prompt);
        $this->assertStringContainsString('Base estimated_gas_cost on the chosen car type.', $prompt);
        $this->assertStringContainsString('duration is how long to stay, such as 45 min.', $prompt);
        $this->assertStringContainsString('closing_time is when to be there.', $prompt);
        $this->assertStringContainsString('time is a clock time.', $builder->itinerarySystemPrompt());
        $this->assertStringContainsString('The notes include how long to stay.', $builder->itinerarySystemPrompt());
    }

    public function test_resolver_returns_builder_for_session_plan_type(): void
    {
        $planType = PlanType::factory()->create(['slug' => 'vacation', 'label' => 'Vacation']);
        $session = PlanSession::factory()->create(['plan_type_id' => $planType->id]);

        $resolver = new PlanPromptBuilderResolver;
        $builder = $resolver->resolve($session);

        $this->assertInstanceOf(VacationPromptBuilder::class, $builder);
    }
}
