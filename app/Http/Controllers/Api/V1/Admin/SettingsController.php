<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Services\Settings\AppSettings;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'settings' => $settings->forAdmin(),
            ],
            'message' => 'Settings retrieved.',
        ]);
    }

    public function update(UpdateSettingsRequest $request, AppSettings $settings): JsonResponse
    {
        if ($request->exists('free_plans_per_day')) {
            $settings->set(AppSettings::FREE_PLANS_PER_DAY, $request->integer('free_plans_per_day'));
        }

        $this->applyOptionalString($request, $settings, 'anthropic_api_key', AppSettings::ANTHROPIC_API_KEY, 'clear_anthropic_api_key');
        $this->applyOptionalString($request, $settings, 'anthropic_model', AppSettings::ANTHROPIC_MODEL, 'clear_anthropic_model');
        $this->applyOptionalString($request, $settings, 'anthropic_url', AppSettings::ANTHROPIC_URL, 'clear_anthropic_url');
        $this->applyOptionalString($request, $settings, 'mail_from_address', AppSettings::MAIL_FROM_ADDRESS, 'clear_mail_from_address');
        $this->applyOptionalString($request, $settings, 'mail_from_name', AppSettings::MAIL_FROM_NAME, 'clear_mail_from_name');
        $this->applyOptionalString($request, $settings, 'booking_ops_email', AppSettings::BOOKING_OPS_EMAIL, 'clear_booking_ops_email');

        if ($request->boolean('clear_rate_limit_ai_per_hour')) {
            $settings->forget(AppSettings::RATE_LIMIT_AI_PER_HOUR);
        } elseif ($request->exists('rate_limit_ai_per_hour') && $request->filled('rate_limit_ai_per_hour')) {
            $settings->set(AppSettings::RATE_LIMIT_AI_PER_HOUR, $request->integer('rate_limit_ai_per_hour'));
        }

        if ($request->exists('pro_monthly_price_cents')) {
            $settings->set(AppSettings::PRO_MONTHLY_PRICE_CENTS, $request->integer('pro_monthly_price_cents'));
        }

        $this->applyOptionalString($request, $settings, 'pro_currency', AppSettings::PRO_CURRENCY, 'clear_pro_currency');
        $this->applyOptionalString($request, $settings, 'app_store_url', AppSettings::APP_STORE_URL, 'clear_app_store_url');
        $this->applyOptionalString($request, $settings, 'play_store_url', AppSettings::PLAY_STORE_URL, 'clear_play_store_url');
        $this->applyOptionalString($request, $settings, 'web_app_url', AppSettings::WEB_APP_URL, 'clear_web_app_url');
        $this->applyOptionalString($request, $settings, 'stripe_secret', AppSettings::STRIPE_SECRET, 'clear_stripe_secret');
        $this->applyOptionalString($request, $settings, 'stripe_publishable_key', AppSettings::STRIPE_PUBLISHABLE_KEY, 'clear_stripe_publishable_key');
        $this->applyOptionalString($request, $settings, 'stripe_webhook_secret', AppSettings::STRIPE_WEBHOOK_SECRET, 'clear_stripe_webhook_secret');

        if ($request->boolean('clear_stripe_fake')) {
            $settings->forget(AppSettings::STRIPE_FAKE);
        } elseif ($request->exists('stripe_fake')) {
            $settings->set(AppSettings::STRIPE_FAKE, $request->boolean('stripe_fake'));
        }

        return response()->json([
            'data' => [
                'settings' => $settings->forAdmin(),
            ],
            'message' => 'Settings updated.',
        ]);
    }

    private function applyOptionalString(
        UpdateSettingsRequest $request,
        AppSettings $settings,
        string $input,
        string $key,
        string $clearFlag,
    ): void {
        if ($request->boolean($clearFlag)) {
            $settings->forget($key);

            return;
        }

        if (! $request->exists($input)) {
            return;
        }

        $value = $request->input($input);

        if ($value === null || $value === '') {
            return;
        }

        $settings->set($key, (string) $value);
    }
}
