<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\PlanSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_session_id' => PlanSession::factory(),
            'title' => fake()->sentence(3),
            'amount_cents' => 999,
            'currency' => 'usd',
            'status' => Booking::STATUS_CONFIRMED,
            'stripe_payment_intent_id' => 'pi_fake_123',
            'scheduled_for' => now()->addDay(),
            'metadata' => [],
        ];
    }
}
