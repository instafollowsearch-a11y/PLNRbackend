<?php

namespace App\Services\Bookings;

use App\Mail\BookingOpsNotificationMail;
use App\Models\Booking;
use Illuminate\Support\Facades\Mail;

class BookingFulfillmentService
{
    /**
     * @param  array<string, mixed>  $content
     * @return array{venue_url: ?string, external_url: ?string}
     */
    public function extractUrls(array $content, ?array $suggestionPayload = null): array
    {
        $venueUrl = null;
        $externalUrl = null;

        foreach ($this->collectStops($content) as $stop) {
            if ($venueUrl === null) {
                $venueUrl = $this->firstUrl($stop, ['venue_url', 'url', 'booking_url']);
            }

            if ($externalUrl === null) {
                $externalUrl = $this->firstUrl($stop, ['external_url', 'link', 'website']);
            }
        }

        if ($suggestionPayload !== null) {
            $venueUrl ??= $this->firstUrl($suggestionPayload, ['venue_url', 'url', 'booking_url']);
            $externalUrl ??= $this->firstUrl($suggestionPayload, ['external_url', 'link', 'website']);
        }

        return [
            'venue_url' => $venueUrl,
            'external_url' => $externalUrl,
        ];
    }

    public function notifyOps(Booking $booking): void
    {
        $email = (string) config('services.booking.ops_email');

        if ($email === '') {
            return;
        }

        Mail::to($email)->send(new BookingOpsNotificationMail($booking));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function collectStops(array $content): array
    {
        $stops = [];

        if (isset($content['stops']) && is_array($content['stops'])) {
            foreach ($content['stops'] as $stop) {
                if (is_array($stop)) {
                    $stops[] = $stop;
                }
            }
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $day) {
                if (! is_array($day)) {
                    continue;
                }

                foreach ($day['stops'] ?? [] as $stop) {
                    if (is_array($stop)) {
                        $stops[] = $stop;
                    }
                }
            }
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     */
    private function firstUrl(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($item[$key]) && is_string($item[$key])) {
                return $item[$key];
            }
        }

        return null;
    }
}
