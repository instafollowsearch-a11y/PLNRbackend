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

    public function test_all_required_event_names_must_appear(): void
    {
        $content = [
            'stops' => [
                ['name' => 'Blue Note Bar'],
                ['name' => 'Taco Row'],
            ],
        ];

        $this->assertTrue(PlanConsistency::usesAllPlaces($content, ['Blue Note Bar', 'Taco Row']));
        $this->assertFalse(PlanConsistency::usesAllPlaces($content, ['Blue Note Bar', 'Harbor Jazz Club']));

        $merged = PlanConsistency::mergeRequiredStops([
            'stops' => [
                ['name' => 'Other Club', 'time' => '6:00 PM'],
            ],
        ], [
            ['name' => 'Harbor Jazz Night', 'time' => '8:00 PM', 'event_id' => 9, 'venue_url' => 'https://example.test/jazz'],
        ]);

        $this->assertSame('Harbor Jazz Night', $merged['stops'][0]['name']);
        $this->assertSame(9, $merged['stops'][0]['event_id']);
    }

    public function test_event_names_match_by_meaning_not_exact_spelling(): void
    {
        $this->assertTrue(PlanConsistency::namesOverlap('Harbor Jazz Night', 'Jazz at the Harbor'));
        $this->assertTrue(PlanConsistency::namesOverlap('Blue Note Bar', 'The Blue Note'));
        $this->assertTrue(PlanConsistency::namesOverlap('Elephant Room', 'Live set at Elephant Room downstairs'));
        $this->assertFalse(PlanConsistency::namesOverlap('Harbor Jazz Night', 'Taco Row Food Hall'));

        $merged = PlanConsistency::mergeRequiredStops([
            'stops' => [
                [
                    'time' => '8:00 PM',
                    'name' => 'Jazz at the Harbor',
                    'activity' => 'Catch the early set, grab a high-top, and stay through the second song block.',
                    'notes' => 'Arrive 20 minutes early. Cover at the door.',
                ],
            ],
        ], [
            [
                'name' => 'Harbor Jazz Night',
                'time' => '8:00 PM',
                'activity' => 'Listed jazz show.',
                'notes' => 'Harbor Stage',
                'event_id' => 12,
                'venue_url' => 'https://example.test/harbor-jazz',
            ],
        ]);

        $this->assertSame('Harbor Jazz Night', $merged['stops'][0]['name']);
        $this->assertSame(12, $merged['stops'][0]['event_id']);
        $this->assertSame('https://example.test/harbor-jazz', $merged['stops'][0]['venue_url']);
        $this->assertStringContainsString('early set', $merged['stops'][0]['activity']);
    }

    public function test_catalog_events_do_not_steal_a_different_stop(): void
    {
        $merged = PlanConsistency::mergeRequiredStops([
            'stops' => [
                [
                    'time' => '5:40 PM',
                    'name' => 'A Cappella Books',
                    'activity' => 'Arrive at A Cappella Books a few minutes early for the author talk in Atlanta.',
                    'notes' => 'Historic Fourth Ward Park is later. Stay for the signing.',
                ],
                [
                    'time' => '8:45 PM',
                    'name' => 'Late Dinner at Ponce City Market Food Hall',
                    'activity' => 'Walk to Ponce City Market.',
                    'notes' => 'Botiwalla kati rolls.',
                ],
            ],
        ], [
            [
                'name' => 'Jazz in the Park ATL with a Taste of R&B',
                'time' => '5:45 PM',
                'activity' => 'Outdoor jazz in the park.',
                'notes' => 'Historic Fourth Ward Park, Atlanta, GA',
                'event_id' => 44,
            ],
        ]);

        $this->assertCount(3, $merged['stops']);
        $this->assertSame('Jazz in the Park ATL with a Taste of R&B', $merged['stops'][0]['name']);
        $this->assertSame(44, $merged['stops'][0]['event_id']);
        $this->assertSame('A Cappella Books', $merged['stops'][1]['name']);
        $this->assertStringContainsString('author talk', $merged['stops'][1]['activity']);
        $this->assertSame('Late Dinner at Ponce City Market Food Hall', $merged['stops'][2]['name']);
    }
}
