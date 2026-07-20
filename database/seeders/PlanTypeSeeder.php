<?php

namespace Database\Seeders;

use App\Models\PlanType;
use Illuminate\Database\Seeder;

class PlanTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'slug' => 'night_out',
                'label' => 'Plan My Night Out',
                'description' => 'Group night out planning with interests, budget, and timing.',
            ],
            [
                'slug' => 'date_night',
                'label' => 'Plan My Date Night',
                'description' => 'Romantic date planning tailored to you and your partner.',
            ],
            [
                'slug' => 'vacation',
                'label' => 'Plan My Vacation',
                'description' => 'Multi-day trip planning with activity and relax balance.',
            ],
            [
                'slug' => 'road_trip',
                'label' => 'Plan My Road Trip',
                'description' => 'Route planning with stops, gas, food, and timing.',
            ],
        ];

        foreach ($types as $type) {
            PlanType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                $type,
            );
        }
    }
}
