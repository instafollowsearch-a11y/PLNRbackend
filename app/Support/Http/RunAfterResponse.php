<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAfterResponse
{
    public static function inline(): bool
    {
        return app()->runningUnitTests() && ! config('app.defer_http_work');
    }

    /**
     * Run the work after the HTTP response is sent, so it continues when the phone leaves the app.
     * Tests run the work immediately and return null so the caller can send the finished payload.
     *
     * @param  callable(): void  $work
     */
    public static function defer(callable $work): ?JsonResponse
    {
        if (self::inline()) {
            $work();

            return null;
        }

        ignore_user_abort(true);

        // Stay in this PHP request after the 202 is flushed. A queued job would
        // wait for a worker, and leaving the app would still cancel the plan.
        app()->terminating(static function () use ($work): void {
            ignore_user_abort(true);

            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }

            try {
                $work();
            } catch (Throwable $exception) {
                Log::error('http.background_work_failed', [
                    'message' => $exception->getMessage(),
                ]);
            }
        });

        return response()->json([
            'data' => [
                'status' => 'generating',
            ],
            'message' => 'Still working.',
        ], 202);
    }
}
