<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'free_plans_per_month' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'anthropic_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'google_places_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'google_client_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]{10,}\.apps\.googleusercontent\.com$/'],
            'google_ios_client_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]{10,}\.apps\.googleusercontent\.com$/'],
            'google_android_client_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]{10,}\.apps\.googleusercontent\.com$/'],
            'twilio_account_sid' => ['sometimes', 'nullable', 'string', 'regex:/^AC[0-9a-fA-F]{32}$/'],
            'twilio_auth_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'twilio_from_number' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'twilio_messaging_service_sid' => ['sometimes', 'nullable', 'string', 'regex:/^MG[0-9a-fA-F]{32}$/'],
            'findlocal_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'anthropic_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'anthropic_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_from_address' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'booking_ops_email' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
            'rate_limit_ai_per_hour' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'pro_monthly_price_cents' => ['sometimes', 'integer', 'min:100', 'max:100000'],
            'pro_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'app_store_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url'],
            'play_store_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url'],
            'web_app_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url'],
            'stripe_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_publishable_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_fake' => ['sometimes', 'boolean'],
            'clear_anthropic_api_key' => ['sometimes', 'boolean'],
            'clear_google_places_api_key' => ['sometimes', 'boolean'],
            'clear_google_client_id' => ['sometimes', 'boolean'],
            'clear_google_ios_client_id' => ['sometimes', 'boolean'],
            'clear_google_android_client_id' => ['sometimes', 'boolean'],
            'clear_twilio_account_sid' => ['sometimes', 'boolean'],
            'clear_twilio_auth_token' => ['sometimes', 'boolean'],
            'clear_twilio_from_number' => ['sometimes', 'boolean'],
            'clear_twilio_messaging_service_sid' => ['sometimes', 'boolean'],
            'clear_findlocal_api_key' => ['sometimes', 'boolean'],
            'clear_anthropic_model' => ['sometimes', 'boolean'],
            'clear_anthropic_url' => ['sometimes', 'boolean'],
            'clear_mail_from_address' => ['sometimes', 'boolean'],
            'clear_mail_from_name' => ['sometimes', 'boolean'],
            'clear_booking_ops_email' => ['sometimes', 'boolean'],
            'clear_rate_limit_ai_per_hour' => ['sometimes', 'boolean'],
            'clear_pro_currency' => ['sometimes', 'boolean'],
            'clear_app_store_url' => ['sometimes', 'boolean'],
            'clear_play_store_url' => ['sometimes', 'boolean'],
            'clear_web_app_url' => ['sometimes', 'boolean'],
            'clear_stripe_secret' => ['sometimes', 'boolean'],
            'clear_stripe_publishable_key' => ['sometimes', 'boolean'],
            'clear_stripe_webhook_secret' => ['sometimes', 'boolean'],
            'clear_stripe_fake' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_client_id.regex' => 'Enter the Web client ID ending in .apps.googleusercontent.com.',
            'google_ios_client_id.regex' => 'Enter the iOS client ID ending in .apps.googleusercontent.com.',
            'google_android_client_id.regex' => 'Enter the Android client ID ending in .apps.googleusercontent.com.',
            'twilio_account_sid.regex' => 'Enter the Twilio Account SID. It starts with AC.',
            'twilio_from_number.regex' => 'Enter the Twilio number with country code, such as +15551234567.',
            'twilio_messaging_service_sid.regex' => 'Enter the Messaging Service SID. It starts with MG.',
        ];
    }
}
