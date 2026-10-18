<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\PlanPacing;
use Tests\TestCase;

class PlanPacingTest extends TestCase
{
    public function test_a_two_hour_gap_on_a_local_night_is_pulled_in(): void
    {
        $paced = PlanPacing::tighten([
            'stops' => [
                ['time' => '6:00 PM', 'name' => 'First'],
                ['time' => '8:00 PM', 'name' => 'Second'],
            ],
        ], 'night_out');

        $this->assertSame('6:00 PM', $paced['stops'][0]['time']);
        $this->assertSame('7:15 PM', $paced['stops'][1]['time']);
    }

    public function test_a_short_hop_and_a_road_day_stay_as_written(): void
    {
        $local = PlanPacing::tighten([
            'stops' => [
                ['time' => '19:30', 'name' => 'First'],
                ['time' => '21:00', 'name' => 'Second'],
            ],
        ], 'date_night');
        $drive = PlanPacing::tighten([
            'stops' => [
                ['time' => '8:00 AM', 'name' => 'Depart'],
                ['time' => '11:00 AM', 'name' => 'Lunch'],
            ],
        ], 'road_trip');

        $this->assertSame('21:00', $local['stops'][1]['time']);
        $this->assertSame('11:00 AM', $drive['stops'][1]['time']);
    }
}
