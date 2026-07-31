<?php

namespace Tests\Feature\Api\V1;

use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanQuotaAndAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private PlanType $planType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\AppSettingsSeeder::class);
        $this->planType = PlanType::factory()->create([
            'slug' => 'night_out',
            'label' => 'Plan My Night Out',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        return [
            'city' => 'Austin',
            'interests' => 'jazz',
            'group_size' => 2,
            'budget_per_person' => 50,
            'dates' => 'Saturday',
            'start_time' => '8:00 PM',
        ];
    }

    public function test_guest_is_limited_by_ip_per_day(): void
    {
        app(AppSettings::class)->set(AppSettings::FREE_PLANS_PER_DAY, 2);

        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => $this->answers(),
        ])->assertCreated();

        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => $this->answers(),
        ])->assertCreated();

        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => $this->answers(),
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Daily free plan limit reached. Create an account or try again tomorrow.');
    }

    public function test_authenticated_user_is_limited_by_account_not_ip(): void
    {
        app(AppSettings::class)->set(AppSettings::FREE_PLANS_PER_DAY, 1);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => $this->answers(),
        ])
            ->assertCreated();

        $this->assertDatabaseHas('plan_sessions', [
            'user_id' => $user->id,
        ]);

        $this->postJson('/api/v1/plan-sessions', [
            'plan_type' => 'night_out',
            'answers' => $this->answers(),
        ])->assertStatus(429);
    }

    public function test_user_can_list_and_claim_plan_sessions(): void
    {
        $user = User::factory()->create();
        $owned = PlanSession::factory()->create([
            'user_id' => $user->id,
            'plan_type_id' => $this->planType->id,
            'status' => PlanSession::STATUS_READY,
            'city' => 'Austin',
        ]);
        $guest = PlanSession::factory()->create([
            'user_id' => null,
            'plan_type_id' => $this->planType->id,
            'status' => PlanSession::STATUS_READY,
            'city' => 'Dallas',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/plan-sessions')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $owned->uuid]);

        $this->postJson("/api/v1/plan-sessions/{$guest->uuid}/claim")
            ->assertOk()
            ->assertJsonPath('data.plan_session.uuid', $guest->uuid);

        $this->assertDatabaseHas('plan_sessions', [
            'uuid' => $guest->uuid,
            'user_id' => $user->id,
        ]);
    }

    public function test_admin_can_manage_settings_and_users(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'member@plnr.test']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.users_total', 2);

        $this->patchJson('/api/v1/admin/settings', [
            'free_plans_per_day' => 7,
            'anthropic_model' => 'claude-test-model',
            'anthropic_api_key' => 'sk-ant-test-secret-key',
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.free_plans_per_day', 7)
            ->assertJsonPath('data.settings.anthropic_model', 'claude-test-model')
            ->assertJsonPath('data.settings.anthropic_model_source', 'admin')
            ->assertJsonPath('data.settings.anthropic_api_key_set', true)
            ->assertJsonPath('data.settings.anthropic_api_key_source', 'admin')
            ->assertJsonMissingPath('data.settings.anthropic_api_key');

        $this->assertSame(
            'sk-ant-test-secret-key',
            app(AppSettings::class)->anthropicApiKey(),
        );
        $this->assertSame('claude-test-model', app(AppSettings::class)->anthropicModel());

        $this->patchJson('/api/v1/admin/settings', [
            'clear_anthropic_api_key' => true,
            'clear_anthropic_model' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.anthropic_api_key_source', 'env')
            ->assertJsonPath('data.settings.anthropic_model_source', 'env');

        $this->getJson('/api/v1/admin/users?search=member')
            ->assertOk()
            ->assertJsonPath('data.users.0.email', 'member@plnr.test');

        $this->patchJson("/api/v1/admin/users/{$user->id}", [
            'role' => User::ROLE_ADMIN,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_non_admin_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/stats')->assertForbidden();
    }

    public function test_plan_limits_endpoint_returns_remaining(): void
    {
        app(AppSettings::class)->set(AppSettings::FREE_PLANS_PER_DAY, 5);

        $this->getJson('/api/v1/plan-limits')
            ->assertOk()
            ->assertJsonPath('data.limit', 5)
            ->assertJsonPath('data.remaining', 5)
            ->assertJsonPath('data.window', 'day');
    }
}
