<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Settings\AppSettings;
use Illuminate\Http\JsonResponse;

class BillingConfigController extends Controller
{
    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'pro_monthly_price_cents' => $settings->proMonthlyPriceCents(),
                'pro_currency' => $settings->proCurrency(),
                'app_store_url' => $settings->appStoreUrl(),
                'play_store_url' => $settings->playStoreUrl(),
                'web_app_url' => $settings->webAppUrl(),
                'stripe_fake' => $settings->stripeFake(),
                'stripe_configured' => $settings->stripeSecret() !== null && $settings->stripeSecret() !== '',
            ],
            'message' => 'Billing configuration retrieved.',
        ]);
    }
}
