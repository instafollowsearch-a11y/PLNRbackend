<?php

namespace Tests\Unit\Services;

use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Services\Bookings\ItineraryScheduleParser;
use App\Services\Reminders\ScheduleItineraryStopReminders;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleItineraryStopRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_reminders_thirty_minutes_before_upcoming_stops(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 12:00:00'));

        $planType = PlanType::factory()->create(['slug' => 'night_out', 'label' => 'Plan My Night Out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
            'answers' => ['dates' => '2026-06-10'],
        ]);
        $itinerary = Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'content' => [
                'title' => 'Night',
                'stops' => [
                    ['time' => '19:00', 'name' => 'Bar', 'activity' => 'Drinks', 'notes' => 'Early'],
                    ['time' => '21:00', 'name' => 'Club', 'activity' => 'Music'],
                ],
            ],
        ]);

        $service = new ScheduleItineraryStopReminders(new ItineraryScheduleParser);
        $service->forGuestSend($session, $itinerary, 'guest@plnr.test');

        $reminders = ItineraryStopReminder::query()->orderBy('stop_index')->get();

        $this->assertCount(2, $reminders);
        $this->assertSame('guest@plnr.test', $reminders[0]->recipient_email);
        $this->assertSame('Bar', $reminders[0]->stop_name);
        $this->assertSame('2026-06-10 19:00:00', $reminders[0]->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10 18:30:00', $reminders[0]->remind_at->format('Y-m-d H:i:s'));
        $this->assertSame('night_out', $reminders[0]->plan_type_slug);

        Carbon::setTestNow();
    }

    public function test_skips_past_stops(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 20:00:00'));

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
        ]);
        $itinerary = Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'content' => [
                'stops' => [
                    ['time' => '2026-06-09 18:00', 'name' => 'Past Stop'],
                    ['time' => '2026-06-10 22:00', 'name' => 'Future Stop'],
                ],
            ],
        ]);

        $service = new ScheduleItineraryStopReminders(new ItineraryScheduleParser);
        $service->forGuestSend($session, $itinerary, 'guest@plnr.test');

        $reminders = ItineraryStopReminder::query()->get();

        $this->assertCount(1, $reminders);
        $this->assertSame('Future Stop', $reminders[0]->stop_name);

        Carbon::setTestNow();
    }
}
