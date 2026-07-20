<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanSession;
use App\Models\PlanType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rate_limiting.auth_per_minute' => 2,
            'rate_limiting.ai_per_hour' => 2,
            'rate_limiting.email_per_hour' => 2,
        ]);
    }

    public function test_auth_register_returns_429_when_limit_exceeded(): void
    {
        $payload = [
            'name' => 'Test User',
            'email' => 'rate-limit@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();
        $this->postJson('/api/v1/auth/register', [
            ...$payload,
            'email' => 'rate-limit-2@example.com',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/auth/register', [
            ...$payload,
            'email' => 'rate-limit-3@example.com',
        ]);

        $response->assertStatus(429)
            ->assertJson(['message' => 'Too many requests.']);
    }

    public function test_ai_suggestions_returns_429_when_limit_exceeded(): void
    {
        $this->fakeAnthropicSuggestions();

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'user_id' => null,
            'plan_type_id' => $planType->id,
            'answers' => [
                'city' => 'Austin',
                'interests' => 'jazz',
                'group_size' => 2,
                'budget_per_person' => 50,
                'dates' => 'Saturday',
                'start_time' => '8 PM',
            ],
        ]);

        $url = "/api/v1/plan-sessions/{$session->uuid}/suggestions";

        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();

        $this->postJson($url)
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many requests.']);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('auth');
        RateLimiter::clear('ai');
        RateLimiter::clear('email');

        parent::tearDown();
    }
}
