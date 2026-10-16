<?php

namespace Tests\Unit\Services\AI;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Services\AI\PlanConsistency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cheaper_request_drops_the_same_plan_and_higher_prices(): void
    {
        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'refinement_messages' => [
                ['role' => 'user', 'content' => 'Something cheaper', 'created_at' => now()->toIso8601String()],
            ],
        ]);
        Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => [
                'name' => 'Jazz and Bites',
                'estimated_cost_per_person' => 40,
            ],
        ]);
        $session->load('suggestions');

        $kept = PlanConsistency::cheaperThanCurrent($session, [
            [
                'name' => 'Jazz and Bites',
                'estimated_cost_per_person' => 55,
            ],
            [
                'name' => 'Rooftop night',
                'estimated_cost_per_person' => 48,
            ],
            [
                'name' => 'Taco walk',
                'estimated_cost_per_person' => 25,
            ],
        ]);

        $this->assertSame(['Taco walk'], array_column($kept, 'name'));
    }

    public function test_a_written_plan_must_use_a_place_from_the_chosen_card(): void
    {
        $this->assertFalse(PlanConsistency::usesPlaces([
            'stops' => [
                ['name' => 'Other Club'],
            ],
        ], ['Blue Note Bar']));

        $this->assertTrue(PlanConsistency::usesPlaces([
            'stops' => [
                ['name' => 'Blue Note Bar'],
            ],
        ], ['Blue Note Bar']));
    }
}
