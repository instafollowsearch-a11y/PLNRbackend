<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_config_returns_fee_and_currency(): void
    {
        config([
            'services.booking.fee_cents' => 999,
            'services.booking.currency' => 'usd',
        ]);

        $this->getJson('/api/v1/booking-config')
            ->assertOk()
            ->assertJsonPath('data.fee_cents', 999)
            ->assertJsonPath('data.currency', 'usd');
    }
}
