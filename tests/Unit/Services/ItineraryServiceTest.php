<?php

namespace Tests\Unit\Services;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Services\AI\AnthropicClient;
use App\Services\AI\ItineraryService;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class ItineraryServiceTest extends TestCase
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

    public function test_generates_itinerary_from_anthropic_fixture(): void
    {
        $this->fakeAnthropicItinerary();

        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_SELECTED,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'group_size' => 4,
                'budget_per_person' => 65,
                'dates' => 'Saturday',
                'start_time' => '8:00 PM',
            ],
        ]);

        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'selected_at' => now(),
            'payload' => [
                'name' => 'Jazz and Bites',
                'description' => 'Cocktails and jazz',
                'time_slot' => '8:00 PM – 12:30 AM',
                'venues' => ['Blue Note Bar'],
            ],
        ]);

        $service = new ItineraryService(new AnthropicClient, new PlanPromptBuilderResolver);
        $itinerary = $service->generate($session, $suggestion);

        $this->assertSame('Saturday Night Out in Austin', $itinerary->content['title']);
        $this->assertCount(3, $itinerary->content['stops']);
        $this->assertSame(PlanSession::STATUS_ITINERARY, $session->fresh()->status);

        Http::assertSentCount(1);
    }
}
