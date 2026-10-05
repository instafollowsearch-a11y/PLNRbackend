<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FindLocalSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_and_clear_the_findlocal_key_without_returning_it(): void
    {
        config(['services.events.findlocal.api_key' => 'env-findlocal-key']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $secret = 'admin-findlocal-secret';

        $response = $this->patchJson('/api/v1/admin/settings', [
            'findlocal_api_key' => $secret,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.findlocal_api_key_set', true)
            ->assertJsonPath('data.settings.findlocal_api_key_source', 'admin')
            ->assertJsonMissingPath('data.settings.findlocal_api_key');

        $hint = $response->json('data.settings.findlocal_api_key_hint');
        $this->assertIsString($hint);
        $this->assertNotSame($secret, $hint);
        $this->assertStringStartsWith('admi', $hint);
        $this->assertStringEndsWith('cret', $hint);
        $this->assertSame($secret, app(AppSettings::class)->findLocalApiKey());

        $this->patchJson('/api/v1/admin/settings', [
            'clear_findlocal_api_key' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.findlocal_api_key_source', 'env')
            ->assertJsonPath('data.settings.findlocal_api_key_set', true);

        $this->assertSame('env-findlocal-key', app(AppSettings::class)->findLocalApiKey());
    }
}
