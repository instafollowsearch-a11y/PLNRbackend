<p>A new booking needs fulfillment.</p>

<p><strong>Title:</strong> {{ $booking->title }}</p>
<p><strong>Booking UUID:</strong> {{ $booking->uuid }}</p>
<p><strong>User ID:</strong> {{ $booking->user_id }}</p>
<p><strong>Scheduled for:</strong> {{ $booking->scheduled_for?->format('M j, Y g:i A') }}</p>
<p><strong>Amount:</strong> {{ number_format($booking->amount_cents / 100, 2) }} {{ strtoupper($booking->currency) }}</p>

@php($metadata = $booking->metadata ?? [])
@if (! empty($metadata['venue_url']))
<p><strong>Venue URL:</strong> <a href="{{ $metadata['venue_url'] }}">{{ $metadata['venue_url'] }}</a></p>
@endif
@if (! empty($metadata['external_url']))
<p><strong>External URL:</strong> <a href="{{ $metadata['external_url'] }}">{{ $metadata['external_url'] }}</a></p>
@endif
