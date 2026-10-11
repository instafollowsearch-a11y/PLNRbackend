<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\Sms\TwilioPlanInviteSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleAndTwilioSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_google_client_id_and_the_login_page_can_read_it(): void
    {
        config(['services.google.client_id' => 'env-client.apps.googleusercontent.com']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $clientId = '789738263381-dieq466kujjip9c4cnrn0um48j80slhn.apps.googleusercontent.com';

        $this->patchJson('/api/v1/admin/settings', [
            'google_client_id' => $clientId,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.google_client_id', $clientId)
            ->assertJsonPath('data.settings.google_client_id_source', 'admin');

        $this->assertSame($clientId, app(AppSettings::class)->googleClientId());

        auth()->forgetGuards();

        $this->getJson('/api/v1/google-sign-in')
            ->assertOk()
            ->assertJsonPath('data.client_id', $clientId);

        $this->patchJson('/api/v1/admin/settings', [
            'clear_google_client_id' => true,
        ])->assertUnauthorized();
    }

    public function test_clearing_the_google_client_id_falls_back_to_env(): void
    {
        config(['services.google.client_id' => 'env-client.apps.googleusercontent.com']);
        Sanctum::actingAs(User::factory()->admin()->create());

        app(AppSettings::class)->set(
            AppSettings::GOOGLE_CLIENT_ID,
            '789738263381-adminoverride.apps.googleusercontent.com',
        );

        $this->patchJson('/api/v1/admin/settings', [
            'clear_google_client_id' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.google_client_id', 'env-client.apps.googleusercontent.com')
            ->assertJsonPath('data.settings.google_client_id_source', 'env');
    }

    public function test_admin_can_save_ios_and_android_client_ids_for_the_phone_app(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $ios = '789738263381-iosclientidvalue000000000000.apps.googleusercontent.com';
        $android = '789738263381-androidclientidvalue00000000.apps.googleusercontent.com';

        $this->patchJson('/api/v1/admin/settings', [
            'google_ios_client_id' => $ios,
            'google_android_client_id' => $android,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.google_ios_client_id', $ios)
            ->assertJsonPath('data.settings.google_ios_client_id_source', 'admin')
            ->assertJsonPath('data.settings.google_android_client_id', $android)
            ->assertJsonPath('data.settings.google_android_client_id_source', 'admin');

        auth()->forgetGuards();

        $this->getJson('/api/v1/google-sign-in')
            ->assertOk()
            ->assertJsonPath('data.ios_client_id', $ios)
            ->assertJsonPath('data.android_client_id', $android);
    }

    public function test_a_places_key_is_rejected_as_the_google_client_id(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/settings', [
            'google_client_id' => 'AIzaSyBmNj4EjSyETxPMuE8ihKF3_VQH8WCZgxE',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.google_client_id.0', 'Enter the Web client ID ending in .apps.googleusercontent.com.');
    }

    public function test_admin_can_save_twilio_settings_without_returning_the_auth_token(): void
    {
        config([
            'services.twilio.account_sid' => null,
            'services.twilio.auth_token' => null,
            'services.twilio.from' => null,
            'services.twilio.messaging_service_sid' => null,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $token = 'twilio-auth-token-value';
        $sid = 'AC'.str_repeat('a', 32);
        $from = '+15551234567';
        $messaging = 'MG'.str_repeat('b', 32);

        $response = $this->patchJson('/api/v1/admin/settings', [
            'twilio_account_sid' => $sid,
            'twilio_auth_token' => $token,
            'twilio_from_number' => $from,
            'twilio_messaging_service_sid' => $messaging,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.twilio_account_sid', $sid)
            ->assertJsonPath('data.settings.twilio_account_sid_source', 'admin')
            ->assertJsonPath('data.settings.twilio_from_number', $from)
            ->assertJsonPath('data.settings.twilio_messaging_service_sid', $messaging)
            ->assertJsonPath('data.settings.twilio_auth_token_set', true)
            ->assertJsonPath('data.settings.twilio_auth_token_source', 'admin')
            ->assertJsonMissingPath('data.settings.twilio_auth_token');

        $hint = $response->json('data.settings.twilio_auth_token_hint');
        $this->assertIsString($hint);
        $this->assertNotSame($token, $hint);
        $this->assertSame($token, app(AppSettings::class)->twilioAuthToken());
        $this->assertTrue(app(TwilioPlanInviteSms::class)->configured());

        $this->getJson('/api/v1/google-sign-in')
            ->assertOk()
            ->assertJsonMissingPath('data.twilio_auth_token');
    }

    public function test_invalid_twilio_number_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/settings', [
            'twilio_from_number' => '5551234567',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.twilio_from_number.0', 'Enter the Twilio number with country code, such as +15551234567.');
    }
}
