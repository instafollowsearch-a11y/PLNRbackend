<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendItineraryStopReminderJob;
use App\Mail\ItineraryStopReminderMail;
use App\Models\DevicePushToken;
use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $this->runJob($reminder->id);

        Mail::assertSent(ItineraryStopReminderMail::class, function (ItineraryStopReminderMail $mail) {
            return $mail->hasTo('guest@plnr.test')
                && $mail->reminder->stop_name === 'Wine Bar';
        });
        $this->assertNotNull($reminder->fresh()->email_sent_at);
        Http::assertNothingSent();

        Mail::fake();
        $this->runJob($reminder->id);
        Mail::assertNothingSent();
    }

    public function test_sends_one_email_and_one_push_then_nothing_on_the_second_run(): void
    {
        Mail::fake();
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok'],
                ],
            ]),
        ]);

        $user = User::factory()->create(['email' => 'guest@plnr.test']);
        DevicePushToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[stop]',
        ]);
        $planType = PlanType::factory()->create(['slug' => 'night_out', 'label' => 'Plan My Night Out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
        ]);
        $itinerary = Itinerary::factory()->create(['plan_session_id' => $session->id]);
        $reminder = ItineraryStopReminder::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_id' => $itinerary->id,
            'stop_name' => 'Elephant Room',
            'recipient_email' => 'guest@plnr.test',
            'plan_type_slug' => 'night_out',
            'email_sent_at' => null,
            'push_sent_at' => null,
        ]);

        $this->runJob($reminder->id);

        Mail::assertSent(ItineraryStopReminderMail::class, 1);
        Http::assertSent(function ($request) use ($session) {
            $body = $request->data();
            $message = $body[0] ?? [];

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && ($message['title'] ?? null) === 'Elephant Room'
                && ($message['data']['plan_session_uuid'] ?? null) === $session->uuid
                && ($message['data']['plan_type_slug'] ?? null) === 'night_out';
        });
        $this->assertNotNull($reminder->fresh()->email_sent_at);
        $this->assertNotNull($reminder->fresh()->push_sent_at);

        Mail::fake();
        Http::fake();
        $this->runJob($reminder->id);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_sends_only_the_email_when_the_recipient_has_no_token(): void
    {
        Mail::fake();
        Http::fake();

        User::factory()->create(['email' => 'guest@plnr.test']);
        $planType = PlanType::factory()->create(['slug' => 'night_out', 'label' => 'Plan My Night Out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
        ]);
        $itinerary = Itinerary::factory()->create(['plan_session_id' => $session->id]);
        $reminder = ItineraryStopReminder::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_id' => $itinerary->id,
            'recipient_email' => 'guest@plnr.test',
            'email_sent_at' => null,
        ]);

        $this->runJob($reminder->id);

        Mail::assertSent(ItineraryStopReminderMail::class, 1);
        Http::assertNothingSent();
        $this->assertNull($reminder->fresh()->push_sent_at);
    }

    private function runJob(int $reminderId): void
    {
        (new SendItineraryStopReminderJob($reminderId))->handle(app(ExpoPushService::class));
    }
}
