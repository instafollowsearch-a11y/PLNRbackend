<?php

namespace Database\Factories;

use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItineraryStopReminder>
 */
class ItineraryStopReminderFactory extends Factory
{
    protected $model = ItineraryStopReminder::class;

    public function definition(): array
    {
        $scheduledAt = now()->addHours(2);

        return [
            'plan_session_id' => PlanSession::factory(),
            'itinerary_id' => Itinerary::factory(),
            'booking_id' => null,
            'stop_index' => 0,
            'day_index' => null,
            'stop_name' => fake()->words(2, true),
            'activity' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'scheduled_at' => $scheduledAt,
            'remind_at' => $scheduledAt->copy()->subMinutes(30),
            'recipient_email' => fake()->safeEmail(),
            'plan_type_slug' => 'night_out',
            'email_sent_at' => null,
        ];
    }
}
