<?php

namespace Tests\Unit\Mail;

use App\Mail\ItineraryMail;
use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItineraryMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_stops_for_night_out_style_itinerary(): void
    {
        $planType = PlanType::factory()->create([
            'slug' => 'night_out',
            'label' => 'Plan My Night Out',
        ]);

        $session = PlanSession::factory()->create([
            'user_id' => null,
            'plan_type_id' => $planType->id,
            'city' => 'Austin',
        ]);

        $itinerary = Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'content' => [
                'title' => 'Saturday Night Out in Austin',
                'summary' => '3-stop evening for 4 people.',
                'stops' => [
                    [
                        'time' => '19:30',
                        'name' => 'Blue Note Bar',
                        'activity' => 'Cocktails',
                        'notes' => 'Arrive early for seating.',
                    ],
                    [
                        'time' => '21:00',
                        'name' => 'Harbor Jazz Club',
                        'activity' => 'Live jazz set',
                        'notes' => 'Cover charge included.',
                    ],
                ],
            ],
        ]);

        $session->load('planType');
        $html = (new ItineraryMail($session, $itinerary))->render();
        $theme = PlanTypeMailTheme::for('night_out');

        $this->assertStringContainsString('PLNR', $html);
        $this->assertStringContainsString('Saturday Night Out in Austin', $html);
        $this->assertStringContainsString('Austin', $html);
        $this->assertStringContainsString('Blue Note Bar', $html);
        $this->assertStringContainsString('Harbor Jazz Club', $html);
        $this->assertStringContainsString('Live jazz set', $html);
        $this->assertStringContainsString($theme['accent'], $html);
        $this->assertStringContainsString('data-motif="moon"', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
        $this->assertStringNotContainsString('Laravel Logo', $html);
    }

    public function test_renders_days_for_vacation_style_itinerary(): void
    {
        $planType = PlanType::factory()->create([
            'slug' => 'vacation',
            'label' => 'Plan My Vacation',
        ]);

        $session = PlanSession::factory()->create([
            'user_id' => null,
            'plan_type_id' => $planType->id,
            'city' => 'Barcelona',
        ]);

        $itinerary = Itinerary::factory()->create([
            'plan_session_id' => $session->id,
            'content' => [
                'title' => '7 Days in Barcelona',
                'summary' => 'Culture and food focused week for two.',
                'days' => [
                    [
                        'date' => 'Day 1',
                        'theme' => 'Arrival and explore',
                        'stops' => [
                            [
                                'time' => '15:00',
                                'name' => 'Gothic Quarter',
                                'activity' => 'Walking tour',
                                'notes' => 'Check into hotel first.',
                            ],
                        ],
                    ],
                    [
                        'date' => 'Day 2',
                        'theme' => 'Gaudi day',
                        'stops' => [
                            [
                                'time' => '10:00',
                                'name' => 'Sagrada Familia',
                                'activity' => 'Guided visit',
                                'notes' => 'Pre-book tickets.',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $session->load('planType');
        $html = (new ItineraryMail($session, $itinerary))->render();
        $theme = PlanTypeMailTheme::for('vacation');

        $this->assertStringContainsString('7 Days in Barcelona', $html);
        $this->assertStringContainsString('Vacation', $html);
        $this->assertStringContainsString('Gothic Quarter', $html);
        $this->assertStringContainsString('Sagrada Familia', $html);
        $this->assertStringContainsString($theme['accent'], $html);
        $this->assertStringContainsString('data-motif="wave"', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
    }
}
