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
                'name' => 'Jazz and Bites',
                'estimated_cost_per_person' => 20,
            ],
            [
                'name' => 'Same price night',
                'estimated_cost_per_person' => 40,
            ],
            [
                'name' => 'Free night',
                'estimated_cost_per_person' => 0,
            ],
            [
                'name' => 'Taco walk',
                'estimated_cost_per_person' => 25,
            ],
        ]);

        $this->assertSame(['Taco walk'], array_column($kept, 'name'));
        $this->assertStringContainsString('must cost less than $40', PlanConsistency::cheaperInstruction($session));
    }

    public function test_a_cheaper_request_drops_the_same_places_at_a_higher_price_and_a_low_teaser(): void
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
                'venues' => ['Blue Note Bar'],
            ],
        ]);
        $session->load('suggestions');

        $kept = PlanConsistency::cheaperThanCurrent($session, [
            [
                'name' => 'A different title',
                'estimated_cost_per_person' => 55,
                'venues' => ['Blue Note Bar'],
            ],
            [
                'name' => 'Do this for $10',
                'description' => 'Do this for $10. Then a five-course dinner for $55.',
                'estimated_cost_per_person' => 10,
                'venues' => ['The Grand Tasting Room'],
            ],
            [
                'name' => 'Taco walk',
                'description' => 'A short cheap night for $18.',
                'estimated_cost_per_person' => 18,
                'venues' => ['Taco Row'],
            ],
        ]);

        $this->assertSame(['Taco walk'], array_column($kept, 'name'));
    }

    public function test_a_mismatched_draft_keeps_the_picked_places(): void
    {
        $anchored = PlanConsistency::anchorPlaces([
            'stops' => [
                ['name' => 'Other Club', 'time' => '6:00 PM'],
                ['name' => 'Far Restaurant', 'time' => '8:00 PM'],
            ],
        ], ['Blue Note Bar', 'Taco Row']);

        $this->assertSame('Blue Note Bar', $anchored['stops'][0]['name']);
        $this->assertSame('Taco Row', $anchored['stops'][1]['name']);
        $this->assertTrue(PlanConsistency::usesPlaces($anchored, ['Blue Note Bar']));
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
