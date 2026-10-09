<?php

namespace Tests\Unit\Mail;

use App\Mail\BookingReminderMail;
use App\Models\Booking;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingReminderMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_branded_booking_reminder_without_laravel_logo(): void
    {
        $planType = PlanType::factory()->create([
            'slug' => 'road_trip',
            'label' => 'Plan My Road Trip',
        ]);
        $user = User::factory()->create(['name' => 'Alex']);
        $session = PlanSession::factory()->create([
            'user_id' => $user->id,
            'plan_type_id' => $planType->id,
            'city' => 'Denver',
        ]);
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'plan_session_id' => $session->id,
            'title' => 'Denver weekend drive',
            'scheduled_for' => now()->addDay()->setTime(9, 0),
        ]);

        $html = (new BookingReminderMail($booking))->render();

        $this->assertStringContainsString('PLNR', $html);
        $this->assertStringContainsString('alt="PLNR"', $html);
        $this->assertStringContainsString('width="120"', $html);
        $this->assertStringContainsString('Denver weekend drive', $html);
        $this->assertStringContainsString('Alex', $html);
        $this->assertStringContainsString('This is a reminder for your upcoming plan.', $html);
        $this->assertStringContainsString('#F7F4F0', $html);
        $this->assertStringContainsString('#D4622A', $html);
        $this->assertStringContainsString('Road Trip', $html);
        $this->assertStringNotContainsString('data-motif', $html);
        $this->assertStringNotContainsString('margin-top:-28px', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
        $this->assertStringNotContainsString('Laravel Logo', $html);
    }
}
