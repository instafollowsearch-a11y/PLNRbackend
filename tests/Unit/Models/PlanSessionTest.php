<?php

namespace Tests\Unit\Models;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_session_relationships(): void
    {
        $user = User::factory()->create();
        $planType = PlanType::factory()->create();
        $planSession = PlanSession::factory()->create([
            'user_id' => $user->id,
            'plan_type_id' => $planType->id,
        ]);

        $suggestion = Suggestion::factory()->create([
            'plan_session_id' => $planSession->id,
        ]);

        $itinerary = Itinerary::factory()->create([
            'plan_session_id' => $planSession->id,
        ]);

        $this->assertTrue($planSession->user->is($user));
        $this->assertTrue($planSession->planType->is($planType));
        $this->assertTrue($planSession->suggestions->contains($suggestion));
        $this->assertTrue($planSession->itinerary->is($itinerary));
    }
}
