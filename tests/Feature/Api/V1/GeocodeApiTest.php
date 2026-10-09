<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeApiTest extends TestCase
{
    public function test_reverse_returns_the_city_for_a_map_pin(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/reverse*' => Http::response([
                'lat' => '30.2672',
                'lon' => '-97.7431',
                'display_name' => 'Austin, Travis County, Texas, United States',
                'address' => [
                    'city' => 'Austin',
                    'state' => 'Texas',
                    'country' => 'United States',
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/geocode/reverse?lat=30.2672&lon=-97.7431');

        $response
            ->assertOk()
            ->assertJsonPath('data.label', 'Austin, Texas')
            ->assertJsonPath('data.lat', 30.2672)
            ->assertJsonPath('data.lon', -97.7431);
    }

    public function test_reverse_returns_null_when_the_lookup_fails(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([], 503),
        ]);

        $this->getJson('/api/v1/geocode/reverse?lat=30.2672&lon=-97.7431')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_search_returns_matching_places(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([
                [
                    'lat' => '30.2672',
                    'lon' => '-97.7431',
                    'display_name' => 'Austin, Texas, United States',
                    'address' => [
                        'city' => 'Austin',
                        'state' => 'Texas',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/v1/geocode/search?q=Austin')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Austin, Texas');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'accept-language=en');
        });
    }

    public function test_search_keeps_a_street_address(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([
                [
                    'lat' => '33.7467075',
                    'lon' => '-84.4151622',
                    'display_name' => '840, Westview Drive Southwest, Atlanta, Georgia, United States',
                    'address' => [
                        'house_number' => '840',
                        'road' => 'Westview Drive Southwest',
                        'city' => 'Atlanta',
                        'state' => 'Georgia',
                        'country' => 'United States',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/v1/geocode/search?q=840+westview+dr+atlanta+Ga')
            ->assertOk()
            ->assertJsonPath('data.0.label', '840 Westview Drive Southwest, Atlanta, Georgia');
    }

    public function test_search_skips_a_short_query(): void
    {
        Http::fake();

        $this->getJson('/api/v1/geocode/search?q=A')
            ->assertOk()
            ->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    public function test_reverse_rejects_coordinates_outside_the_map(): void
    {
        $this->getJson('/api/v1/geocode/reverse?lat=120&lon=-97')
            ->assertUnprocessable();
    }
}
