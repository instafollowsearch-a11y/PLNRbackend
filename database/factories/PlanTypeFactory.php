<?php

namespace Database\Factories;

use App\Models\PlanType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanType>
 */
class PlanTypeFactory extends Factory
{
    protected $model = PlanType::class;

    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->words(2, true));

        return [
            'slug' => $slug,
            'label' => Str::headline(str_replace('_', ' ', $slug)),
            'description' => fake()->sentence(),
        ];
    }
}
