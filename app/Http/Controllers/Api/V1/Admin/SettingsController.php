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
