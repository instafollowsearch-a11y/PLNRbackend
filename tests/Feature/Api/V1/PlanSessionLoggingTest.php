<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsPlanEventCatalog;
use Tests\TestCase;

class PlanSessionLoggingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPlanEventCatalog;

    public function test_suggestions_failure_is_logged(): void
    {
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);

        $this->seedPlanEventCatalog();

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

    public function test_suggestions_returns_empty_catalog_message(): void
    {
        $this->mock(\App\Services\Events\PlanEventInventory::class, function ($mock): void {
            $mock->shouldReceive('forSession')->andThrow(\Illuminate\Validation\ValidationException::withMessages([
                'events' => ['We could not find enough upcoming events for this city. Try another city or different dates.'],
            ]));
        });

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'user_id' => null,
            'plan_type_id' => $planType->id,
            'answers' => [
                'city' => 'Callahan County',
                'interests' => 'live music',
                'group_size' => 2,
                'budget_per_person' => 50,
                'dates' => 'Saturday',
                'start_time' => '8 PM',
            ],
        ]);

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/suggestions")
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'We could not find enough upcoming events for this city. Try another city or different dates.',
            );
    }
}
