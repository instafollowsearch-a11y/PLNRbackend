<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\PlanSession;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Reminders\ScheduleItineraryStopReminders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BookingService
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly ItineraryScheduleParser $scheduleParser,
        private readonly BookingFulfillmentService $fulfillmentService,
        private readonly ScheduleItineraryStopReminders $scheduleStopReminders,
    ) {}

    public function createForSession(User $user, PlanSession $planSession): Booking
    {
        $planSession->loadMissing(['itinerary', 'planType', 'booking', 'suggestions']);

        if ($planSession->itinerary === null || $planSession->itinerary->email_sent_at === null) {
            throw ValidationException::withMessages([
                'plan_session' => ['Send your itinerary by email before booking.'],
            ]);
        }

        $existing = $planSession->booking;

        if ($existing !== null && $existing->status !== Booking::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'plan_session' => ['This plan is already booked.'],
            ]);
        }

        $paymentMethod = $user->defaultPaymentMethod;

        if ($paymentMethod === null) {
            throw ValidationException::withMessages([
                'payment_method' => ['Add a payment method before booking.'],
            ]);
        }

        if ($planSession->user_id === null) {
            $planSession->update(['user_id' => $user->id]);
        } elseif ($planSession->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'plan_session' => ['You cannot book this plan session.'],
            ]);
        }

        $content = $planSession->itinerary->content ?? [];
        $amountCents = (int) config('services.booking.fee_cents', 999);
        $currency = (string) config('services.booking.currency', 'usd');
        $selectedSuggestion = $planSession->selectedSuggestion();
        $urls = $this->fulfillmentService->extractUrls(
            $content,
            $selectedSuggestion?->payload,
        );
        $metadata = array_filter([
            'plan_type' => $planSession->planType?->slug,
            'city' => $planSession->city,
            'venue_url' => $urls['venue_url'],
            'external_url' => $urls['external_url'],
        ], fn ($value) => $value !== null && $value !== '');

        return DB::transaction(function () use (
            $user,
            $planSession,
            $existing,
            $content,
            $amountCents,
            $currency,
            $paymentMethod,
            $metadata,
        ) {
            $bookingAttributes = [
                'user_id' => $user->id,
                'title' => (string) ($content['title'] ?? 'Your plan'),
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'status' => Booking::STATUS_PENDING,
                'fulfillment_status' => Booking::FULFILLMENT_PENDING_MANUAL,
                'scheduled_for' => $this->scheduleParser->firstStopTime($content, $planSession),
                'metadata' => $metadata,
            ];

            if ($existing !== null && $existing->status === Booking::STATUS_FAILED) {
                $booking = $existing;
                $booking->update([
                    ...$bookingAttributes,
                    'stripe_payment_intent_id' => null,
                    'reminder_sent_at' => null,
                    'push_reminder_sent_at' => null,
                ]);
            } else {
                $booking = Booking::query()->create([
                    ...$bookingAttributes,
                    'plan_session_id' => $planSession->id,
                ]);
            }

            $customerId = $this->paymentGateway->ensureCustomer($user);

            try {
                $charge = $this->paymentGateway->charge(
                    $customerId,
                    $paymentMethod->stripe_payment_method_id,
                    $amountCents,
                    $currency,
                    [
                        'booking_uuid' => $booking->uuid,
                        'plan_session_uuid' => $planSession->uuid,
                    ],
                );
            } catch (RuntimeException $exception) {
                Log::error('booking.charge_failed', [
                    'booking_uuid' => $booking->uuid,
                    'user_id' => $user->id,
                    'error' => $exception->getMessage(),
                ]);
                $booking->update([
                    'status' => Booking::STATUS_FAILED,
                    'fulfillment_status' => Booking::FULFILLMENT_FAILED,
                ]);

                throw ValidationException::withMessages([
                    'payment' => ['Payment failed. Please try another card.'],
                ]);
            }

            $status = $charge['status'] === 'succeeded'
                ? Booking::STATUS_CONFIRMED
                : Booking::STATUS_FAILED;

            $booking->update([
                'status' => $status,
                'stripe_payment_intent_id' => $charge['payment_intent_id'],
                'fulfillment_status' => $status === Booking::STATUS_CONFIRMED
                    ? Booking::FULFILLMENT_PENDING_MANUAL
                    : Booking::FULFILLMENT_FAILED,
            ]);

            if ($status === Booking::STATUS_FAILED) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment failed. Please try another card.'],
                ]);
            }

            $booking = $booking->fresh(['planSession.itinerary', 'planSession.planType', 'user']);
            $this->fulfillmentService->notifyOps($booking);

            if ($booking->planSession?->itinerary !== null && $user->email) {
                $this->scheduleStopReminders->forBooking(
                    $booking->planSession,
                    $booking->planSession->itinerary,
                    $booking,
                    $user->email,
                );
            }

            return $booking;
        });
    }
}
