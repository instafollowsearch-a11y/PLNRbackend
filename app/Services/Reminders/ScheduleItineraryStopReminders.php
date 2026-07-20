<?php

namespace App\Services\Reminders;

use App\Models\Booking;
use App\Models\Itinerary;
use App\Models\ItineraryStopReminder;
use App\Models\PlanSession;
use App\Services\Bookings\ItineraryScheduleParser;
use Illuminate\Support\Facades\Log;

class ScheduleItineraryStopReminders
{
    public function __construct(
        private readonly ItineraryScheduleParser $scheduleParser,
    ) {}

    public function forGuestSend(PlanSession $planSession, Itinerary $itinerary, string $recipientEmail): void
    {
        $this->schedule($planSession, $itinerary, $recipientEmail, null);
    }

    public function forBooking(PlanSession $planSession, Itinerary $itinerary, Booking $booking, string $recipientEmail): void
    {
        $this->schedule($planSession, $itinerary, $recipientEmail, $booking->id);
    }

    private function schedule(
        PlanSession $planSession,
        Itinerary $itinerary,
        string $recipientEmail,
        ?int $bookingId,
    ): void {
        $planSession->loadMissing('planType');

        ItineraryStopReminder::query()
            ->where('itinerary_id', $itinerary->id)
            ->whereNull('email_sent_at')
            ->delete();

        $content = $itinerary->content ?? [];
        $stops = $this->scheduleParser->allStops($content, $planSession);
        $slug = $planSession->planType?->slug;

        foreach ($stops as $stop) {
            $scheduledAt = $stop['at'];

            if ($scheduledAt->isPast()) {
                continue;
            }

            $alreadySent = ItineraryStopReminder::query()
                ->where('itinerary_id', $itinerary->id)
                ->where('stop_index', $stop['stop_index'])
                ->when(
                    $stop['day_index'] === null,
                    fn ($query) => $query->whereNull('day_index'),
                    fn ($query) => $query->where('day_index', $stop['day_index']),
                )
                ->whereNotNull('email_sent_at')
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $remindAt = $scheduledAt->copy()->subMinutes(30);

            if ($remindAt->isPast()) {
                $remindAt = now();
            }

            try {
                ItineraryStopReminder::query()->create([
                    'plan_session_id' => $planSession->id,
                    'itinerary_id' => $itinerary->id,
                    'booking_id' => $bookingId,
                    'stop_index' => $stop['stop_index'],
                    'day_index' => $stop['day_index'],
                    'stop_name' => $stop['name'],
                    'activity' => $stop['activity'],
                    'notes' => $stop['notes'],
                    'scheduled_at' => $scheduledAt,
                    'remind_at' => $remindAt,
                    'recipient_email' => $recipientEmail,
                    'plan_type_slug' => $slug,
                    'email_sent_at' => null,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('itinerary_stop_reminder.schedule_failed', [
                    'plan_session_uuid' => $planSession->uuid,
                    'stop_name' => $stop['name'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
