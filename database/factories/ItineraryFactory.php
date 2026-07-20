<?php

namespace Database\Factories;

use App\Models\Itinerary;
use App\Models\PlanSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Itinerary>
 */
class ItineraryFactory extends Factory
{
    protected $model = Itinerary::class;

    public function definition(): array
    {
        return [
            'plan_session_id' => PlanSession::factory(),
            'content' => [
                'title' => fake()->sentence(3),
                'stops' => [
                    ['time' => '19:00', 'activity' => fake()->words(3, true)],
                    ['time' => '21:00', 'activity' => fake()->words(3, true)],
                ],
            ],
            'email_sent_at' => null,
        ];
    }
}
