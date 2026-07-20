<?php

namespace Tests\Unit\Services;

use App\Models\PlanSession;
use App\Models\PlanType;
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

    public function test_resolver_returns_builder_for_session_plan_type(): void
    {
        $planType = PlanType::factory()->create(['slug' => 'vacation', 'label' => 'Vacation']);
        $session = PlanSession::factory()->create(['plan_type_id' => $planType->id]);

        $resolver = new PlanPromptBuilderResolver;
        $builder = $resolver->resolve($session);

        $this->assertInstanceOf(VacationPromptBuilder::class, $builder);
    }
}
