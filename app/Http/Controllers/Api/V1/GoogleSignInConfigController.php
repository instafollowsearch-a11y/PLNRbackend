<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Settings\AppSettings;
use Illuminate\Http\JsonResponse;

class GoogleSignInConfigController extends Controller
{
    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'client_id' => $settings->googleClientId(),
                'ios_client_id' => $settings->googleIosClientId(),
                'android_client_id' => $settings->googleAndroidClientId(),
            ],
            'message' => 'Google sign-in configuration retrieved.',
        ]);
    }
}
