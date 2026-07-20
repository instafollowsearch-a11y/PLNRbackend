<?php

namespace Database\Factories;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevicePushToken>
 */
class DevicePushTokenFactory extends Factory
{
    protected $model = DevicePushToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'ExponentPushToken['.fake()->uuid().']',
            'platform' => fake()->randomElement(['ios', 'android']),
        ];
    }
}
