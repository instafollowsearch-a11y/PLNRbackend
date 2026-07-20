<?php

namespace Tests\Unit\Services;

use App\Models\Itinerary;
use App\Models\PaymentMethod;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use App\Services\Bookings\BookingFulfillmentService;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\ItineraryScheduleParser;
use App\Services\Payments\FakePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_urls_from_itinerary_stops(): void
    {
        $service = new BookingFulfillmentService;

        $urls = $service->extractUrls([
            'stops' => [
                ['name' => 'Jazz Club', 'venue_url' => 'https://example.com/jazz'],
            ],
        ]);

        $this->assertSame('https://example.com/jazz', $urls['venue_url']);
    }

    public function test_confirmed_booking_includes_fulfillment_metadata(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        PaymentMethod::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'user_id' => $user->id,
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_COMPLETED,
        ]);

        Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'email_sent_at' => now(),
            'content' => [
                'title' => 'Night Out',
                'stops' => [
                    [
                        'time' => '20:00',
                        'name' => 'Club',
                        'venue_url' => 'https://example.com/club',
                    ],
                ],
            ],
        ]);

        config(['services.booking.ops_email' => 'ops@plnr.app']);

        $service = new BookingService(
            new FakePaymentGateway,
            new ItineraryScheduleParser,
            new BookingFulfillmentService,
            app(\App\Services\Reminders\ScheduleItineraryStopReminders::class),
        );

        $booking = $service->createForSession($user, $session);

        $this->assertSame('pending_manual', $booking->fulfillment_status);
        $this->assertSame('https://example.com/club', $booking->metadata['venue_url']);
    }
}
