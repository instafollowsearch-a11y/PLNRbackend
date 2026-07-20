<?php

namespace Tests\Unit\Services;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Services\Bookings\ItineraryScheduleParser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItineraryScheduleParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_time_only_in_past_rolls_to_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 15:00:00'));

        $parser = new ItineraryScheduleParser;
        $result = $parser->firstStopTime([
            'stops' => [['time' => '10:00', 'name' => 'Brunch']],
        ]);

        $this->assertSame('2026-06-09 10:00:00', $result->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_time_only_in_future_uses_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 15:00:00'));

        $parser = new ItineraryScheduleParser;
        $result = $parser->firstStopTime([
            'stops' => [['time' => '20:00', 'name' => 'Dinner']],
        ]);

        $this->assertSame('2026-06-08 20:00:00', $result->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_all_stops_parses_night_out_and_vacation_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 12:00:00'));

        $planType = PlanType::factory()->create(['slug' => 'vacation']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'answers' => ['dates' => '2026-07-01 to 2026-07-07'],
        ]);

        $parser = new ItineraryScheduleParser;

        $nightStops = $parser->allStops([
            'stops' => [
                ['time' => '19:00', 'name' => 'Bar', 'activity' => 'Drinks'],
                ['time' => '21:00', 'name' => 'Club', 'activity' => 'Music'],
            ],
        ], $session);

        $this->assertCount(2, $nightStops);
        $this->assertSame('Bar', $nightStops[0]['name']);
        $this->assertSame(0, $nightStops[0]['stop_index']);
        $this->assertNull($nightStops[0]['day_index']);

        $vacationStops = $parser->allStops([
            'days' => [
                [
                    'date' => 'Day 1',
                    'theme' => 'Arrive',
                    'stops' => [
                        ['time' => '15:00', 'name' => 'Old Town', 'activity' => 'Walk'],
                    ],
                ],
                [
                    'date' => 'Day 2',
                    'theme' => 'Museum',
                    'stops' => [
                        ['time' => '10:00', 'name' => 'Gallery', 'activity' => 'Visit'],
                    ],
                ],
            ],
        ], $session);

        $this->assertCount(2, $vacationStops);
        $this->assertSame('2026-07-01 15:00:00', $vacationStops[0]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-02 10:00:00', $vacationStops[1]['at']->format('Y-m-d H:i:s'));
        $this->assertSame(0, $vacationStops[0]['day_index']);
        $this->assertSame(1, $vacationStops[1]['day_index']);

        Carbon::setTestNow();
    }
}
