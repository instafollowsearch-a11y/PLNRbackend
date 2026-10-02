<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_user_can_update_name(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'name' => 'Grace Hopper',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Grace Hopper');

        $this->assertSame('Grace Hopper', $user->fresh()->name);
    }

    public function test_user_can_update_email_with_current_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'email' => 'grace@example.com',
            'current_password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'grace@example.com');

        $this->assertSame('grace@example.com', $user->fresh()->email);
    }

    public function test_email_update_is_rejected_without_current_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $fresh = $user->fresh();
        $this->assertSame('ada@example.com', $fresh->email);
        $this->assertSame('Ada Lovelace', $fresh->name);
    }

    public function test_email_update_is_rejected_when_current_password_is_wrong(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'email' => 'grace@example.com',
            'current_password' => 'not-the-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertSame('ada@example.com', $user->fresh()->email);
    }

    public function test_email_update_is_rejected_when_email_is_taken(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'email' => 'taken@example.com',
            'current_password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame('ada@example.com', $user->fresh()->email);
    }

    public function test_same_email_does_not_require_current_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/user', [
            'name' => 'Ada King',
            'email' => 'ada@example.com',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Ada King')
            ->assertJsonPath('data.user.email', 'ada@example.com');
    }

    public function test_user_can_change_password_and_other_tokens_are_revoked(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)
            ->postJson('/api/v1/user/password', [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertOk()
            ->assertJsonPath('message', 'Password updated.')
            ->assertJsonMissingPath('data.password');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('new-password-1', $fresh->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)
            ->getJson('/api/v1/user')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)
            ->getJson('/api/v1/user')
            ->assertUnauthorized();
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_rejects_reusing_the_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/password', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_profile_and_password_routes_require_authentication(): void
    {
        $this->patchJson('/api/v1/user', [
            'name' => 'Grace Hopper',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/user/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertUnauthorized();
    }
}
