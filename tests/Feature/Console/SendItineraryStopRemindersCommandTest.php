<?php

namespace Tests\Feature\Console;

use App\Jobs\SendItineraryStopReminderJob;
use App\Mail\ItineraryStopReminderMail;
use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use App\Models\PlanType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendItineraryStopRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_due_stop_reminders(): void
    {
        Queue::fake();

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create(['plan_type_id' => $planType->id]);
        $itinerary = Itinerary::factory()->create(['plan_session_id' => $session->id]);

        $due = ItineraryStopReminder::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_id' => $itinerary->id,
            'stop_index' => 0,
            'remind_at' => now()->subMinutes(10),
            'scheduled_at' => now()->addMinutes(20),
            'email_sent_at' => null,
        ]);

        ItineraryStopReminder::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_id' => $itinerary->id,
            'stop_index' => 1,
            'remind_at' => now()->addHours(2),
            'scheduled_at' => now()->addHours(3),
            'email_sent_at' => null,
        ]);

        $this->artisan('itineraries:send-stop-reminders')->assertSuccessful();

        Queue::assertPushed(SendItineraryStopReminderJob::class, function (SendItineraryStopReminderJob $job) use ($due) {
            return $job->reminderId === $due->id;
        });
        Queue::assertPushed(SendItineraryStopReminderJob::class, 1);
    }
}
