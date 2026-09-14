<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->alias([
            'plan.session' => \App\Http\Middleware\EnsurePlanSessionAccess::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'auth.optional' => \App\Http\Middleware\OptionalSanctumAuth::class,
            'pro' => \App\Http\Middleware\EnsureUserIsPro::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/v1/stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Too many requests.'], 429);
            }
        });
    })->create();
