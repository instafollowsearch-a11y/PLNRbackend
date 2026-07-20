<?php

namespace Tests\Feature\Api\V1;

use App\Mail\ItineraryMail;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class PlanSessionApiTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    /** @var array<string, PlanType> */
    private array $planTypes = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
        ]);

        foreach (['night_out', 'date_night', 'vacation', 'road_trip'] as $slug) {
            $this->planTypes[$slug] = PlanType::factory()->create([
                'slug' => $slug,
                'label' => match ($slug) {
                    'night_out' => 'Plan My Night Out',
                    'date_night' => 'Plan My Date Night',
                    'vacation' => 'Plan My Vacation',
                    'road_trip' => 'Plan My Road Trip',
                },
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validAnswers(string $planType): array
    {
        return match ($planType) {
            'night_out' => [
                'city' => 'Austin',
                'interests' => 'live jazz and tacos',
                'group_size' => 4,
                'budget_per_person' => 65,
                'dates' => 'Saturday, June 14',
                'start_time' => '8:00 PM',
            ],
            'date_night' => [
                'self_gender' => 'woman',
                'partner_gender' => 'man',
                'city' => 'Austin',
                'timeframe' => 'Saturday, 10 AM – 10 PM',
                'partner_interests' => 'art museums and wine',
                'budget' => 200,
                'event_count' => 3,
            ],
            'vacation' => [
                'destination' => 'Barcelona',
                'dates' => 'June 10 – June 17',
                'budget' => 3000,
                'age_range' => '25–34',
                'interests' => 'food and architecture',
                'group_size' => 2,
                'activity_mix' => 'Active mornings, relax afternoons',
            ],
            'road_trip' => [
                'start_location' => 'Austin',
                'end_location' => 'Dallas',
                'arrival_date' => 'Saturday, June 14',
                'departure_time' => '8:00 AM',
                'car_type' => 'SUV',
                'stop_preference' => 'with_stops',
                'stop_interests' => 'scenic viewpoints and BBQ',
                'interests' => 'country music and hiking',
                'food_preferences' => 'BBQ and diners',
                'group_size' => 3,
            ],
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadFixture(string $filename): array
    {
        return json_decode(
            file_get_contents(base_path("tests/Fixtures/openai/{$filename}")),
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function anthropicFromFixture(string $filename): array
    {
        return $this->anthropicResponseFromOpenAiFixture($filename);
    }

    private function completePlanFlow(
        string $planType,
        string $suggestionsFixture,
        string $itineraryFixture,
        string $expectedItineraryTitle,
    ): string {
        Mail::fake();

        Http::fake([
            config('services.anthropic.url') => Http::sequence()
                ->push($this->anthropicFromFixture($suggestionsFixture))
                ->push($this->anthropicFromFixture($suggestionsFixture))
                ->push($this->anthropicFromFixture($itineraryFixture)),
        ]);

        $create = $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => $planType,
            'answers' => $this->validAnswers($planType),
        ]);

        $create->assertCreated();
        $uuid = $create->json('data.plan_session.uuid');
        $this->assertNotEmpty($uuid);

        $this->postJson("/api/v1/plan-sessions/{$uuid}/suggestions")
            ->assertOk()
            ->assertJsonStructure(['data' => ['suggestions']]);

        $this->postJson("/api/v1/plan-sessions/{$uuid}/refine", [
            'message' => 'Something more low-key please',
        ])->assertOk();

        $suggestions = $this->getJson("/api/v1/plan-sessions/{$uuid}")
            ->assertOk()
            ->json('data.plan_session.suggestions');

        $suggestionId = $suggestions[0]['id'];

        $this->postJson("/api/v1/plan-sessions/{$uuid}/select", [
            'suggestion_id' => $suggestionId,
        ])->assertOk();

        $this->postJson("/api/v1/plan-sessions/{$uuid}/itinerary")
            ->assertOk()
            ->assertJsonPath('data.itinerary.content.title', $expectedItineraryTitle);

        $this->postJson("/api/v1/plan-sessions/{$uuid}/send-email", [
            'email' => 'guest@plnr.test',
            'phone' => '+15551234567',
        ])->assertOk()
            ->assertJsonPath('data.phone', '+15551234567')
            ->assertJsonPath('data.email', 'guest@plnr.test');

        Mail::assertSent(ItineraryMail::class, function (ItineraryMail $mail) {
            return $mail->hasTo('guest@plnr.test');
        });

        $session = PlanSession::query()->where('uuid', $uuid)->first();
        $this->assertSame('guest@plnr.test', $session?->recipient_email);
        $this->assertSame('+15551234567', $session?->recipient_phone);
        $this->assertSame(
            PlanSession::STATUS_COMPLETED,
            $session?->status,
        );

        return $uuid;
    }

    public function test_guest_can_create_and_complete_night_out_flow(): void
    {
        $this->completePlanFlow(
            'night_out',
            'suggestions_response.json',
            'itinerary_response.json',
            'Saturday Night Out in Austin',
        );
    }

    public function test_guest_can_create_and_complete_date_night_flow(): void
    {
        $this->completePlanFlow(
            'date_night',
            'date_night_suggestions_response.json',
            'date_night_itinerary_response.json',
            'Romantic Saturday in Austin',
        );
    }

    public function test_guest_can_create_and_complete_vacation_flow(): void
    {
        $uuid = $this->completePlanFlow(
            'vacation',
            'vacation_suggestions_response.json',
            'vacation_itinerary_response.json',
            '7 Days in Barcelona',
        );

        $content = PlanSession::query()
            ->where('uuid', $uuid)
            ->first()
            ?->itinerary
            ?->content;

        $this->assertIsArray($content);
        $this->assertArrayHasKey('days', $content);
        $this->assertNotEmpty($content['days']);
    }

    public function test_guest_can_create_and_complete_road_trip_flow_with_stops(): void
    {
        $uuid = $this->completePlanFlow(
            'road_trip',
            'road_trip_suggestions_response.json',
            'road_trip_itinerary_response.json',
            'Austin to Dallas Road Trip',
        );

        $payload = PlanSession::query()
            ->where('uuid', $uuid)
            ->first()
            ?->suggestions()
            ->first()
            ?->payload;

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('estimated_gas_cost', $payload);
        $this->assertArrayHasKey('estimated_food_cost', $payload);
        $this->assertArrayHasKey('stops', $payload);
    }

    public function test_road_trip_straight_path_does_not_require_stop_interests(): void
    {
        Http::fake([
            config('services.anthropic.url') => Http::response(
                $this->anthropicResponseFromOpenAiFixture('road_trip_suggestions_response.json'),
            ),
        ]);

        $answers = $this->validAnswers('road_trip');
        $answers['stop_preference'] = 'straight';
        unset($answers['stop_interests']);

        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'road_trip',
            'answers' => $answers,
        ])->assertCreated();
    }

    public function test_authenticated_user_session_is_linked_and_protected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => $this->validAnswers('night_out'),
        ])->assertCreated();

        $uuid = $create->json('data.plan_session.uuid');

        $this->assertSame($user->id, PlanSession::query()->where('uuid', $uuid)->value('user_id'));

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->getJson("/api/v1/plan-sessions/{$uuid}")->assertForbidden();
    }

    public function test_validation_errors_on_invalid_answers(): void
    {
        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => [
                'city' => '',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'answers.city',
                'answers.interests',
                'answers.group_size',
                'answers.budget_per_person',
                'answers.dates',
                'answers.start_time',
            ]);
    }

    public function test_invalid_uuid_returns_not_found(): void
    {
        $this->getJson('/api/v1/plan-sessions/00000000-0000-0000-0000-000000000099')
            ->assertNotFound();
    }
}
