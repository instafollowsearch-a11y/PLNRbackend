<?php

namespace Tests\Feature\Api\V1;

use App\Models\Booking;
use App\Models\Itinerary;
use App\Models\PaymentMethod;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    private function completedSession(?int $userId = null): PlanSession
    {
        $planType = PlanType::factory()->create(['slug' => 'night_out']);

        $session = PlanSession::factory()->create([
            'user_id' => $userId,
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_COMPLETED,
            'city' => 'Austin',
        ]);

        Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'email_sent_at' => now(),
            'content' => [
                'title' => 'Saturday Night Out',
                'summary' => 'Fun night',
                'stops' => [
                    ['time' => '20:00', 'name' => 'Jazz Club', 'activity' => 'Music', 'notes' => ''],
                ],
            ],
        ]);

        return $session;
    }

    public function test_booking_requires_authentication(): void
    {
        $session = $this->completedSession();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/bookings")->assertUnauthorized();
    }

    public function test_user_can_book_completed_plan_session(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        Sanctum::actingAs($user);

        $session = $this->completedSession();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/bookings")
            ->assertCreated()
            ->assertJsonPath('data.booking.status', Booking::STATUS_CONFIRMED)
            ->assertJsonPath('data.booking.amount_cents', 999);

        $this->assertSame($user->id, $session->fresh()->user_id);
    }

    public function test_booking_fails_without_payment_method(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = $this->completedSession();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/bookings")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_duplicate_booking_is_rejected(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        Sanctum::actingAs($user);

        $session = $this->completedSession($user->id);

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/bookings")->assertCreated();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/bookings")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_session']);
    }

    public function test_user_can_list_bookings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Booking::factory()->count(2)->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonCount(2, 'data.bookings');
    }

    public function test_failed_booking_can_be_retried(): void
    {
        $user = User::factory()->create();
        PaymentMethod::factory()->create(['user_id' => $user->id, 'is_default' => true]);
        Sanctum::actingAs($user);

        $session = $this->completedSession($user->id);

        Booking::factory()->create([
            'user_id' => $user->id,
            'plan_session_id' => $session->id,
            'status' => Booking::STATUS_FAILED,
        ]);

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/bookings")
            ->assertCreated()
            ->assertJsonPath('data.booking.status', Booking::STATUS_CONFIRMED);

        $this->assertSame(1, Booking::query()->where('plan_session_id', $session->id)->count());
    }

    public function test_list_bookings_rejects_invalid_per_page(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/bookings?per_page=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }
}
