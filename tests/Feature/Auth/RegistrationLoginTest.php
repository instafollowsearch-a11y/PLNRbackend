<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_fetch_user_and_logout(): void
    {
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'PLNR User',
            'email' => 'user@plnr.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $registerResponse
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'user@plnr.test')
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonStructure(['data' => ['user', 'token'], 'message']);

        $token = $registerResponse->json('data.token');

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@plnr.test',
            'password' => 'password123',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.user.email', 'user@plnr.test');

        $token = $loginResponse->json('data.token');

        $this->getJson('/api/v1/user', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'user@plnr.test');

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
    }

    public function test_invalid_login_returns_validation_error(): void
    {
        User::factory()->create([
            'email' => 'existing@plnr.test',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'existing@plnr.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unauthenticated_user_endpoint_returns_unauthorized(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }
}
