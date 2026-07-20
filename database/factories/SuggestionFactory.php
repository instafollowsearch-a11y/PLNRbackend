<?php

namespace Database\Factories;

use App\Models\PlanSession;
use App\Models\Suggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suggestion>
 */
class SuggestionFactory extends Factory
{
    protected $model = Suggestion::class;

    public function definition(): array
    {
        return [
            'plan_session_id' => PlanSession::factory(),
            'payload' => [
                'name' => fake()->company(),
                'description' => fake()->sentence(),
                'estimated_cost' => fake()->numberBetween(20, 150),
            ],
            'selected_at' => null,
        ];
    }
}
