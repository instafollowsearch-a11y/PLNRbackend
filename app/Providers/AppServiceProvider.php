<?php

namespace App\Providers;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Services\AI\AiChatClient;
use App\Services\AI\AnthropicClient;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\VerifiesGoogleIdToken;
use App\Services\Billing\AppleSubscriptionVerifier;
use App\Services\Billing\AppStoreSubscriptionVerifier;
use App\Services\Billing\GooglePlaySubscriptionVerifier;
use App\Services\Billing\PlaySubscriptionVerifier;
use App\Services\Bookings\BookingFulfillmentService;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\ItineraryScheduleParser;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Reminders\ScheduleItineraryStopReminders;
use App\Services\Settings\AppSettings;
use App\Services\Sms\SendsPlanInviteSms;
use App\Services\Sms\TwilioPlanInviteSms;
use App\Support\Auth\PasswordResetUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiChatClient::class, AnthropicClient::class);
        $this->app->singleton(SendsPlanInviteSms::class, TwilioPlanInviteSms::class);
        $this->app->singleton(VerifiesGoogleIdToken::class, GoogleIdTokenVerifier::class);
        $this->app->singleton(PlaySubscriptionVerifier::class, GooglePlaySubscriptionVerifier::class);
        $this->app->singleton(AppleSubscriptionVerifier::class, AppStoreSubscriptionVerifier::class);

        $this->app->singleton(PaymentGateway::class, function ($app) {
            return $app->make(PaymentGatewayResolver::class)->resolve();
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        View::addNamespace('mail', [
            resource_path('views/vendor/mail/html'),
            base_path('vendor/laravel/framework/src/Illuminate/Mail/resources/views/html'),
        ]);

        View::composer('mail.*', function ($view): void {
            $data = $view->getData();
            if (isset($data['message']) && is_object($data['message']) && method_exists($data['message'], 'embed')) {
                View::share('plnrMailMessage', $data['message']);
            }
        });

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => PasswordResetUrl::for($user, $token));

        ResetPassword::toMailUsing(function (User $user, string $token): ResetPasswordMail {
            return new ResetPasswordMail(
                $user->getEmailForPasswordReset(),
                PasswordResetUrl::for($user, $token),
            );
        });

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
                : (string) ($request->route('planSession') ?? $request->user()?->id ?? 'guest');

            $aiPerHour = app(AppSettings::class)->rateLimitAiPerHour();

            return Limit::perHour($aiPerHour)
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

        RateLimiter::for('geocode', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('visits', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
