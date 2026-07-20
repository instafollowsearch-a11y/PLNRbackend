<?php

namespace App\Providers;

use App\Services\AI\AiChatClient;
use App\Services\AI\AnthropicClient;
use App\Services\Bookings\BookingFulfillmentService;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\ItineraryScheduleParser;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Reminders\ScheduleItineraryStopReminders;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiChatClient::class, AnthropicClient::class);

        $this->app->singleton(PaymentGateway::class, function () {
            return (new PaymentGatewayResolver)->resolve();
        });

        $this->app->singleton(BookingService::class, function ($app) {
            return new BookingService(
                $app->make(PaymentGateway::class),
                new ItineraryScheduleParser,
                new BookingFulfillmentService,
                $app->make(ScheduleItineraryStopReminders::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute((int) config('rate_limiting.api_per_minute'))
                ->by($request->ip());
        });

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute((int) config('rate_limiting.auth_per_minute'))
                ->by($request->ip());
        });

        RateLimiter::for('ai', function (Request $request): Limit {
            $session = $request->route('planSession');
            $sessionKey = is_object($session) && isset($session->uuid)
                ? $session->uuid
                : (string) $request->route('planSession');

            return Limit::perHour((int) config('rate_limiting.ai_per_hour'))
                ->by($request->ip().':'.$sessionKey);
        });

        RateLimiter::for('email', function (Request $request): Limit {
            $session = $request->route('planSession');
            $sessionKey = is_object($session) && isset($session->uuid)
                ? $session->uuid
                : (string) $request->route('planSession');

            return Limit::perHour((int) config('rate_limiting.email_per_hour'))
                ->by($request->ip().':'.$sessionKey);
        });

        RateLimiter::for('booking', function (Request $request): Limit {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perHour((int) config('rate_limiting.booking_per_hour'))
                ->by((string) $key);
        });
    }
}
