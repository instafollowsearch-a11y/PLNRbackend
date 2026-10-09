@component('mail::message')
{{ $theme['label'] }}

# {{ $booking->title }}

Hi {{ $booking->user->name }},

This is a reminder for your upcoming plan.

<x-mail::panel>
**Scheduled for**

{{ $booking->scheduled_for?->format('D, M j, Y · g:i A') }}
</x-mail::panel>

Enjoy your plans.
@endcomponent
