<?php

namespace Tests\Unit\Mail;

use App\Mail\ItineraryStopReminderMail;
use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItineraryStopReminderMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_plan_accent_motif_and_stop_details_without_laravel_logo(): void
    {
        $planType = PlanType::factory()->create([
            'slug' => 'date_night',
            'label' => 'Plan My Date Night',
        ]);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
        ]);
        $itinerary = Itinerary::factory()->create(['plan_session_id' => $session->id]);
        $reminder = ItineraryStopReminder::factory()->create([
            'plan_session_id' => $session->id,
            'itinerary_id' => $itinerary->id,
            'stop_name' => 'Wine Bar',
            'activity' => 'Tasting flight',
            'notes' => 'Ask for the corner table.',
            'plan_type_slug' => 'date_night',
            'scheduled_at' => now()->addDay()->setTime(19, 30),
        ]);

        $html = (new ItineraryStopReminderMail($reminder))->render();
        $theme = PlanTypeMailTheme::for('date_night');

        $this->assertStringContainsString('PLNR', $html);
        $this->assertStringContainsString($theme['accent'], $html);
        $this->assertStringContainsString('data-motif="heart"', $html);
        $this->assertStringContainsString('Wine Bar', $html);
        $this->assertStringContainsString('Tasting flight', $html);
        $this->assertStringContainsString('Austin', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
        $this->assertStringNotContainsString('Laravel Logo', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}]/u', $html);
    }
}
