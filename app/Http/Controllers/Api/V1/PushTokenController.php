<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeletePushTokenRequest;
use App\Http\Requests\Api\V1\StorePushTokenRequest;
use App\Models\DevicePushToken;
use Illuminate\Http\JsonResponse;

class PushTokenController extends Controller
{
    public function store(StorePushTokenRequest $request): JsonResponse
    {
        $token = DevicePushToken::query()->updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->string('platform')->toString(),
            ],
        );

        return response()->json([
            'data' => [
                'push_token' => [
                    'id' => $token->id,
                    'platform' => $token->platform,
                ],
            ],
            'message' => 'Push token saved.',
        ], 201);
    }

    public function destroy(DeletePushTokenRequest $request): JsonResponse
    {
        DevicePushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $request->string('token')->toString())
            ->delete();

        return response()->json([
            'message' => 'Push token removed.',
        ]);
    }
}
