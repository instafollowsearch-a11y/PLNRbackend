<?php

namespace Tests\Unit\Services;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Services\AI\AnthropicClient;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\AI\SuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class SuggestionServiceTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);
    }

    public function test_generates_suggestions_from_anthropic_fixture(): void
    {
        $this->fakeAnthropicSuggestions();

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_READY,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'interests' => 'jazz and tacos',
                'group_size' => 4,
                'budget_per_person' => 65,
                'dates' => 'Saturday',
                'start_time' => '8:00 PM',
            ],
        ]);

        $service = new SuggestionService(app(AnthropicClient::class), new PlanPromptBuilderResolver);
        $suggestions = $service->generate($session);

        $this->assertGreaterThanOrEqual(3, $suggestions->count());
        $this->assertSame(PlanSession::STATUS_SUGGESTIONS, $session->fresh()->status);
        $this->assertSame('Jazz and Bites', $suggestions->first()->payload['name']);

        Http::assertSentCount(1);
    }
}
