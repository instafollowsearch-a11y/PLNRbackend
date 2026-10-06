<?php

use App\Http\Controllers\Api\V1\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\V1\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingConfigController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BookingConfigController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\GeocodeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HotelSuggestionController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PlacePhotoController;
use App\Http\Controllers\Api\V1\PlanCardImageController;
use App\Http\Controllers\Api\V1\PlanLimitController;
use App\Http\Controllers\Api\V1\PlanSessionController;
use App\Http\Controllers\Api\V1\PlanShareController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\WeekendRecommendationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [HealthController::class, 'show']);
    Route::get('/booking-config', [BookingConfigController::class, 'show']);
    Route::get('/billing-config', [BillingConfigController::class, 'show']);
    Route::get('/plan-card-images', [PlanCardImageController::class, 'index']);
    Route::get('/plan-card-images/{planType}/file', [PlanCardImageController::class, 'file'])
        ->where('planType', 'date_night|night_out|vacation|road_trip');
    Route::get('/place-photos/{token}', [PlacePhotoController::class, 'show'])
        ->where('token', '[A-Za-z0-9]+');
    Route::middleware('throttle:geocode')->group(function (): void {
        Route::get('/geocode/search', [GeocodeController::class, 'search']);
        Route::get('/geocode/reverse', [GeocodeController::class, 'reverse']);
    });
    Route::post('/hotel-suggestions', [HotelSuggestionController::class, 'store'])
        ->middleware('throttle:ai');

    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

    Route::get('/plan-shares/{token}', [PlanShareController::class, 'show']);

    Route::prefix('auth')->group(function (): void {
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
            Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('/reset-password', [AuthController::class, 'resetPassword']);
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', [AuthController::class, 'user']);
        Route::middleware('throttle:auth')->group(function (): void {
            Route::patch('/user', [AuthController::class, 'update']);
            Route::post('/user/password', [AuthController::class, 'changePassword']);
            Route::delete('/user', [AuthController::class, 'destroy']);
        });
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/plan-sessions', [PlanSessionController::class, 'index']);
        Route::post('/plan-sessions/{planSession}/claim', [PlanSessionController::class, 'claim']);
        Route::post('/plan-shares/{token}/accept', [PlanShareController::class, 'accept']);
        Route::post('/billing/checkout-session', [BillingController::class, 'checkout'])
            ->middleware('throttle:booking');
        Route::post('/billing/portal-session', [BillingController::class, 'portal'])
            ->middleware('throttle:booking');
        Route::post('/billing/cancel-subscription', [BillingController::class, 'cancel'])
            ->middleware('throttle:booking');
        Route::post('/billing/play/subscription', [BillingController::class, 'play'])
            ->middleware('throttle:booking');

        Route::middleware('pro')->group(function (): void {
            Route::get('/weekend-recommendations', [WeekendRecommendationController::class, 'index']);
            Route::post('/weekend-recommendations', [WeekendRecommendationController::class, 'store'])
                ->middleware('throttle:ai');
            Route::get('/weekend-recommendations/{weekendRecommendation}', [WeekendRecommendationController::class, 'show']);
            Route::post('/weekend-recommendations/{weekendRecommendation}/send-email', [WeekendRecommendationController::class, 'sendEmail'])
                ->middleware('throttle:email');
            Route::post('/plan-sessions/{planSession}/shares', [PlanShareController::class, 'store'])
                ->middleware('throttle:email');
        });

        Route::middleware('throttle:booking')->group(function (): void {
            Route::post('/payment-methods/setup-intent', [PaymentMethodController::class, 'setupIntent']);
            Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
        });
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::patch('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'setDefault']);
        Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/push-tokens', [PushTokenController::class, 'store']);
        Route::delete('/push-tokens', [PushTokenController::class, 'destroy']);

        Route::middleware('admin')->prefix('admin')->group(function (): void {
            Route::get('/stats', [AdminStatsController::class, 'show']);
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::patch('/users/{user}', [AdminUserController::class, 'update']);
            Route::get('/settings', [AdminSettingsController::class, 'show']);
            Route::patch('/settings', [AdminSettingsController::class, 'update']);
            Route::post('/plan-card-images', [PlanCardImageController::class, 'store']);
            Route::delete('/plan-card-images/{planType}', [PlanCardImageController::class, 'destroy'])
                ->where('planType', 'date_night|night_out|vacation|road_trip');
        });
    });

    Route::middleware('auth.optional')->get('/plan-limits', [PlanLimitController::class, 'show']);

    Route::middleware('auth.optional')->post('/plan-sessions', [PlanSessionController::class, 'store']);

    // auth.optional so Bearer tokens resolve for owners/viewers; guests still access unclaimed sessions.
    Route::middleware(['auth.optional', 'plan.session'])->prefix('plan-sessions/{planSession}')->group(function (): void {
        Route::get('/', [PlanSessionController::class, 'show']);
        Route::middleware('throttle:ai')->group(function (): void {
            Route::post('/suggestions', [PlanSessionController::class, 'suggestions']);
            Route::post('/suggestions/{suggestion}/plan', [PlanSessionController::class, 'draftPlan']);
            Route::post('/refine', [PlanSessionController::class, 'refine']);
            Route::post('/itinerary', [PlanSessionController::class, 'itinerary']);
        });
        Route::post('/select', [PlanSessionController::class, 'select']);
        Route::post('/send-email', [PlanSessionController::class, 'sendEmail'])
            ->middleware('throttle:email');
    });

    Route::middleware(['auth:sanctum', 'plan.session', 'throttle:booking'])
        ->post('/plan-sessions/{planSession}/bookings', [BookingController::class, 'store']);
});
