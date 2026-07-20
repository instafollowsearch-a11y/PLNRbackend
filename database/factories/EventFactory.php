<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'fixture',
            'external_id' => fake()->unique()->uuid(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'city' => 'Austin',
            'venue_name' => fake()->company(),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 14)),
            'ends_at' => null,
            'url' => fake()->url(),
            'image_url' => null,
            'price_min' => 25,
            'price_max' => 75,
            'payload' => [],
        ];
    }
}
