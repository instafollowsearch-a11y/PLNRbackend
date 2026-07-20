<?php

namespace App\Services\Notifications;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    private const SEND_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        $tokens = $user->devicePushTokens()->pluck('token')->all();

        if ($tokens === []) {
            return false;
        }

        $messages = array_map(
            fn (string $token) => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
            ],
            $tokens,
        );

        $response = Http::acceptJson()
            ->post(self::SEND_URL, $messages);

        if (! $response->successful()) {
            Log::warning('expo.push_request_failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return false;
        }

        $items = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        $sent = false;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = (string) ($item['status'] ?? '');

            if ($status === 'ok') {
                $sent = true;

                continue;
            }

            $details = is_array($item['details'] ?? null) ? $item['details'] : [];
            $error = (string) ($details['error'] ?? $item['message'] ?? '');

            if ($error === 'DeviceNotRegistered' && isset($tokens[$index])) {
                DevicePushToken::query()->where('token', $tokens[$index])->delete();
            }

            Log::warning('expo.push_delivery_failed', [
                'user_id' => $user->id,
                'token' => $tokens[$index] ?? null,
                'error' => $error,
            ]);
        }

        return $sent;
    }
}
