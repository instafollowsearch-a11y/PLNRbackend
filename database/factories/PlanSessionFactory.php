<?php

namespace Database\Factories;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanSession>
 */
class PlanSessionFactory extends Factory
{
    protected $model = PlanSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_type_id' => PlanType::factory(),
            'status' => 'draft',
            'city' => fake()->city(),
            'answers' => [
                'interests' => fake()->words(3),
                'budget' => fake()->numberBetween(50, 200),
            ],
        ];
    }
}
