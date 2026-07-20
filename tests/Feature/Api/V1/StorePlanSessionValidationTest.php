<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorePlanSessionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['night_out', 'date_night', 'vacation', 'road_trip'] as $slug) {
            PlanType::factory()->create(['slug' => $slug, 'label' => $slug]);
        }
    }

    public function test_rejects_unknown_plan_type(): void
    {
        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'beach_day',
            'answers' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_type']);
    }

    public function test_date_night_requires_valid_gender_and_event_count(): void
    {
        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'date_night',
            'answers' => [
                'self_gender' => 'invalid',
                'partner_gender' => 'man',
                'city' => 'Austin',
                'timeframe' => 'Saturday',
                'partner_interests' => 'art',
                'budget' => 0,
                'event_count' => 11,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'answers.self_gender',
                'answers.budget',
                'answers.event_count',
            ]);
    }

    public function test_vacation_requires_destination_and_group_size(): void
    {
        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'vacation',
            'answers' => [
                'destination' => '',
                'dates' => 'June',
                'budget' => 100,
                'age_range' => '30s',
                'interests' => 'food',
                'group_size' => 0,
                'activity_mix' => 'relax',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'answers.destination',
                'answers.group_size',
            ]);
    }

    public function test_road_trip_with_stops_requires_stop_interests(): void
    {
        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'road_trip',
            'answers' => [
                'start_location' => 'Austin',
                'end_location' => 'Dallas',
                'arrival_date' => 'Saturday',
                'departure_time' => '8 AM',
                'car_type' => 'SUV',
                'stop_preference' => 'with_stops',
                'interests' => 'music',
                'food_preferences' => 'BBQ',
                'group_size' => 2,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.stop_interests']);
    }
}
