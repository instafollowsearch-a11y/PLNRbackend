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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_confirmed_booking_with_flat_fee(): void
    {
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
                'stops' => [['time' => '20:00', 'name' => 'Club', 'activity' => 'Music', 'notes' => '']],
            ],
        ]);

        $service = new BookingService(
            new FakePaymentGateway,
            new ItineraryScheduleParser,
            new BookingFulfillmentService,
            app(\App\Services\Reminders\ScheduleItineraryStopReminders::class),
        );
        $booking = $service->createForSession($user, $session);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(999, $booking->amount_cents);
    }

    public function test_requires_payment_method(): void
    {
        $user = User::factory()->create();
        $session = PlanSession::factory()->create(['user_id' => $user->id]);
        Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'email_sent_at' => now(),
            'content' => ['title' => 'Plan'],
        ]);

        $service = new BookingService(
            new FakePaymentGateway,
            new ItineraryScheduleParser,
            new BookingFulfillmentService,
            app(\App\Services\Reminders\ScheduleItineraryStopReminders::class),
        );

        $this->expectException(ValidationException::class);
        $service->createForSession($user, $session);
    }
}
