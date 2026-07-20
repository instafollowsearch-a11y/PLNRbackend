<p>Hi {{ $booking->user->name }},</p>

<p>This is a reminder for your upcoming plan:</p>

<p><strong>{{ $booking->title }}</strong></p>
<p>Scheduled for: {{ $booking->scheduled_for?->format('M j, Y g:i A') }}</p>

<p>We hope you have a great time!</p>

<p>— PLNR</p>
