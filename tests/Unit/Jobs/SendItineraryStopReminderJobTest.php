<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendItineraryStopReminderJob;
use App\Mail\ItineraryStopReminderMail;
use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use App\Models\PlanType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendItineraryStopReminderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_stop_reminder_email_once(): void
    {
        Mail::fake();

        $planType = PlanType::factory()->create(['slug' => 'date_night', 'label' => 'Plan My Date Night']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
        ]);
        $itinerary = Itinerary::factory()->create(['plan_session_id' => $session->id]);
        $reminder = ItineraryStopReminder::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_id' => $itinerary->id,
            'stop_name' => 'Wine Bar',
            'recipient_email' => 'guest@plnr.test',
            'plan_type_slug' => 'date_night',
            'email_sent_at' => null,
        ]);

        (new SendItineraryStopReminderJob($reminder->id))->handle();

        Mail::assertSent(ItineraryStopReminderMail::class, function (ItineraryStopReminderMail $mail) {
            return $mail->hasTo('guest@plnr.test')
                && $mail->reminder->stop_name === 'Wine Bar';
        });
        $this->assertNotNull($reminder->fresh()->email_sent_at);

        Mail::fake();
        (new SendItineraryStopReminderJob($reminder->id))->handle();
        Mail::assertNothingSent();
    }
}
