<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_city(): void
    {
        $user = User::factory()->create(['city' => null]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'city' => 'Austin',
        ])->assertOk()
            ->assertJsonPath('data.user.city', 'Austin');

        $this->assertSame('Austin', $user->fresh()->city);
    }

    public function test_city_is_required_when_updating_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'city' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['city']);
    }

    public function test_get_user_includes_city(): void
    {
        $user = User::factory()->create(['city' => 'Barcelona']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.user.city', 'Barcelona');
    }
}
