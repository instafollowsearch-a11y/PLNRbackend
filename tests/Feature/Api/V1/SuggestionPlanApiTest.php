<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class SuggestionPlanApiTest extends TestCase
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

    public function test_draft_is_stored_and_a_second_call_does_not_hit_the_model(): void
    {
        $this->fakeAnthropicItinerary();
        [$session, $suggestion] = $this->guestSuggestion();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/suggestions/{$suggestion->id}/plan")
            ->assertOk()
            ->assertJsonPath('data.suggestion.itinerary_content.title', 'Saturday Night Out in Austin');

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/suggestions/{$suggestion->id}/plan")
            ->assertOk()
            ->assertJsonPath('data.suggestion.itinerary_content.title', 'Saturday Night Out in Austin');

        $this->assertSame(PlanSession::STATUS_SUGGESTIONS, $session->fresh()->status);
        $this->assertNull($session->fresh()->itinerary);
        Http::assertSentCount(1);
    }

    public function test_choosing_copies_the_draft_onto_the_session_itinerary(): void
    {
        Http::fake();
        [$session, $suggestion] = $this->guestSuggestion([
            'title' => 'Jazz on 6th Street Crawl',
            'summary' => 'Three stops downtown.',
            'stops' => [
                ['time' => '20:00', 'name' => 'Elephant Room', 'activity' => 'Live jazz', 'notes' => 'Arrive early.'],
            ],
        ]);

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/select", [
            'suggestion_id' => $suggestion->id,
        ])->assertOk();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/itinerary")
            ->assertOk()
            ->assertJsonPath('data.itinerary.content.title', 'Jazz on 6th Street Crawl')
            ->assertJsonPath('data.plan_session.status', PlanSession::STATUS_ITINERARY);

        Http::assertNothingSent();
    }

    public function test_itinerary_keeps_building_after_the_response_is_sent(): void
    {
        config(['app.defer_http_work' => true]);
        [$session, $suggestion] = $this->guestSuggestion([
            'title' => 'Jazz on 6th Street Crawl',
            'summary' => 'Three stops downtown.',
            'stops' => [
                ['time' => '20:00', 'name' => 'Elephant Room', 'activity' => 'Live jazz', 'notes' => 'Arrive early.'],
            ],
        ]);

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/select", [
            'suggestion_id' => $suggestion->id,
        ])->assertOk();

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/itinerary")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        $this->assertSame(PlanSession::STATUS_ITINERARY, $session->fresh()->status);
        $this->assertNull($session->fresh()->generation_status);
        $this->assertSame('Jazz on 6th Street Crawl', $session->fresh()->itinerary?->content['title']);
    }

    public function test_a_failed_draft_leaves_the_other_ideas_in_place(): void
    {
        Http::fake([
            config('services.anthropic.url') => Http::response(['error' => 'unavailable'], 503),
        ]);

        [$session, $suggestion, $other] = $this->guestSuggestion(null, true);

        $this->postJson("/api/v1/plan-sessions/{$session->uuid}/suggestions/{$suggestion->id}/plan")
            ->assertStatus(502);

        $this->assertNull($suggestion->fresh()->itinerary_content);
        $this->assertSame('Other night', $other->fresh()->payload['name']);
        $this->assertCount(2, $session->fresh()->suggestions);
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @return array{0: PlanSession, 1: Suggestion, 2?: Suggestion}
     */
    private function guestSuggestion(?array $draft = null, bool $withOther = false): array
    {
        $planType = PlanType::factory()->create(['slug' => 'night_out']);
        $session = PlanSession::factory()->create([
            'user_id' => null,
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_SUGGESTIONS,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'group_size' => 4,
                'budget_per_person' => 65,
            ],
        ]);
        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $session->id,
            'payload' => [
                'name' => 'Jazz and Bites',
                'description' => 'Cocktails and jazz',
                'venues' => ['Blue Note Bar'],
            ],
            'itinerary_content' => $draft,
        ]);
        $other = null;

        if ($withOther) {
            $other = Suggestion::factory()->create([
                'plan_session_id' => $session->id,
                'payload' => ['name' => 'Other night', 'description' => 'Still here'],
            ]);
        }

        return $withOther ? [$session, $suggestion, $other] : [$session, $suggestion];
    }
}
