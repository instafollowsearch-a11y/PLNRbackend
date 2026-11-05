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

        self::afterFlush($work);

        return response()->json([
            'data' => [
                'status' => 'generating',
            ],
            'message' => 'Still working.',
        ], 202);
    }

    /**
     * Keep the current JSON status (e.g. 201 create) and run work after the client has the body.
     *
     * @param  callable(): void  $work
     */
    public static function afterFlush(callable $work): void
    {
        if (self::inline()) {
            return;
        }

        ignore_user_abort(true);

        app()->terminating(static function () use ($work): void {
            ignore_user_abort(true);

            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }

            self::releaseClient();

            try {
                $work();
            } catch (Throwable $exception) {
                Log::error('http.background_work_failed', [
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private static function releaseClient(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();

            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();
    }
}
