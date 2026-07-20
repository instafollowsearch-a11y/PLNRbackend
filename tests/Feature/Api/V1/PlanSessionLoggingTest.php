<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlanSessionLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_failure_is_logged(): void
    {
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'down'], 500),
            config('services.anthropic.url') => Http::response(['error' => 'down'], 500),
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

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($session): bool {
                return $message === 'plan_session.suggestions_failed'
                    && ($context['plan_session_uuid'] ?? null) === $session->uuid;
            });

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/suggestions")
            ->assertStatus(502);
    }
}
