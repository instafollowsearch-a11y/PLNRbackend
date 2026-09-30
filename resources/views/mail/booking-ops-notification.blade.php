@component('mail.layouts.plnr', [
    'theme' => $theme,
    'title' => 'New booking',
    'heroEyebrow' => 'Booking desk',
    'heroTitle' => $booking->title,
    'closing' => 'Sent to the PLNR booking desk.',
])
    <p style="margin:0 0 16px 0;color:{{ $theme['muted'] }};font-size:15px;">
        A new booking needs fulfillment.
    </p>

    <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['text'] }};"><strong>Booking:</strong> {{ $booking->uuid }}</p>
    <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['text'] }};"><strong>User ID:</strong> {{ $booking->user_id }}</p>
    <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['text'] }};"><strong>Scheduled for:</strong> {{ $booking->scheduled_for?->format('M j, Y g:i A') }}</p>
    <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['text'] }};"><strong>Amount:</strong> {{ number_format($booking->amount_cents / 100, 2) }} {{ strtoupper($booking->currency) }}</p>

    @php($metadata = $booking->metadata ?? [])
    @if (! empty($metadata['venue_url']))
        <p style="margin:0 0 8px 0;font-size:14px;color:{{ $theme['text'] }};">
            <strong>Venue:</strong>
            <a href="{{ $metadata['venue_url'] }}" style="color:{{ $theme['accent'] }};">{{ $metadata['venue_url'] }}</a>
        </p>
    @endif
    @if (! empty($metadata['external_url']))
        <p style="margin:0;font-size:14px;color:{{ $theme['text'] }};">
            <strong>External:</strong>
            <a href="{{ $metadata['external_url'] }}" style="color:{{ $theme['accent'] }};">{{ $metadata['external_url'] }}</a>
        </p>
    @endif
@endcomponent
