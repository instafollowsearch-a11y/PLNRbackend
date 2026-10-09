<?php

namespace Tests\Unit\Services\AI;

use App\Models\Event;
use App\Models\PlanSession;
use App\Services\AI\Prompts\NightOutPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NightOutPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestion_user_prompt_includes_local_events(): void
    {
        Event::factory()->create([
            'city' => 'Austin',
            'title' => 'Skyline Sunset Sessions',
            'source' => 'fixture',
            'starts_at' => now()->addDays(2),
        ]);

        $session = PlanSession::factory()->create([
            'user_id' => null,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'interests' => 'jazz',
                'group_size' => 2,
                'budget_per_person' => 50,
                'dates' => 'Saturday',
                'start_time' => '8 PM',
            ],
        ]);

        $builder = new NightOutPromptBuilder;
        $prompt = $builder->suggestionUserPrompt($session);

        $this->assertStringContainsString('Skyline Sunset Sessions', $prompt);
        $this->assertStringContainsString('Local upcoming events:', $prompt);
    }

    public function test_a_street_address_still_matches_events_in_that_city(): void
    {
        Event::factory()->create([
            'city' => 'Atlanta',
            'title' => 'Westview Jazz',
            'source' => 'fixture',
            'starts_at' => now()->addDays(2),
        ]);

        $session = PlanSession::factory()->create([
            'user_id' => null,
            'city' => '840 Westview Drive Southwest, Atlanta, Georgia',
            'answers' => [
                'city' => '840 Westview Drive Southwest, Atlanta, Georgia',
                'interests' => 'jazz',
                'group_size' => 2,
                'budget_per_person' => 50,
                'dates' => 'Saturday',
                'start_time' => '8 PM',
            ],
        ]);

        $prompt = (new NightOutPromptBuilder)->suggestionUserPrompt($session);

        $this->assertStringContainsString('840 Westview Drive Southwest, Atlanta, Georgia', $prompt);
        $this->assertStringContainsString('Westview Jazz', $prompt);
    }
}
