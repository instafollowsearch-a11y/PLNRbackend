<?php

namespace Tests\Feature\Api\V1;

use App\Models\PageVisit;
use App\Models\PlanSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_their_account_with_the_right_password(): void
    {
        $user = User::factory()->create([
            'email' => 'delete-me@plnr.test',
            'password' => 'password123',
        ]);
        $owned = PlanSession::factory()->create(['user_id' => $user->id]);
        $other = User::factory()->create();
        $kept = PlanSession::factory()->create(['user_id' => $other->id]);
        PageVisit::query()->create([
            'user_id' => $user->id,
            'occurred_at' => now(),
            'path' => '/plans',
            'plan' => PageVisit::PLAN_FREE,
        ]);
        PageVisit::query()->create([
            'user_id' => null,
            'occurred_at' => now(),
            'path' => '/',
            'plan' => PageVisit::PLAN_GUEST,
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/user', [
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Account deleted.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('plan_sessions', ['id' => $owned->id]);
        $this->assertDatabaseHas('plan_sessions', ['id' => $kept->id]);
        $this->assertDatabaseMissing('page_visits', ['user_id' => $user->id]);
        $this->assertDatabaseHas('page_visits', [
            'user_id' => null,
            'path' => '/',
            'plan' => PageVisit::PLAN_GUEST,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'delete-me@plnr.test',
            'password' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_wrong_password_does_not_delete_the_account(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/user', [
            'password' => 'not-the-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_last_admin_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => 'password123',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/user', [
            'password' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
