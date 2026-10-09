@component('mail::message')
# {{ $booking->title }}

A new booking needs fulfillment.

**Booking:** {{ $booking->uuid }}

**User ID:** {{ $booking->user_id }}

**Scheduled for:** {{ $booking->scheduled_for?->format('M j, Y g:i A') }}

**Amount:** {{ number_format($booking->amount_cents / 100, 2) }} {{ strtoupper($booking->currency) }}

@php($metadata = $booking->metadata ?? [])
@if (! empty($metadata['venue_url']))
**Venue:** [{{ $metadata['venue_url'] }}]({{ $metadata['venue_url'] }})
@endif
@if (! empty($metadata['external_url']))
**External:** [{{ $metadata['external_url'] }}]({{ $metadata['external_url'] }})
@endif

Sent to the PLNR booking desk.
@endcomponent
