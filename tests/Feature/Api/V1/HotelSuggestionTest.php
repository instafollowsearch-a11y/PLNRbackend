<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HotelSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);
    }

    public function test_returns_https_hotel_links_for_a_destination(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'hotels' => [
                            ['name' => 'Hotel Arts', 'url' => 'https://www.hotelartsbarcelona.com'],
                            ['name' => 'Casa Camper', 'url' => 'http://casacamper.com'],
                            ['name' => 'El Palace', 'url' => 'https://www.hotelpalacebarcelona.com'],
                            ['name' => 'Extra', 'url' => 'https://example.com'],
                        ],
                    ]),
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/hotel-suggestions', [
            'destination' => 'Barcelona',
        ])->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Hotel Arts')
            ->assertJsonPath('data.0.url', 'https://www.hotelartsbarcelona.com')
            ->assertJsonPath('data.1.name', 'El Palace')
            ->assertJsonMissing(['name' => 'Casa Camper']);
    }

    public function test_returns_an_empty_list_when_the_planner_fails(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'unavailable'], 500),
        ]);

        $this->postJson('/api/v1/hotel-suggestions', [
            'destination' => 'Barcelona',
        ])->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_requires_a_destination(): void
    {
        $this->postJson('/api/v1/hotel-suggestions', [
            'destination' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['destination']);
    }
}
