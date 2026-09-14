<?php

namespace Tests\Feature\Database;

use App\Models\PlanType;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_required_plan_types_without_demo_users(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertDatabaseHas('plan_types', ['slug' => 'night_out']);
        $this->assertDatabaseHas('plan_types', ['slug' => 'date_night']);
        $this->assertDatabaseHas('plan_types', ['slug' => 'vacation']);
        $this->assertDatabaseHas('plan_types', ['slug' => 'road_trip']);
        $this->assertSame(4, PlanType::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@plnr.test']);
    }
}
